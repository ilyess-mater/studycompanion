<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Lesson;
use App\Entity\StudentProfile;
use App\Entity\TeacherComment;
use App\Enum\ProcessingStatus;
use App\Enum\ThirdPartyProvider;
use App\Message\AnalyzeLessonMessage;
use App\Message\GenerateQuizMessage;
use App\Form\JoinGroupType;
use App\Form\LessonUploadType;
use App\Service\EntityThirdPartyLinkRecorder;
use App\Service\PerspectiveModerationService;
use App\Service\ThirdPartyMetaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[IsGranted('ROLE_STUDENT')]
#[Route('/student')]
class StudentController extends AbstractController
{
    #[Route('/dashboard', name: 'student_dashboard')]
    public function dashboard(EntityManagerInterface $entityManager): Response
    {
        $student = $this->currentStudentProfile();
        if ($student === null) {
            throw $this->createAccessDeniedException('Student profile is missing.');
        }

        $group = $student->getGroup();
        $teacherName = $group?->getTeacher()?->getUser()?->getName();
        $groupName = $group?->getName();

        $recentLessons = $entityManager->getRepository(Lesson::class)->findBy(
            ['uploadedBy' => $student],
            ['createdAt' => 'DESC'],
            6,
        );

        $allLessons = $entityManager->getRepository(Lesson::class)->findBy(
            ['uploadedBy' => $student],
            ['createdAt' => 'DESC'],
        );

        $recentReports = $entityManager->getRepository('App\\Entity\\PerformanceReport')->findBy(
            ['student' => $student],
            ['createdAt' => 'DESC'],
            6,
        );

        $allReports = $entityManager->getRepository('App\\Entity\\PerformanceReport')->findBy(
            ['student' => $student],
            ['createdAt' => 'DESC'],
        );

        $doneLessons = 0;
        $pendingLessons = 0;
        $failedLessons = 0;
        $totalEstimatedMinutes = 0;
        $subjectProgress = [];

        foreach ($allLessons as $lesson) {
            $status = $lesson->getProcessingStatus();
            if ($status === ProcessingStatus::Done || $status === ProcessingStatus::PartialFallback) {
                ++$doneLessons;
            } elseif ($status === ProcessingStatus::Failed) {
                ++$failedLessons;
            } else {
                ++$pendingLessons;
            }

            $totalEstimatedMinutes += max(0, (int) ($lesson->getEstimatedStudyMinutes() ?? 0));

            $subject = trim($lesson->getSubject()) !== '' ? $lesson->getSubject() : 'General';
            if (!isset($subjectProgress[$subject])) {
                $subjectProgress[$subject] = ['subject' => $subject, 'total' => 0, 'done' => 0];
            }
            ++$subjectProgress[$subject]['total'];
            if ($status === ProcessingStatus::Done || $status === ProcessingStatus::PartialFallback) {
                ++$subjectProgress[$subject]['done'];
            }
        }

        $averageScore = 0.0;
        $masteredCount = 0;
        $needsReviewCount = 0;
        $notMasteredCount = 0;
        $weakTopicFrequency = [];

        foreach ($allReports as $report) {
            $averageScore += $report->getQuizScore();
            $status = $report->getMasteryStatus()->value;
            if ($status === 'MASTERED') {
                ++$masteredCount;
            } elseif ($status === 'NEEDS_REVIEW') {
                ++$needsReviewCount;
            } else {
                ++$notMasteredCount;
            }

            foreach ($report->getWeakTopics() as $weakTopic) {
                $topic = trim((string) $weakTopic);
                if ($topic === '') {
                    continue;
                }
                $weakTopicFrequency[$topic] = ($weakTopicFrequency[$topic] ?? 0) + 1;
            }
        }

        if ($allReports !== []) {
            $averageScore = round($averageScore / count($allReports), 2);
        }

        arsort($weakTopicFrequency);
        uasort($subjectProgress, static function (array $a, array $b): int {
            return $b['total'] <=> $a['total'];
        });

        $masteryRate = $allReports === [] ? 0 : (int) round(($masteredCount / count($allReports)) * 100);
        $nextAction = 'Upload a lesson and start your first adaptive quiz.';
        $latestReport = $recentReports[0] ?? null;
        if ($latestReport !== null) {
            $nextAction = match ($latestReport->getMasteryStatus()->value) {
                'MASTERED' => 'Great progress. Start a new lesson to keep momentum.',
                'NEEDS_REVIEW' => 'Review weak topics and retake the new adaptive quiz.',
                default => 'Focus on generated remediation materials before the next attempt.',
            };
        }

        return $this->render('student/dashboard.html.twig', [
            'student' => $student,
            'lessons' => $recentLessons,
            'reports' => $recentReports,
            'dashboard' => [
                'totalLessons' => count($allLessons),
                'doneLessons' => $doneLessons,
                'pendingLessons' => $pendingLessons,
                'failedLessons' => $failedLessons,
                'totalReports' => count($allReports),
                'averageScore' => $averageScore,
                'masteredCount' => $masteredCount,
                'needsReviewCount' => $needsReviewCount,
                'notMasteredCount' => $notMasteredCount,
                'masteryRate' => $masteryRate,
                'plannedMinutes' => $totalEstimatedMinutes,
                'nextAction' => $nextAction,
                'subjectProgress' => array_values($subjectProgress),
                'topWeakTopics' => array_slice(array_keys($weakTopicFrequency), 0, 5),
                'groupJoined' => $group !== null,
                'groupName' => $groupName,
                'teacherName' => $teacherName,
            ],
        ]);
    }

