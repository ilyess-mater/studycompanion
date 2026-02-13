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

        $lessons = $entityManager->getRepository(Lesson::class)->findBy(
            ['uploadedBy' => $student],
            ['createdAt' => 'DESC'],
            6,
        );

        $reports = $entityManager->getRepository('App\\Entity\\PerformanceReport')->findBy(
            ['student' => $student],
            ['createdAt' => 'DESC'],
            6,
        );

        return $this->render('student/dashboard.html.twig', [
            'student' => $student,
            'lessons' => $lessons,
            'reports' => $reports,
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
