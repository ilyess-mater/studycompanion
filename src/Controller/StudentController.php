<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Lesson;
use App\Entity\StudentProfile;
use App\Entity\TeacherComment;
use App\Enum\ProcessingStatus;
use App\Form\JoinGroupType;
use App\Form\LessonUploadType;
use App\Service\LessonWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
            ],
        ]);
    }

    #[Route('/lessons', name: 'student_lessons')]
    public function lessons(
        Request $request,
        EntityManagerInterface $entityManager,
        SluggerInterface $slugger,
        LessonWorkflowService $lessonWorkflowService,
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

                $lessonWorkflowService->processUploadedLesson((int) $lesson->getId());
                $this->addFlash('success', 'Lesson uploaded and processed. Study materials and quiz are ready.');

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

        return $this->render('student/lesson_show.html.twig', [
            'lesson' => $lesson,
            'materials' => $materials,
            'latestQuiz' => $latestQuiz,
            'latestReport' => $latestReport,
        ]);
    }

    #[Route('/lessons/{id}/regenerate-quiz', name: 'student_lesson_regenerate_quiz', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function regenerateQuiz(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        LessonWorkflowService $lessonWorkflowService,
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
        $lessonWorkflowService->generateQuiz((int) $lesson->getId(), $focusTopics);

        $this->addFlash('success', $focusTopics === []
            ? 'A fresh lesson-based quiz was generated.'
            : 'A new adaptive quiz was generated from your weak topics.');

        return $this->redirectToRoute('student_lesson_show', ['id' => $id]);
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
                $this->addFlash('success', 'You have joined the group '.$group->getName().'.');

                return $this->redirectToRoute('student_dashboard');
            }
        }

        return $this->render('student/join_group.html.twig', [
            'joinForm' => $form,
            'student' => $student,
        ]);
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

        $comments = $entityManager->getRepository(TeacherComment::class)->findBy(
            ['student' => $student],
            ['createdAt' => 'DESC'],
            20,
        );

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
            'comments' => $comments,
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
}
