<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\StudentAnswer;
use App\Entity\StudentProfile;
use App\Enum\FocusSessionStatus;
use App\Enum\FocusViolationType;
use App\Entity\FocusSession;
use App\Entity\FocusViolation;
use App\Entity\PerformanceReport;
use App\Entity\Quiz;
use App\Message\GenerateMaterialsMessage;
use App\Service\PerformanceAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_STUDENT')]
class QuizController extends AbstractController
{
    #[Route('/student/quiz/{id}', name: 'student_quiz_take', requirements: ['id' => '\\d+'])]
    public function take(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        PerformanceAnalyzer $performanceAnalyzer,
        MessageBusInterface $messageBus,
    ): Response {
        $student = $this->currentStudentProfile();
        if ($student === null) {
            throw $this->createAccessDeniedException('Student profile missing.');
        }

        $quiz = $entityManager->getRepository(Quiz::class)->find($id);
        if (!$quiz instanceof Quiz) {
            throw $this->createNotFoundException('Quiz not found.');
        }

        $lesson = $quiz->getLesson();
        if ($lesson?->getUploadedBy()?->getId() !== $student->getId()) {
            throw $this->createAccessDeniedException('You can only take quizzes for your own lessons.');
        }

        if ($request->isMethod('POST')) {
            $focusSessionId = (int) $request->request->get('focus_session_id', 0);
            $focusSession = $entityManager->getRepository(FocusSession::class)->find($focusSessionId);
            if (!$focusSession instanceof FocusSession || $focusSession->getStudent()?->getId() !== $student->getId()) {
                throw $this->createAccessDeniedException('Invalid focus session.');
            }

            $answers = $request->request->all('answers');
            $times = $request->request->all('response_times');

            $analysis = $performanceAnalyzer->analyze($quiz->getQuestions()->toArray(), $answers, $times);

            foreach ($analysis['answerStats'] as $row) {
                $studentAnswer = (new StudentAnswer())
                    ->setStudent($student)
                    ->setQuestion($row['question'])
                    ->setAnswer($row['answer'])
                    ->setIsCorrect($row['isCorrect'])
                    ->setResponseTimeMs($row['responseTimeMs']);
                $entityManager->persist($studentAnswer);
            }

            $report = (new PerformanceReport())
                ->setStudent($student)
                ->setLesson($lesson)
                ->setQuiz($quiz)
                ->setQuizScore($analysis['score'])
                ->setWeakTopics($analysis['weakTopics'])
                ->setMasteryStatus($analysis['masteryStatus']);
            $entityManager->persist($report);

            $focusSession
                ->setStatus(FocusSessionStatus::Completed)
                ->setEndedAt(new \DateTimeImmutable());

            $entityManager->flush();

            if ($analysis['weakTopics'] !== []) {
                $existingVersionCount = $entityManager->getRepository('App\\Entity\\StudyMaterial')->count(['lesson' => $lesson]);
                $nextVersion = max(2, (int) floor($existingVersionCount / 4) + 1);
                $messageBus->dispatch(new GenerateMaterialsMessage((int) $lesson?->getId(), $analysis['weakTopics'], $nextVersion));
            }

            $this->addFlash('success', sprintf('Quiz completed. Score: %.2f%%', $analysis['score']));

            return $this->redirectToRoute('student_reports');
        }

        $durationSeconds = max(600, (int) (($lesson?->getEstimatedStudyMinutes() ?? 20) * 60));
        $focusSession = (new FocusSession())
            ->setStudent($student)
            ->setLesson($lesson)
            ->setQuiz($quiz)
            ->setStatus(FocusSessionStatus::Active)
            ->setStartedAt(new \DateTimeImmutable())
            ->setDurationSeconds($durationSeconds);

        $entityManager->persist($focusSession);
        $entityManager->flush();

        return $this->render('student/quiz_take.html.twig', [
            'quiz' => $quiz,
            'focusSession' => $focusSession,
            'durationSeconds' => $durationSeconds,
        ]);
    }

    #[Route('/student/focus/{id}/violation', name: 'student_focus_violation', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function logViolation(int $id, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $student = $this->currentStudentProfile();
        if ($student === null) {
            return $this->json(['error' => 'unauthorized'], 401);
        }

        $session = $entityManager->getRepository(FocusSession::class)->find($id);
        if (!$session instanceof FocusSession || $session->getStudent()?->getId() !== $student->getId()) {
            return $this->json(['error' => 'focus session not found'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        $type = FocusViolationType::tryFrom((string) ($payload['type'] ?? '')) ?? FocusViolationType::VisibilityChange;

        $violation = (new FocusViolation())
            ->setFocusSession($session)
            ->setType($type)
            ->setSeverity(max(1, min(5, (int) ($payload['severity'] ?? 2))))
            ->setDetails(isset($payload['details']) ? (string) $payload['details'] : null);

        $entityManager->persist($violation);
        $entityManager->flush();

        return $this->json(['status' => 'ok']);
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
