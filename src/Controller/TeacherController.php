<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Lesson;
use App\Entity\PerformanceReport;
use App\Entity\StudentProfile;
use App\Entity\StudyGroup;
use App\Entity\TeacherComment;
use App\Entity\TeacherProfile;
use App\Enum\ThirdPartyProvider;
use App\Enum\ThirdPartyStatus;
use App\Form\StudyGroupType;
use App\Service\EntityThirdPartyLinkRecorder;
use App\Service\InviteCodeGenerator;
use App\Service\PerspectiveModerationService;
use App\Service\ThirdPartyMetaService;
use App\Service\YouTubeRecommendationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_TEACHER')]
#[Route('/teacher')]
class TeacherController extends AbstractController
{
    #[Route('/dashboard', name: 'teacher_dashboard')]
    public function dashboard(EntityManagerInterface $entityManager): Response
    {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        $groups = $entityManager->getRepository(StudyGroup::class)->findBy(['teacher' => $teacher], ['id' => 'DESC']);
        $lessons = $this->findTeacherLessons($entityManager, $teacher, 8);
        $students = $this->findTeacherStudents($entityManager, $teacher);

        $studentsCount = count($students);

        $reports = $entityManager->createQueryBuilder()
            ->select('r', 's', 'u', 'l')
            ->from(PerformanceReport::class, 'r')
            ->join('r.student', 's')
            ->join('s.user', 'u')
            ->join('r.lesson', 'l')
            ->join('s.group', 'g')
            ->where('g.teacher = :teacher')
            ->setParameter('teacher', $teacher)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(120)
            ->getQuery()
            ->getResult();

        $averageScore = 0.0;
        $masteredCount = 0;
        $needsReviewCount = 0;
        $notMasteredCount = 0;
        $weakTopicFrequency = [];
        $studentStats = [];

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

            foreach ($report->getWeakTopics() as $weakTopic) {
                $topic = trim((string) $weakTopic);
                if ($topic === '') {
                    continue;
                }
                $weakTopicFrequency[$topic] = ($weakTopicFrequency[$topic] ?? 0) + 1;
            }

            $studentId = $report->getStudent()?->getId();
            if ($studentId === null) {
                continue;
            }

            if (!isset($studentStats[$studentId])) {
                $studentStats[$studentId] = [
                    'student' => $report->getStudent(),
                    'total' => 0,
                    'sum' => 0.0,
                    'lastStatus' => $status,
                ];
            }

            ++$studentStats[$studentId]['total'];
            $studentStats[$studentId]['sum'] += $report->getQuizScore();
            $studentStats[$studentId]['lastStatus'] = $status;
        }

        if ($reports !== []) {
            $averageScore = round($averageScore / count($reports), 2);
        }

        $atRiskStudents = [];
        foreach ($studentStats as $stat) {
            $avg = $stat['total'] > 0 ? ($stat['sum'] / $stat['total']) : 0.0;
            if ($avg < 60 || $stat['lastStatus'] === 'NOT_MASTERED') {
                $atRiskStudents[] = [
                    'student' => $stat['student'],
                    'averageScore' => round($avg, 1),
                    'lastStatus' => $stat['lastStatus'],
                ];
            }
        }

        usort($atRiskStudents, static function (array $a, array $b): int {
            return $a['averageScore'] <=> $b['averageScore'];
        });
        $atRiskStudents = array_slice($atRiskStudents, 0, 6);

        $latestReportByStudentId = [];
        foreach ($reports as $report) {
            $studentId = $report->getStudent()?->getId();
            if ($studentId === null || isset($latestReportByStudentId[$studentId])) {
                continue;
            }
            $latestReportByStudentId[$studentId] = $report;
        }

        $studentGroupRows = [];
        foreach ($students as $student) {
            $latest = $latestReportByStudentId[$student->getId()] ?? null;
            $studentGroupRows[] = [
                'student' => $student,
                'studentName' => $student->getUser()?->getName() ?? 'Unknown',
                'groupName' => $student->getGroup()?->getName() ?? 'No group',
                'latestScore' => $latest?->getQuizScore(),
                'latestMastery' => $latest?->getMasteryStatus()->value,
                'lastActivityAt' => $latest?->getCreatedAt(),
            ];
        }
        usort($studentGroupRows, static function (array $left, array $right): int {
            return strcmp((string) $left['studentName'], (string) $right['studentName']);
        });

