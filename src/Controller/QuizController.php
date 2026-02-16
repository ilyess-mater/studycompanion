<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FocusSession;
use App\Entity\FocusViolation;
use App\Entity\PerformanceReport;
use App\Entity\Quiz;
use App\Entity\StudentAnswer;
use App\Entity\StudentProfile;
use App\Enum\FocusSessionStatus;
use App\Enum\FocusViolationType;
use App\Enum\MasteryStatus;
use App\Enum\ThirdPartyProvider;
use App\Enum\ThirdPartyStatus;
use App\Message\GenerateMaterialsMessage;
use App\Message\GenerateQuizMessage;
use App\Service\EntityThirdPartyLinkRecorder;
use App\Service\LearningAiService;
use App\Service\PerformanceAnalyzer;
use App\Service\ThirdPartyMetaService;
use App\Service\YouTubeRecommendationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        LearningAiService $learningAiService,
        YouTubeRecommendationService $youtubeRecommendationService,
        ThirdPartyMetaService $thirdPartyMetaService,
        EntityThirdPartyLinkRecorder $entityThirdPartyLinkRecorder,
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
            $evaluation = $learningAiService->evaluateQuizSubmission($analysis['answerStats'], [
                'lessonTitle' => $lesson?->getTitle() ?? 'Lesson',
                'lessonSubject' => $lesson?->getSubject() ?? 'General',
            ]);
            $evaluatedScore = (float) ($evaluation['data']['score'] ?? $analysis['score']);
            $score = ($evaluatedScore >= 0 && $evaluatedScore <= 100) ? $evaluatedScore : (float) $analysis['score'];
            $weakTopics = $evaluation['data']['weakTopics'] ?? [];
            if (!is_array($weakTopics) || $weakTopics === []) {
                $weakTopics = $analysis['weakTopics'];
            }
            $weakTopics = array_values(array_unique(array_filter(array_map(static fn (mixed $topic): string => trim((string) $topic), $weakTopics))));

            $masteryStatus = match (true) {
                $score >= 85 => MasteryStatus::Mastered,
                $score >= 60 => MasteryStatus::NeedsReview,
                default => MasteryStatus::NotMastered,
            };

            foreach ($analysis['answerStats'] as $row) {
                $studentAnswer = (new StudentAnswer())
                    ->setStudent($student)
                    ->setQuestion($row['question'])
                    ->setAnswer($row['answer'])
                    ->setIsCorrect($row['isCorrect'])
                    ->setResponseTimeMs($row['responseTimeMs']);

                if ($row['isCorrect'] === true) {
                    $thirdPartyMetaService->record(
                        $studentAnswer,
                        $evaluation['provider'],
                        ThirdPartyStatus::Skipped,
                        'Misconception analysis skipped for correct answer.',
                        ['correct' => true],
                    );
                } else {
                    $misconception = $learningAiService->analyzeMisconception(
                        $row['question']->getText(),
                        $row['question']->getCorrectAnswer(),
                        $row['answer'],
                    );
                    $thirdPartyMetaService->record(
                        $studentAnswer,
                        $misconception['provider'],
                        $misconception['status'],
                        $misconception['message'],
                        [
                            'label' => $misconception['data']['label'],
                            'confidence' => $misconception['data']['confidence'],
                        ],
                        null,
                        $misconception['latencyMs'],
                    );
                }
                $entityThirdPartyLinkRecorder->recordLinks($studentAnswer);
                $entityManager->persist($studentAnswer);
            }

            $report = (new PerformanceReport())
                ->setStudent($student)
                ->setLesson($lesson)
                ->setQuiz($quiz)
                ->setQuizScore($score)
                ->setWeakTopics($weakTopics)
                ->setMasteryStatus($masteryStatus);

            $remediation = $learningAiService->summarizeWeakTopics(
                $weakTopics,
                $score,
                $lesson?->getTitle() ?? 'Lesson',
            );
            $thirdPartyMetaService->record(
                $report,
                $remediation['provider'],
                $remediation['status'],
                $remediation['message'],
                [
                    'summary' => $remediation['data']['summary'],
                    'evaluationExplanation' => (string) ($evaluation['data']['explanation'] ?? ''),
                    'weakTopics' => $weakTopics,
                    'score' => $score,
                ],
                null,
                $remediation['latencyMs'],
            );

            $youtubeQuery = trim(($lesson?->getSubject() ?? '').' '.implode(' ', array_slice($weakTopics, 0, 3)));
            $youtubeResults = $youtubeRecommendationService->recommend($youtubeQuery !== '' ? $youtubeQuery : ($lesson?->getTitle() ?? 'study'), 3);
            $thirdPartyMetaService->record(
                $report,
                ThirdPartyProvider::Youtube,
                $youtubeRecommendationService->hasProvider() ? ThirdPartyStatus::Success : ThirdPartyStatus::Fallback,
                'Weak-topic videos linked to performance report.',
                [
                    'query' => $youtubeQuery,
                    'results' => array_map(
                        static fn (array $video): array => [
                            'title' => (string) ($video['title'] ?? ''),
                            'url' => (string) ($video['url'] ?? ''),
                        ],
                        array_slice($youtubeResults, 0, 3),
                    ),
                ],
            );
            $entityThirdPartyLinkRecorder->recordLinks($report);
            $entityManager->persist($report);

            $focusSession
                ->setStatus(FocusSessionStatus::Completed)
                ->setEndedAt(new \DateTimeImmutable());

            $entityManager->flush();

            if ($weakTopics !== []) {
                $existingVersionCount = $entityManager->getRepository('App\\Entity\\StudyMaterial')->count(['lesson' => $lesson]);
                $nextVersion = max(2, (int) floor($existingVersionCount / 4) + 1);
                $messageBus->dispatch(new GenerateMaterialsMessage((int) $lesson->getId(), $weakTopics, $nextVersion));
                $messageBus->dispatch(new GenerateQuizMessage((int) $lesson->getId(), $weakTopics));
            }

            $this->addFlash('success', sprintf('Quiz completed. Score: %.2f%%', $score));

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

    #[Route('/student/focus/{id}/violation', name: 'student_focus_violation', requirements: ['id' => '\\d+'], methods: ['POST'])]
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