    #[Route('/lessons', name: 'student_lessons')]
    public function lessons(
        Request $request,
        EntityManagerInterface $entityManager,
        SluggerInterface $slugger,
        MessageBusInterface $messageBus,
    ): Response {
        $student = $this->currentStudentProfile();
        if ($student === null) {
            throw $this->createAccessDeniedException('Student profile is missing.');
        }

        $lesson = new Lesson();
        $form = $this->createForm(LessonUploadType::class, $lesson);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $uploadedFile = $form->get('lessonFile')->getData();
            if ($uploadedFile !== null) {
                $safeBase = $slugger->slug(pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME));
                $newFilename = sprintf('%s-%s.%s', $safeBase, bin2hex(random_bytes(4)), $uploadedFile->guessExtension() ?: 'txt');

                try {
                    $uploadedFile->move($this->getParameter('app.lesson_upload_dir'), $newFilename);
                } catch (FileException $exception) {
                    $this->addFlash('error', 'Upload failed: '.$exception->getMessage());

                    return $this->redirectToRoute('student_lessons');
                }

                $lesson
                    ->setFilePath('/uploads/lessons/'.$newFilename)
                    ->setUploadedBy($student)
                    ->setProcessingStatus(ProcessingStatus::Pending);

                $entityManager->persist($lesson);
                $entityManager->flush();

                $messageBus->dispatch(new AnalyzeLessonMessage((int) $lesson->getId()));
                $this->addFlash('success', 'Lesson uploaded. AI analysis and generation started in background.');

                return $this->redirectToRoute('student_lesson_show', ['id' => $lesson->getId()]);
            }
        }

        $lessons = $entityManager->getRepository(Lesson::class)->findBy(['uploadedBy' => $student], ['createdAt' => 'DESC']);

        return $this->render('student/lessons.html.twig', [
            'lessonForm' => $form,
            'lessons' => $lessons,
        ]);
    }

    #[Route('/lessons/{id}', name: 'student_lesson_show', requirements: ['id' => '\\d+'])]
    public function lessonShow(int $id, EntityManagerInterface $entityManager): Response
    {
        $student = $this->currentStudentProfile();
        if ($student === null) {
            throw $this->createAccessDeniedException('Student profile is missing.');
        }

        $lesson = $entityManager->getRepository(Lesson::class)->find($id);
        if (!$lesson instanceof Lesson) {
            throw $this->createNotFoundException('Lesson not found.');
        }

        if ($lesson->getUploadedBy()?->getId() !== $student->getId()) {
            throw $this->createAccessDeniedException('You can only access your own lessons.');
        }

        $materials = $entityManager->getRepository('App\\Entity\\StudyMaterial')->findBy(
            ['lesson' => $lesson],
            ['version' => 'DESC', 'createdAt' => 'DESC'],
        );

        $latestQuiz = $lesson->getLatestQuiz();
        $latestReport = $entityManager->getRepository('App\\Entity\\PerformanceReport')->findOneBy(
            ['student' => $student, 'lesson' => $lesson],
            ['createdAt' => 'DESC'],
        );

        $lessonComments = $entityManager->getRepository(TeacherComment::class)->findBy(
            ['lesson' => $lesson, 'student' => $student],
            ['createdAt' => 'ASC'],
        );

        return $this->render('student/lesson_show.html.twig', [
            'lesson' => $lesson,
            'materials' => $materials,
            'latestQuiz' => $latestQuiz,
            'latestReport' => $latestReport,
            'lessonComments' => $lessonComments,
        ]);
    }

    #[Route('/comments', name: 'student_comments')]
    public function comments(Request $request, EntityManagerInterface $entityManager): Response
    {
        $student = $this->currentStudentProfile();
        if ($student === null) {
            throw $this->createAccessDeniedException('Student profile is missing.');
        }

        $lessons = $entityManager->getRepository(Lesson::class)->findBy(
            ['uploadedBy' => $student],
            ['createdAt' => 'DESC'],
        );

        $selectedLesson = null;
        $requestedLessonId = (int) $request->query->get('lesson', 0);
        foreach ($lessons as $candidateLesson) {
            if ($requestedLessonId > 0 && $candidateLesson->getId() === $requestedLessonId) {
                $selectedLesson = $candidateLesson;
                break;
            }
        }

        if ($selectedLesson === null && $lessons !== []) {
            $selectedLesson = $lessons[0];
        }

        $thread = [];
        $latestReport = null;
        $replyTargetTeacherCommentId = null;

        if ($selectedLesson instanceof Lesson) {
            $thread = $entityManager->getRepository(TeacherComment::class)->findBy(
                ['lesson' => $selectedLesson, 'student' => $student],
                ['createdAt' => 'ASC'],
            );

            for ($index = count($thread) - 1; $index >= 0; --$index) {
                $comment = $thread[$index];
                if ($comment->isTeacherAuthor()) {
                    $replyTargetTeacherCommentId = $comment->getId();
                    break;
                }
            }

            $latestReport = $entityManager->getRepository('App\\Entity\\PerformanceReport')->findOneBy(
                ['student' => $student, 'lesson' => $selectedLesson],
                ['createdAt' => 'DESC'],
            );
        }

        return $this->render('student/comments.html.twig', [
            'student' => $student,
            'lessons' => $lessons,
            'selectedLesson' => $selectedLesson,
            'thread' => $thread,
            'latestReport' => $latestReport,
            'replyTargetTeacherCommentId' => $replyTargetTeacherCommentId,
        ]);
    }

    #[Route('/lessons/{id}/regenerate-quiz', name: 'student_lesson_regenerate_quiz', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function regenerateQuiz(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        MessageBusInterface $messageBus,
    ): Response {
        $student = $this->currentStudentProfile();
        if ($student === null) {
            throw $this->createAccessDeniedException('Student profile is missing.');
        }

        if (!$this->isCsrfTokenValid('regenerate_quiz_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectToRoute('student_lesson_show', ['id' => $id]);
        }

        $lesson = $entityManager->getRepository(Lesson::class)->find($id);
        if (!$lesson instanceof Lesson || $lesson->getUploadedBy()?->getId() !== $student->getId()) {
            throw $this->createAccessDeniedException('You can only regenerate quizzes for your own lessons.');
        }

        $latestReport = $entityManager->getRepository('App\\Entity\\PerformanceReport')->findOneBy(
            ['student' => $student, 'lesson' => $lesson],
            ['createdAt' => 'DESC'],
        );

        $focusTopics = $latestReport?->getWeakTopics() ?? [];
        $messageBus->dispatch(new GenerateQuizMessage((int) $lesson->getId(), $focusTopics));

        $this->addFlash('success', $focusTopics === []
            ? 'A fresh lesson-based quiz is being generated.'
            : 'A new adaptive quiz is being generated from your weak topics.');

        return $this->redirectToRoute('student_lesson_show', ['id' => $id]);
    }

    #[Route('/lessons/{lessonId}/comments/{commentId}/reply', name: 'student_lesson_comment_reply', requirements: ['lessonId' => '\\d+', 'commentId' => '\\d+'], methods: ['POST'])]
    public function replyToLessonComment(
        int $lessonId,
        int $commentId,
        Request $request,
        EntityManagerInterface $entityManager,
        PerspectiveModerationService $moderationService,
        ThirdPartyMetaService $thirdPartyMetaService,
        EntityThirdPartyLinkRecorder $entityThirdPartyLinkRecorder,
    ): Response {
        $student = $this->currentStudentProfile();
        if ($student === null) {
            throw $this->createAccessDeniedException('Student profile is missing.');
        }

        if (!$this->isCsrfTokenValid('student_reply_comment_'.$commentId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectAfterCommentAction($request, $lessonId);
        }

        $lesson = $entityManager->getRepository(Lesson::class)->find($lessonId);
        if (!$lesson instanceof Lesson || $lesson->getUploadedBy()?->getId() !== $student->getId()) {
            throw $this->createAccessDeniedException('You can only reply on your own lesson comments.');
        }

        $comment = $entityManager->getRepository(TeacherComment::class)->find($commentId);
        if (!$comment instanceof TeacherComment
            || $comment->getLesson()?->getId() !== $lesson->getId()
            || $comment->getStudent()?->getId() !== $student->getId()) {
            throw $this->createAccessDeniedException('Comment not found for this lesson.');
        }

        if (!$comment->isTeacherAuthor()) {
            $this->addFlash('error', 'You can reply only to teacher comments.');

            return $this->redirectAfterCommentAction($request, $lessonId);
        }

        $teacher = $comment->getTeacher();
        if ($teacher === null) {
            $this->addFlash('error', 'Teacher reference is missing for this comment.');

            return $this->redirectAfterCommentAction($request, $lessonId);
        }

        $content = trim((string) $request->request->get('content', ''));
        if (mb_strlen($content) < 2) {
            $this->addFlash('error', 'Reply must contain at least 2 characters.');

            return $this->redirectAfterCommentAction($request, $lessonId);
        }

        $moderation = $moderationService->moderate($content);
        if ($moderation['allowed'] !== true) {
            $this->addFlash('error', 'Reply blocked by moderation policy.');

            return $this->redirectAfterCommentAction($request, $lessonId);
        }

        $reply = (new TeacherComment())
            ->setTeacher($teacher)
            ->setStudent($student)
            ->setLesson($lesson)
            ->setParentComment($comment)
            ->setAuthorRole(TeacherComment::AUTHOR_STUDENT)
            ->setContent($content);
        $thirdPartyMetaService->record(
            $reply,
            ThirdPartyProvider::GooglePerspective,
            $moderation['status'],
            $moderation['message'],
            [
                'score' => $moderation['score'],
                'warn' => $moderation['warn'],
                'action' => $moderation['action'],
            ],
            null,
            $moderation['latencyMs'],
        );
        $entityThirdPartyLinkRecorder->recordLinks($reply);

        $entityManager->persist($reply);
        $entityManager->flush();

        $this->addFlash('success', 'Your reply was sent.');

        return $this->redirectAfterCommentAction($request, $lessonId);
    }

    #[Route('/lessons/{lessonId}/comments/{commentId}/edit', name: 'student_lesson_comment_edit', requirements: ['lessonId' => '\\d+', 'commentId' => '\\d+'], methods: ['POST'])]
    public function editLessonComment(
        int $lessonId,
        int $commentId,
        Request $request,
        EntityManagerInterface $entityManager,
        PerspectiveModerationService $moderationService,
        ThirdPartyMetaService $thirdPartyMetaService,
        EntityThirdPartyLinkRecorder $entityThirdPartyLinkRecorder,
    ): Response {
        $student = $this->currentStudentProfile();
        if ($student === null) {
            throw $this->createAccessDeniedException('Student profile is missing.');
        }

        if (!$this->isCsrfTokenValid('student_comment_edit_'.$commentId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectAfterCommentAction($request, $lessonId);
        }

        $lesson = $entityManager->getRepository(Lesson::class)->find($lessonId);
        if (!$lesson instanceof Lesson || $lesson->getUploadedBy()?->getId() !== $student->getId()) {
            throw $this->createAccessDeniedException('You can only edit replies on your own lessons.');
        }

        $comment = $entityManager->getRepository(TeacherComment::class)->find($commentId);
        if (!$comment instanceof TeacherComment
            || $comment->getLesson()?->getId() !== $lesson->getId()
            || $comment->getStudent()?->getId() !== $student->getId()) {
            throw $this->createNotFoundException('Comment not found.');
        }

        if (!$comment->isStudentAuthor()) {
            throw $this->createAccessDeniedException('You can edit only your own replies.');
        }

        $content = trim((string) $request->request->get('content', ''));
        if (mb_strlen($content) < 2 || mb_strlen($content) > 2000) {
            $this->addFlash('error', 'Reply must contain between 2 and 2000 characters.');

            return $this->redirectAfterCommentAction($request, $lessonId);
        }

        $moderation = $moderationService->moderate($content);
        if ($moderation['allowed'] !== true) {
            $this->addFlash('error', 'Reply blocked by moderation policy.');

            return $this->redirectAfterCommentAction($request, $lessonId);
        }

        $comment
            ->setContent($content)
            ->setUpdatedAt(new \DateTimeImmutable());

        $thirdPartyMetaService->record(
            $comment,
            ThirdPartyProvider::GooglePerspective,
            $moderation['status'],
            $moderation['message'],
            [
                'score' => $moderation['score'],
                'warn' => $moderation['warn'],
                'action' => $moderation['action'],
            ],
            null,
            $moderation['latencyMs'],
        );
        $entityThirdPartyLinkRecorder->recordLinks($comment);

        $entityManager->flush();

        $this->addFlash('success', 'Reply updated.');

        return $this->redirectAfterCommentAction($request, $lessonId);
    }

    #[Route('/lessons/{lessonId}/comments/{commentId}/delete', name: 'student_lesson_comment_delete', requirements: ['lessonId' => '\\d+', 'commentId' => '\\d+'], methods: ['POST'])]
    public function deleteLessonComment(
        int $lessonId,
        int $commentId,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $student = $this->currentStudentProfile();
        if ($student === null) {
            throw $this->createAccessDeniedException('Student profile is missing.');
        }

        if (!$this->isCsrfTokenValid('student_comment_delete_'.$commentId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectAfterCommentAction($request, $lessonId);
        }

        $lesson = $entityManager->getRepository(Lesson::class)->find($lessonId);
        if (!$lesson instanceof Lesson || $lesson->getUploadedBy()?->getId() !== $student->getId()) {
            throw $this->createAccessDeniedException('You can only delete replies on your own lessons.');
        }

        $comment = $entityManager->getRepository(TeacherComment::class)->find($commentId);
        if (!$comment instanceof TeacherComment
            || $comment->getLesson()?->getId() !== $lesson->getId()
            || $comment->getStudent()?->getId() !== $student->getId()) {
            throw $this->createNotFoundException('Comment not found.');
        }

        if (!$comment->isStudentAuthor()) {
            throw $this->createAccessDeniedException('You can delete only your own replies.');
        }

        $entityManager->remove($comment);
        $entityManager->flush();

        $this->addFlash('success', 'Reply deleted.');

        return $this->redirectAfterCommentAction($request, $lessonId);
    }

    #[Route('/groups/join', name: 'student_group_join')]
    public function joinGroup(Request $request, EntityManagerInterface $entityManager): Response
    {
        $student = $this->currentStudentProfile();
        if ($student === null) {
            throw $this->createAccessDeniedException('Student profile is missing.');
        }

        $form = $this->createForm(JoinGroupType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $inviteCode = strtoupper(trim((string) $form->get('inviteCode')->getData()));
            $group = $entityManager->getRepository('App\\Entity\\StudyGroup')->findOneBy(['inviteCode' => $inviteCode]);

            if ($group === null) {
                $this->addFlash('error', 'Invite code not found.');
            } else {
                $student->setGroup($group);
                $entityManager->flush();
                $teacherName = $group->getTeacher()?->getUser()?->getName() ?? 'your teacher';
                $this->addFlash('success', sprintf('You have joined the group %s (Teacher: %s).', $group->getName(), $teacherName));

                return $this->redirectToRoute('student_dashboard');
            }
        }

        return $this->render('student/join_group.html.twig', [
            'joinForm' => $form,
            'student' => $student,
        ]);
    }

    #[Route('/groups/leave', name: 'student_group_leave', methods: ['POST'])]
    public function leaveGroup(Request $request, EntityManagerInterface $entityManager): Response
    {
        $student = $this->currentStudentProfile();
        if ($student === null) {
            throw $this->createAccessDeniedException('Student profile is missing.');
        }

        if (!$this->isCsrfTokenValid('student_group_leave', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectToRoute('student_group_join');
        }

        if ($student->getGroup() === null) {
            $this->addFlash('success', 'You are not currently assigned to any group.');

            return $this->redirectToRoute('student_group_join');
        }

        $student->setGroup(null);
        $entityManager->flush();

        $this->addFlash('success', 'You left the group successfully.');

        return $this->redirectToRoute('student_group_join');
    }

    #[Route('/reports', name: 'student_reports')]
    public function reports(EntityManagerInterface $entityManager): Response
    {
        $student = $this->currentStudentProfile();
        if ($student === null) {
            throw $this->createAccessDeniedException('Student profile is missing.');
        }

        $reports = $entityManager->getRepository('App\\Entity\\PerformanceReport')->findBy(
            ['student' => $student],
            ['createdAt' => 'DESC'],
        );

        $linkedFeedback = $entityManager->createQueryBuilder()
            ->select('c', 't', 'tu', 'l')
            ->from(TeacherComment::class, 'c')
            ->join('c.teacher', 't')
            ->join('t.user', 'tu')
            ->join('c.lesson', 'l')
            ->where('c.student = :student')
            ->andWhere('c.authorRole = :authorRole')
            ->setParameter('student', $student)
            ->setParameter('authorRole', TeacherComment::AUTHOR_TEACHER)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(40)
            ->getQuery()
            ->getResult();

        $legacyFeedback = $entityManager->createQueryBuilder()
            ->select('c', 't', 'tu')
            ->from(TeacherComment::class, 'c')
            ->join('c.teacher', 't')
            ->join('t.user', 'tu')
            ->where('c.student = :student')
            ->andWhere('c.authorRole = :authorRole')
            ->andWhere('c.lesson IS NULL')
            ->setParameter('student', $student)
            ->setParameter('authorRole', TeacherComment::AUTHOR_TEACHER)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        $totalReports = count($reports);
        $averageScore = 0.0;
        $masteredCount = 0;
        $needsReviewCount = 0;
        $notMasteredCount = 0;

        foreach ($reports as $report) {
            $averageScore += $report->getQuizScore();
            $status = $report->getMasteryStatus()->value;
            if ($status === 'MASTERED') {
                ++$masteredCount;
            } elseif ($status === 'NEEDS_REVIEW') {
                ++$needsReviewCount;
            } else {
                ++$notMasteredCount;
            }
        }

        if ($totalReports > 0) {
            $averageScore = round($averageScore / $totalReports, 2);
        }

        return $this->render('student/reports.html.twig', [
            'reports' => $reports,
            'student' => $student,
            'linkedFeedback' => $linkedFeedback,
            'legacyFeedback' => $legacyFeedback,
            'summary' => [
                'averageScore' => $averageScore,
                'masteredCount' => $masteredCount,
                'needsReviewCount' => $needsReviewCount,
                'notMasteredCount' => $notMasteredCount,
                'totalReports' => $totalReports,
            ],
        ]);
    }

    private function currentStudentProfile(): ?StudentProfile
    {
        $user = $this->getUser();

        if (!is_object($user) || !method_exists($user, 'getStudentProfile')) {
            return null;
        }

        return $user->getStudentProfile();
    }

    private function redirectAfterCommentAction(Request $request, int $lessonId): Response
    {
        $returnPage = strtolower(trim((string) $request->request->get('return_page', '')));
        if ($returnPage === 'comments') {
            $returnLesson = (int) $request->request->get('return_lesson', $lessonId);
            if ($returnLesson <= 0) {
                $returnLesson = $lessonId;
            }

            return $this->redirectToRoute('student_comments', ['lesson' => $returnLesson]);
        }

        return $this->redirectToRoute('student_lesson_show', ['id' => $lessonId]);
    }
}