        $groupInsights = [];
        foreach ($groups as $group) {
            $groupStudentIds = [];
            foreach ($group->getStudents() as $student) {
                $groupStudentIds[] = $student->getId();
            }

            $groupAverage = 0.0;
            $groupReports = 0;
            foreach ($reports as $report) {
                $studentId = $report->getStudent()?->getId();
                if ($studentId !== null && in_array($studentId, $groupStudentIds, true)) {
                    ++$groupReports;
                    $groupAverage += $report->getQuizScore();
                }
            }

            $groupInsights[] = [
                'group' => $group,
                'studentsCount' => count($groupStudentIds),
                'reportsCount' => $groupReports,
                'averageScore' => $groupReports > 0 ? round($groupAverage / $groupReports, 1) : null,
            ];
        }

        arsort($weakTopicFrequency);
        usort($groupInsights, static function (array $a, array $b): int {
            $avgA = $a['averageScore'] ?? -1;
            $avgB = $b['averageScore'] ?? -1;

            return $avgB <=> $avgA;
        });

        return $this->render('teacher/dashboard.html.twig', [
            'teacher' => $teacher,
            'groups' => $groups,
            'lessons' => $lessons,
            'studentsCount' => $studentsCount,
            'dashboard' => [
                'studentsCount' => $studentsCount,
                'groupsCount' => count($groups),
                'lessonsCount' => count($lessons),
                'reportsCount' => count($reports),
                'averageScore' => $averageScore,
                'masteredCount' => $masteredCount,
                'needsReviewCount' => $needsReviewCount,
                'notMasteredCount' => $notMasteredCount,
                'masteryRate' => $reports === [] ? 0 : (int) round(($masteredCount / count($reports)) * 100),
                'studentsWithoutGroup' => count(array_filter($students, static fn (StudentProfile $student): bool => $student->getGroup() === null)),
                'topWeakTopics' => array_slice(array_keys($weakTopicFrequency), 0, 5),
                'atRiskStudents' => $atRiskStudents,
                'studentGroupRows' => $studentGroupRows,
                'groupInsights' => $groupInsights,
                'recentReports' => array_slice($reports, 0, 6),
            ],
        ]);
    }

    #[Route('/groups', name: 'teacher_groups')]
    public function groups(
        Request $request,
        EntityManagerInterface $entityManager,
        InviteCodeGenerator $inviteCodeGenerator,
        YouTubeRecommendationService $youtubeRecommendationService,
        ThirdPartyMetaService $thirdPartyMetaService,
        EntityThirdPartyLinkRecorder $entityThirdPartyLinkRecorder,
    ): Response {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        $group = new StudyGroup();
        $form = $this->createForm(StudyGroupType::class, $group);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $inviteCode = $inviteCodeGenerator->generate(8);
            while ($entityManager->getRepository(StudyGroup::class)->findOneBy(['inviteCode' => $inviteCode]) !== null) {
                $inviteCode = $inviteCodeGenerator->generate(8);
            }

            $group
                ->setTeacher($teacher)
                ->setInviteCode($inviteCode);

            $query = trim($group->getName().' educational resources');
            $recommendations = $youtubeRecommendationService->recommend($query, 3);
            $thirdPartyMetaService->record(
                $group,
                ThirdPartyProvider::Youtube,
                $youtubeRecommendationService->hasProvider() ? ThirdPartyStatus::Success : ThirdPartyStatus::Fallback,
                'Group learning resources generated.',
                [
                    'query' => $query,
                    'results' => array_map(
                        static fn (array $video): array => [
                            'title' => (string) ($video['title'] ?? ''),
                            'url' => (string) ($video['url'] ?? ''),
                        ],
                        array_slice($recommendations, 0, 3),
                    ),
                ],
            );
            $entityThirdPartyLinkRecorder->recordLinks($group);

            $entityManager->persist($group);
            $entityManager->flush();

            $this->addFlash('success', 'Group created. Invite code: '.$group->getInviteCode());

            return $this->redirectToRoute('teacher_groups');
        }

        $groups = $entityManager->getRepository(StudyGroup::class)->findBy(['teacher' => $teacher], ['id' => 'DESC']);

        return $this->render('teacher/groups.html.twig', [
            'groupForm' => $form,
            'groups' => $groups,
        ]);
    }

    #[Route('/groups/{id}', name: 'teacher_group_show', requirements: ['id' => '\\d+'])]
    public function groupShow(int $id, EntityManagerInterface $entityManager): Response
    {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        $group = $entityManager->getRepository(StudyGroup::class)->find($id);
        if (!$group instanceof StudyGroup || $group->getTeacher()?->getId() !== $teacher->getId()) {
            throw $this->createNotFoundException('Group not found.');
        }

        return $this->render('teacher/group_show.html.twig', [
            'group' => $group,
        ]);
    }

    #[Route('/lessons', name: 'teacher_lessons')]
    public function lessons(EntityManagerInterface $entityManager): Response
    {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        $lessons = $this->findTeacherLessons($entityManager, $teacher);

        return $this->render('teacher/lessons.html.twig', [
            'lessons' => $lessons,
        ]);
    }

    #[Route('/comments', name: 'teacher_comments')]
    public function comments(Request $request, EntityManagerInterface $entityManager): Response
    {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        $lessons = $this->findTeacherLessons($entityManager, $teacher);

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

        $reports = [];
        $commentsByStudent = [];

        if ($selectedLesson instanceof Lesson) {
            $rawReports = $entityManager->createQueryBuilder()
                ->select('r', 's', 'u')
                ->from(PerformanceReport::class, 'r')
                ->join('r.student', 's')
                ->join('s.user', 'u')
                ->join('s.group', 'g')
                ->where('r.lesson = :lesson')
                ->andWhere('g.teacher = :teacher')
                ->setParameter('lesson', $selectedLesson)
                ->setParameter('teacher', $teacher)
                ->orderBy('r.createdAt', 'DESC')
                ->getQuery()
                ->getResult();

            $latestReportByStudent = [];
            foreach ($rawReports as $report) {
                $studentId = $report->getStudent()?->getId();
                if ($studentId === null || isset($latestReportByStudent[$studentId])) {
                    continue;
                }
                $latestReportByStudent[$studentId] = $report;
            }
            $reports = array_values($latestReportByStudent);

            $lessonComments = $entityManager->getRepository(TeacherComment::class)->findBy(
                ['lesson' => $selectedLesson, 'teacher' => $teacher],
                ['createdAt' => 'ASC'],
            );

            foreach ($lessonComments as $comment) {
                $student = $comment->getStudent();
                $studentId = $student?->getId();
                if ($studentId === null || !$this->studentBelongsToTeacher($student, $teacher)) {
                    continue;
                }

                if (!isset($commentsByStudent[$studentId])) {
                    $commentsByStudent[$studentId] = [];
                }
                $commentsByStudent[$studentId][] = $comment;
            }
        }

        return $this->render('teacher/comments.html.twig', [
            'lessons' => $lessons,
            'selectedLesson' => $selectedLesson,
            'reports' => $reports,
            'commentsByStudent' => $commentsByStudent,
        ]);
    }

    #[Route('/lessons/{id}', name: 'teacher_lesson_show', requirements: ['id' => '\\d+'])]
    public function lessonShow(int $id, EntityManagerInterface $entityManager): Response
    {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        $lesson = $entityManager->getRepository(Lesson::class)->find($id);
        if (!$lesson instanceof Lesson) {
            throw $this->createNotFoundException('Lesson not found.');
        }

        if (!$this->lessonBelongsToTeacher($lesson, $teacher)) {
            throw $this->createAccessDeniedException('You can only access lessons uploaded by students in your groups.');
        }

        $materials = $entityManager->getRepository('App\\Entity\\StudyMaterial')->findBy(
            ['lesson' => $lesson],
            ['version' => 'DESC', 'createdAt' => 'DESC'],
        );

        $reports = $entityManager->getRepository(PerformanceReport::class)->findBy(
            ['lesson' => $lesson],
            ['createdAt' => 'DESC'],
            20,
        );

        $lessonComments = $entityManager->getRepository(TeacherComment::class)->findBy(
            ['lesson' => $lesson, 'teacher' => $teacher],
            ['createdAt' => 'ASC'],
        );

        $commentsByStudent = [];
        foreach ($lessonComments as $comment) {
            $studentId = $comment->getStudent()?->getId();
            if ($studentId === null) {
                continue;
            }

            if (!isset($commentsByStudent[$studentId])) {
                $commentsByStudent[$studentId] = [];
            }

            $commentsByStudent[$studentId][] = $comment;
        }

        return $this->render('teacher/lesson_show.html.twig', [
            'lesson' => $lesson,
            'materials' => $materials,
            'reports' => $reports,
            'commentsByStudent' => $commentsByStudent,
        ]);
    }

    #[Route('/lessons/{id}/comments', name: 'teacher_lesson_comment_add', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function addLessonComment(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        PerspectiveModerationService $moderationService,
        ThirdPartyMetaService $thirdPartyMetaService,
        EntityThirdPartyLinkRecorder $entityThirdPartyLinkRecorder,
    ): Response {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        if (!$this->isCsrfTokenValid('teacher_lesson_comment_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectAfterCommentAction($request, $id);
        }

        $lesson = $entityManager->getRepository(Lesson::class)->find($id);
        if (!$lesson instanceof Lesson || !$this->lessonBelongsToTeacher($lesson, $teacher)) {
            throw $this->createAccessDeniedException('You can only comment on lessons uploaded by your students.');
        }

        $studentId = (int) $request->request->get('student_id', 0);
        $student = $entityManager->getRepository(StudentProfile::class)->find($studentId);
        if (!$student instanceof StudentProfile || !$this->studentBelongsToTeacher($student, $teacher)) {
            throw $this->createAccessDeniedException('Invalid student selected.');
        }

        if ($lesson->getUploadedBy()?->getId() !== $student->getId()) {
            throw $this->createAccessDeniedException('Selected student is not the owner of this lesson.');
        }

        $content = trim((string) $request->request->get('content', ''));
        if (mb_strlen($content) < 3) {
            $this->addFlash('error', 'Comment must contain at least 3 characters.');

            return $this->redirectAfterCommentAction($request, $id);
        }

        $moderation = $moderationService->moderate($content);
        if ($moderation['allowed'] !== true) {
            $this->addFlash('error', 'Comment blocked by moderation policy.');

            return $this->redirectAfterCommentAction($request, $id);
        }

        $parentCommentId = (int) $request->request->get('parent_comment_id', 0);
        $parentComment = null;
        if ($parentCommentId > 0) {
            $candidate = $entityManager->getRepository(TeacherComment::class)->find($parentCommentId);
            if ($candidate instanceof TeacherComment
                && $candidate->getLesson()?->getId() === $lesson->getId()
                && $candidate->getStudent()?->getId() === $student->getId()) {
                $parentComment = $candidate;
            }
        }

        $comment = (new TeacherComment())
            ->setTeacher($teacher)
            ->setStudent($student)
            ->setLesson($lesson)
            ->setParentComment($parentComment)
            ->setAuthorRole(TeacherComment::AUTHOR_TEACHER)
            ->setContent($content);
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

        $entityManager->persist($comment);
        $entityManager->flush();

        if ($moderation['warn'] === true) {
            $this->addFlash('success', 'Comment saved with moderation warning score.');
        }
        $this->addFlash('success', 'Comment saved for '.$student->getUser()?->getName().'.');

        return $this->redirectAfterCommentAction($request, $id);
    }

    #[Route('/lessons/{lessonId}/comments/{commentId}/edit', name: 'teacher_lesson_comment_edit', requirements: ['lessonId' => '\\d+', 'commentId' => '\\d+'], methods: ['POST'])]
    public function editLessonComment(
        int $lessonId,
        int $commentId,
        Request $request,
        EntityManagerInterface $entityManager,
        PerspectiveModerationService $moderationService,
        ThirdPartyMetaService $thirdPartyMetaService,
        EntityThirdPartyLinkRecorder $entityThirdPartyLinkRecorder,
    ): Response {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        if (!$this->isCsrfTokenValid('teacher_comment_edit_'.$commentId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectAfterCommentAction($request, $lessonId);
        }

        $lesson = $entityManager->getRepository(Lesson::class)->find($lessonId);
        if (!$lesson instanceof Lesson || !$this->lessonBelongsToTeacher($lesson, $teacher)) {
            throw $this->createAccessDeniedException('You can only edit comments on lessons uploaded by your students.');
        }

        $comment = $entityManager->getRepository(TeacherComment::class)->find($commentId);
        if (!$comment instanceof TeacherComment || $comment->getLesson()?->getId() !== $lesson->getId()) {
            throw $this->createNotFoundException('Comment not found.');
        }

        if (!$comment->isTeacherAuthor() || $comment->getTeacher()?->getId() !== $teacher->getId()) {
            throw $this->createAccessDeniedException('You can edit only your own comments.');
        }

        $student = $comment->getStudent();
        if (!$student instanceof StudentProfile || !$this->studentBelongsToTeacher($student, $teacher)) {
            throw $this->createAccessDeniedException('Comment student is outside your groups.');
        }

        $content = trim((string) $request->request->get('content', ''));
        if (mb_strlen($content) < 3 || mb_strlen($content) > 3000) {
            $this->addFlash('error', 'Comment must contain between 3 and 3000 characters.');

            return $this->redirectAfterCommentAction($request, $lessonId);
        }

        $moderation = $moderationService->moderate($content);
        if ($moderation['allowed'] !== true) {
            $this->addFlash('error', 'Comment blocked by moderation policy.');

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

        if ($moderation['warn'] === true) {
            $this->addFlash('success', 'Comment updated with moderation warning score.');
        } else {
            $this->addFlash('success', 'Comment updated.');
        }

        return $this->redirectAfterCommentAction($request, $lessonId);
    }

    #[Route('/lessons/{lessonId}/comments/{commentId}/delete', name: 'teacher_lesson_comment_delete', requirements: ['lessonId' => '\\d+', 'commentId' => '\\d+'], methods: ['POST'])]
    public function deleteLessonComment(
        int $lessonId,
        int $commentId,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        if (!$this->isCsrfTokenValid('teacher_comment_delete_'.$commentId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectAfterCommentAction($request, $lessonId);
        }

        $lesson = $entityManager->getRepository(Lesson::class)->find($lessonId);
        if (!$lesson instanceof Lesson || !$this->lessonBelongsToTeacher($lesson, $teacher)) {
            throw $this->createAccessDeniedException('You can only delete comments on lessons uploaded by your students.');
        }

        $comment = $entityManager->getRepository(TeacherComment::class)->find($commentId);
        if (!$comment instanceof TeacherComment || $comment->getLesson()?->getId() !== $lesson->getId()) {
            throw $this->createNotFoundException('Comment not found.');
        }

        if (!$comment->isTeacherAuthor() || $comment->getTeacher()?->getId() !== $teacher->getId()) {
            throw $this->createAccessDeniedException('You can delete only your own comments.');
        }

        $student = $comment->getStudent();
        if (!$student instanceof StudentProfile || !$this->studentBelongsToTeacher($student, $teacher)) {
            throw $this->createAccessDeniedException('Comment student is outside your groups.');
        }

        $entityManager->remove($comment);
        $entityManager->flush();

        $this->addFlash('success', 'Comment deleted.');

        return $this->redirectAfterCommentAction($request, $lessonId);
    }

    #[Route('/groups/{groupId}/students/{studentId}/remove', name: 'teacher_group_student_remove', requirements: ['groupId' => '\\d+', 'studentId' => '\\d+'], methods: ['POST'])]
    public function removeStudentFromGroup(
        int $groupId,
        int $studentId,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        if (!$this->isCsrfTokenValid('teacher_group_remove_student_'.$groupId.'_'.$studentId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectToRoute('teacher_group_show', ['id' => $groupId]);
        }

        $group = $entityManager->getRepository(StudyGroup::class)->find($groupId);
        if (!$group instanceof StudyGroup || $group->getTeacher()?->getId() !== $teacher->getId()) {
            throw $this->createNotFoundException('Group not found.');
        }

        $student = $entityManager->getRepository(StudentProfile::class)->find($studentId);
        if (!$student instanceof StudentProfile || $student->getGroup()?->getId() !== $group->getId()) {
            throw $this->createAccessDeniedException('Student is not assigned to this group.');
        }

        $student->setGroup(null);
        $entityManager->flush();

        $this->addFlash('success', 'Student removed from group.');

        return $this->redirectToRoute('teacher_group_show', ['id' => $groupId]);
    }

    #[Route('/reports', name: 'teacher_reports')]
    public function reports(EntityManagerInterface $entityManager): Response
    {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        $reports = $entityManager->createQueryBuilder()
            ->select('r', 's', 'u', 'l')
            ->from(PerformanceReport::class, 'r')
            ->join('r.student', 's')
            ->join('s.user', 'u')
            ->join('r.lesson', 'l')
            ->join('s.group', 'g')
            ->where('g.teacher = :teacher')
            ->setParameter('teacher', $teacher)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('teacher/reports.html.twig', [
            'reports' => $reports,
        ]);
    }

    #[Route('/students/{id}', name: 'teacher_student_show', requirements: ['id' => '\\d+'])]
    public function studentShow(
        int $id,
        EntityManagerInterface $entityManager,
    ): Response {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        $student = $entityManager->getRepository(StudentProfile::class)->find($id);
        if (!$student instanceof StudentProfile) {
            throw $this->createNotFoundException('Student not found.');
        }

        if (!$this->studentBelongsToTeacher($student, $teacher)) {
            throw $this->createAccessDeniedException('You can only view students assigned to your groups.');
        }

        $reports = $entityManager->getRepository(PerformanceReport::class)->findBy(
            ['student' => $student],
            ['createdAt' => 'DESC'],
            20,
        );

        $comments = $entityManager->createQueryBuilder()
            ->select('c', 'l')
            ->from(TeacherComment::class, 'c')
            ->leftJoin('c.lesson', 'l')
            ->where('c.student = :student')
            ->andWhere('c.teacher = :teacher')
            ->andWhere('c.lesson IS NOT NULL')
            ->setParameter('student', $student)
            ->setParameter('teacher', $teacher)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(40)
            ->getQuery()
            ->getResult();

        return $this->render('teacher/student_show.html.twig', [
            'student' => $student,
            'reports' => $reports,
            'comments' => $comments,
        ]);
    }

    private function currentTeacherProfile(): ?TeacherProfile
    {
        $user = $this->getUser();

        if (!is_object($user) || !method_exists($user, 'getTeacherProfile')) {
            return null;
        }

        return $user->getTeacherProfile();
    }

    /**
     * @return list<Lesson>
     */
    private function findTeacherLessons(EntityManagerInterface $entityManager, TeacherProfile $teacher, ?int $limit = null): array
    {
        $qb = $entityManager->createQueryBuilder()
            ->select('l', 's', 'u')
            ->from(Lesson::class, 'l')
            ->join('l.uploadedBy', 's')
            ->join('s.user', 'u')
            ->join('s.group', 'g')
            ->where('g.teacher = :teacher')
            ->setParameter('teacher', $teacher)
            ->orderBy('l.createdAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<StudentProfile>
     */
    private function findTeacherStudents(EntityManagerInterface $entityManager, TeacherProfile $teacher): array
    {
        return $entityManager->createQueryBuilder()
            ->select('s', 'u', 'g')
            ->from(StudentProfile::class, 's')
            ->join('s.user', 'u')
            ->join('s.group', 'g')
            ->where('g.teacher = :teacher')
            ->setParameter('teacher', $teacher)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function lessonBelongsToTeacher(Lesson $lesson, TeacherProfile $teacher): bool
    {
        $student = $lesson->getUploadedBy();

        return $student instanceof StudentProfile && $this->studentBelongsToTeacher($student, $teacher);
    }

    private function studentBelongsToTeacher(StudentProfile $student, TeacherProfile $teacher): bool
    {
        $group = $student->getGroup();
        if ($group === null) {
            return false;
        }

        return $group->getTeacher()?->getId() === $teacher->getId();
    }

    private function redirectAfterCommentAction(Request $request, int $lessonId): Response
    {
        $returnPage = strtolower(trim((string) $request->request->get('return_page', '')));
        if ($returnPage === 'comments') {
            $returnLesson = (int) $request->request->get('return_lesson', $lessonId);
            if ($returnLesson <= 0) {
                $returnLesson = $lessonId;
            }

            return $this->redirectToRoute('teacher_comments', ['lesson' => $returnLesson]);
        }

        return $this->redirectToRoute('teacher_lesson_show', ['id' => $lessonId]);
    }
}



