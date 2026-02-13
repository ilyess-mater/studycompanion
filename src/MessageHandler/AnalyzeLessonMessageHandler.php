<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\AiJobLog;
use App\Enum\ProcessingStatus;
use App\Message\AnalyzeLessonMessage;
use App\Message\GenerateMaterialsMessage;
use App\Message\GenerateQuizMessage;
use App\Service\AiTutorService;
use App\Service\LessonTextExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class AnalyzeLessonMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LessonTextExtractor $textExtractor,
        private readonly AiTutorService $aiTutorService,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(AnalyzeLessonMessage $message): void
    {
        $lesson = $this->entityManager->getRepository('App\\Entity\\Lesson')->find($message->lessonId);
        if ($lesson === null) {
            return;
        }

        $jobLog = (new AiJobLog())
            ->setLesson($lesson)
            ->setJobType('analysis')
            ->setPromptHash(hash('sha256', $lesson->getTitle().$lesson->getSubject()));

        $start = microtime(true);

        try {
            $lesson->setProcessingStatus(ProcessingStatus::Running);
            $absolutePath = $this->toAbsolutePath($lesson->getFilePath());
            $rawText = $this->textExtractor->extract($absolutePath);
            if ($rawText === '') {
                $rawText = $lesson->getTitle().' '.$lesson->getSubject();
            }

            $analysis = $this->aiTutorService->analyzeLesson($rawText);

            $lesson
                ->setDifficulty($analysis['difficulty'])
                ->setEstimatedStudyMinutes($analysis['estimatedStudyMinutes'])
                ->setLearningObjectives($analysis['learningObjectives'])
                ->setAnalysisData([
                    'topics' => $analysis['topics'],
                    'keyConcepts' => $analysis['keyConcepts'],
                    'sourceExcerpt' => mb_substr($rawText, 0, 1200),
                ])
                ->setProcessingStatus($this->aiTutorService->hasProvider() ? ProcessingStatus::Done : ProcessingStatus::PartialFallback);

            $jobLog
                ->setProviderStatus('DONE')
                ->setUsedFallback(!$this->aiTutorService->hasProvider())
                ->setLatencyMs((int) ((microtime(true) - $start) * 1000));

            $this->entityManager->persist($jobLog);
            $this->entityManager->flush();

            $this->messageBus->dispatch(new GenerateMaterialsMessage($lesson->getId() ?? 0));
            $this->messageBus->dispatch(new GenerateQuizMessage($lesson->getId() ?? 0));
        } catch (\Throwable $exception) {
            $lesson->setProcessingStatus(ProcessingStatus::Failed);
            $jobLog
                ->setProviderStatus('FAILED')
                ->setUsedFallback(true)
                ->setErrorMessage($exception->getMessage())
                ->setLatencyMs((int) ((microtime(true) - $start) * 1000));
            $this->entityManager->persist($jobLog);
            $this->entityManager->flush();
        }
    }

    private function toAbsolutePath(string $storedPath): string
    {
        $path = ltrim($storedPath, '/\\');

        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
