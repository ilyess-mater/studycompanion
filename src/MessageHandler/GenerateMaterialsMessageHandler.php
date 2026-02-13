<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\AiJobLog;
use App\Entity\StudyMaterial;
use App\Entity\VideoRecommendation;
use App\Enum\MaterialType;
use App\Enum\ProcessingStatus;
use App\Message\GenerateMaterialsMessage;
use App\Service\AiTutorService;
use App\Service\LessonTextExtractor;
use App\Service\YouTubeRecommendationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class GenerateMaterialsMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LessonTextExtractor $textExtractor,
        private readonly AiTutorService $aiTutorService,
        private readonly YouTubeRecommendationService $youtubeRecommendationService,
    ) {
    }

    public function __invoke(GenerateMaterialsMessage $message): void
    {
        $lesson = $this->entityManager->getRepository('App\\Entity\\Lesson')->find($message->lessonId);
        if ($lesson === null) {
            return;
        }

        $jobLog = (new AiJobLog())
            ->setLesson($lesson)
            ->setJobType('material_generation')
            ->setPromptHash(hash('sha256', 'materials-'.$lesson->getId().'-'.implode('|', $message->weakTopics)));

        $start = microtime(true);

        try {
            $rawText = $this->textExtractor->extract($this->toAbsolutePath($lesson->getFilePath()));
            if ($rawText === '') {
                $rawText = (string) ($lesson->getAnalysisData()['sourceExcerpt'] ?? $lesson->getTitle());
            }

            $materials = $this->aiTutorService->generateMaterials($rawText, $message->weakTopics);

            $summary = (new StudyMaterial())
                ->setLesson($lesson)
                ->setType(MaterialType::Summary)
                ->setSummary($materials['summary'])
                ->setContent($materials['summary'])
                ->setVersion($message->version);

            $flashcards = (new StudyMaterial())
                ->setLesson($lesson)
                ->setType(MaterialType::Flashcards)
                ->setFlashcards($materials['flashcards'])
                ->setContent(json_encode($materials['flashcards'], JSON_PRETTY_PRINT) ?: '[]')
                ->setVersion($message->version);

            $explanation = (new StudyMaterial())
                ->setLesson($lesson)
                ->setType(MaterialType::Explanation)
                ->setContent(implode("\n\n", $materials['explanations']))
                ->setVersion($message->version);

            $example = (new StudyMaterial())
                ->setLesson($lesson)
                ->setType(MaterialType::Example)
                ->setContent(implode("\n\n", $materials['examples']))
                ->setVersion($message->version);

            $this->entityManager->persist($summary);
            $this->entityManager->persist($flashcards);
            $this->entityManager->persist($explanation);
            $this->entityManager->persist($example);

            $query = trim($lesson->getSubject().' '.$lesson->getTitle().' '.implode(' ', $message->weakTopics));
            foreach ($this->youtubeRecommendationService->recommend($query !== '' ? $query : $lesson->getTitle(), 5) as $videoData) {
                $video = (new VideoRecommendation())
                    ->setLesson($lesson)
                    ->setStudyMaterial($summary)
                    ->setTitle($videoData['title'])
                    ->setUrl($videoData['url'])
                    ->setChannelName($videoData['channelName'])
                    ->setScore($videoData['score']);
                $this->entityManager->persist($video);
            }

            if ($lesson->getProcessingStatus() !== ProcessingStatus::Failed) {
                $lesson->setProcessingStatus($this->aiTutorService->hasProvider() ? ProcessingStatus::Done : ProcessingStatus::PartialFallback);
            }

            $jobLog
                ->setProviderStatus('DONE')
                ->setUsedFallback(!$this->aiTutorService->hasProvider())
                ->setLatencyMs((int) ((microtime(true) - $start) * 1000));
            $this->entityManager->persist($jobLog);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
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
