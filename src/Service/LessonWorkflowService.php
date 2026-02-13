<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AiJobLog;
use App\Entity\Lesson;
use App\Entity\Question;
use App\Entity\Quiz;
use App\Entity\StudyMaterial;
use App\Entity\VideoRecommendation;
use App\Enum\MaterialType;
use App\Enum\ProcessingStatus;
use Doctrine\ORM\EntityManagerInterface;

class LessonWorkflowService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LessonTextExtractor $textExtractor,
        private readonly AiTutorService $aiTutorService,
        private readonly YouTubeRecommendationService $youtubeRecommendationService,
    ) {
    }

    public function processUploadedLesson(int $lessonId): void
    {
        $lesson = $this->analyzeLesson($lessonId);
        if (!$lesson instanceof Lesson) {
            return;
        }

        $this->generateMaterials($lessonId);
        $this->generateQuiz($lessonId);
    }

    public function analyzeLesson(int $lessonId): ?Lesson
    {
        $lesson = $this->entityManager->getRepository(Lesson::class)->find($lessonId);
        if (!$lesson instanceof Lesson) {
            return null;
        }

        $jobLog = (new AiJobLog())
            ->setLesson($lesson)
            ->setJobType('analysis')
            ->setPromptHash(hash('sha256', $lesson->getTitle().$lesson->getSubject()));

        $start = microtime(true);

        try {
            $lesson->setProcessingStatus(ProcessingStatus::Running);
            $rawText = $this->extractLessonText($lesson);

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

            return $lesson;
        } catch (\Throwable $exception) {
            $lesson->setProcessingStatus(ProcessingStatus::Failed);
            $jobLog
                ->setProviderStatus('FAILED')
                ->setUsedFallback(true)
                ->setErrorMessage($exception->getMessage())
                ->setLatencyMs((int) ((microtime(true) - $start) * 1000));
            $this->entityManager->persist($jobLog);
            $this->entityManager->flush();

            return null;
        }
    }

    /**
     * @param list<string> $weakTopics
     */
    public function generateMaterials(int $lessonId, array $weakTopics = [], int $version = 1): void
    {
        $lesson = $this->entityManager->getRepository(Lesson::class)->find($lessonId);
        if (!$lesson instanceof Lesson) {
            return;
        }

        $jobLog = (new AiJobLog())
            ->setLesson($lesson)
            ->setJobType('material_generation')
            ->setPromptHash(hash('sha256', 'materials-'.$lesson->getId().'-'.implode('|', $weakTopics).'-'.$version));

        $start = microtime(true);

        try {
            $rawText = $this->extractLessonText($lesson);
            $materials = $this->aiTutorService->generateMaterials($rawText, $weakTopics);

            $summary = (new StudyMaterial())
                ->setLesson($lesson)
                ->setType(MaterialType::Summary)
                ->setSummary($materials['summary'])
                ->setContent($materials['summary'])
                ->setVersion($version);

            $flashcards = (new StudyMaterial())
                ->setLesson($lesson)
                ->setType(MaterialType::Flashcards)
                ->setFlashcards($materials['flashcards'])
                ->setContent(json_encode($materials['flashcards'], JSON_PRETTY_PRINT) ?: '[]')
                ->setVersion($version);

            $explanation = (new StudyMaterial())
                ->setLesson($lesson)
                ->setType(MaterialType::Explanation)
                ->setContent(implode("\n\n", $materials['explanations']))
                ->setVersion($version);

            $example = (new StudyMaterial())
                ->setLesson($lesson)
                ->setType(MaterialType::Example)
                ->setContent(implode("\n\n", $materials['examples']))
                ->setVersion($version);

            $this->entityManager->persist($summary);
            $this->entityManager->persist($flashcards);
            $this->entityManager->persist($explanation);
            $this->entityManager->persist($example);

            $query = trim($lesson->getSubject().' '.$lesson->getTitle().' '.implode(' ', $weakTopics));
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

    /**
     * @param list<string> $focusTopics
     */
    public function generateQuiz(int $lessonId, array $focusTopics = []): void
    {
        $lesson = $this->entityManager->getRepository(Lesson::class)->find($lessonId);
        if (!$lesson instanceof Lesson) {
            return;
        }

        $focusTopics = $this->normalizeStringList($focusTopics);
        $analysisData = $lesson->getAnalysisData() ?? [];
        $analysisTopics = $this->normalizeStringList($analysisData['topics'] ?? []);
        $analysisConcepts = $this->normalizeStringList($analysisData['keyConcepts'] ?? []);

        $jobLog = (new AiJobLog())
            ->setLesson($lesson)
            ->setJobType('quiz_generation')
            ->setPromptHash(hash('sha256', 'quiz-'.$lesson->getId().'-'.implode('|', $focusTopics)));

        $start = microtime(true);

        try {
            $rawText = $this->extractLessonText($lesson);
            $quiz = (new Quiz())
                ->setLesson($lesson)
                ->setDifficulty($lesson->getDifficulty());

            $questions = $this->aiTutorService->generateQuizQuestions($rawText, 8, [
                'title' => $lesson->getTitle(),
                'subject' => $lesson->getSubject(),
                'difficulty' => $lesson->getDifficulty()->value,
                'topics' => $analysisTopics,
                'keyConcepts' => $analysisConcepts,
                'weakTopics' => $focusTopics,
            ]);

            foreach ($questions as $row) {
                $question = (new Question())
                    ->setQuiz($quiz)
                    ->setText($row['text'])
                    ->setOptions($row['options'])
                    ->setCorrectAnswer($row['correctAnswer']);
                $quiz->addQuestion($question);
            }

            $this->entityManager->persist($quiz);
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

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $output = [];
        foreach ($input as $item) {
            $value = trim((string) $item);
            if ($value !== '') {
                $output[] = $value;
            }
        }

        return array_values(array_unique($output));
    }

    private function extractLessonText(Lesson $lesson): string
    {
        $rawText = $this->textExtractor->extract($this->toAbsolutePath($lesson->getFilePath()));

        if ($rawText === '') {
            return (string) ($lesson->getAnalysisData()['sourceExcerpt'] ?? ($lesson->getTitle().' '.$lesson->getSubject()));
        }

        return $rawText;
    }

    private function toAbsolutePath(string $storedPath): string
    {
        $path = ltrim($storedPath, '/\\');

        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
