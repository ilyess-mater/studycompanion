<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AiJobLog;
use App\Entity\Lesson;
use App\Entity\Question;
use App\Entity\Quiz;
use App\Entity\StudyMaterial;
use App\Entity\VideoRecommendation;
use App\Enum\LessonDifficulty;
use App\Enum\MaterialType;
use App\Enum\ProcessingStatus;
use App\Enum\ThirdPartyStatus;
use Doctrine\ORM\EntityManagerInterface;

class LessonWorkflowService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LessonTextExtractor $textExtractor,
        private readonly LearningAiService $learningAiService,
        private readonly YouTubeRecommendationService $youtubeRecommendationService,
        private readonly ThirdPartyMetaService $thirdPartyMetaService,
        private readonly EntityThirdPartyLinkRecorder $entityThirdPartyLinkRecorder,
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

            $analysisResult = $this->learningAiService->analyzeLesson($rawText);
            $analysis = $analysisResult['data'];
            $difficulty = LessonDifficulty::tryFrom((string) ($analysis['difficulty'] ?? '')) ?? LessonDifficulty::Medium;

            $lesson
                ->setDifficulty($difficulty)
                ->setEstimatedStudyMinutes((int) $analysis['estimatedStudyMinutes'])
                ->setLearningObjectives((array) $analysis['learningObjectives'])
                ->setAnalysisData([
                    'topics' => (array) $analysis['topics'],
                    'keyConcepts' => (array) $analysis['keyConcepts'],
                    'sourceExcerpt' => mb_substr($rawText, 0, 1200),
                ])
                ->setProcessingStatus($analysisResult['fallbackUsed'] ? ProcessingStatus::PartialFallback : ProcessingStatus::Done);

            $this->thirdPartyMetaService->record(
                $lesson,
                $analysisResult['provider'],
                $analysisResult['status'],
                $analysisResult['message'],
                [
                    'topicsCount' => count($analysis['topics']),
                    'keyConceptsCount' => count($analysis['keyConcepts']),
                    'estimatedStudyMinutes' => $analysis['estimatedStudyMinutes'],
                    'fallbackUsed' => $analysisResult['fallbackUsed'],
                ],
                null,
                (int) $analysisResult['latencyMs'],
            );
            $this->entityThirdPartyLinkRecorder->recordLinks($lesson);

            $jobLog
                ->setProviderStatus('DONE')
                ->setUsedFallback((bool) $analysisResult['fallbackUsed'])
                ->setLatencyMs((int) ((microtime(true) - $start) * 1000));

            $this->entityManager->persist($jobLog);
            $this->entityManager->flush();

            return $lesson;
        } catch (\Throwable $exception) {
            $lesson->setProcessingStatus(ProcessingStatus::Failed);
            $this->thirdPartyMetaService->record(
                $lesson,
                $this->learningAiService->providerState()['active'],
                ThirdPartyStatus::Failed,
                'Lesson analysis failed: '.$exception->getMessage(),
                ['error' => $exception->getMessage()],
            );
            $this->entityThirdPartyLinkRecorder->recordLinks($lesson);
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
            $materialsResult = $this->learningAiService->generateMaterials($rawText, $weakTopics);
            $materials = $materialsResult['data'];

            $summary = (new StudyMaterial())
                ->setLesson($lesson)
                ->setType(MaterialType::Summary)
                ->setSummary((string) $materials['summary'])
                ->setContent((string) $materials['summary'])
                ->setVersion($version);

            $flashcards = (new StudyMaterial())
                ->setLesson($lesson)
                ->setType(MaterialType::Flashcards)
                ->setFlashcards((array) $materials['flashcards'])
                ->setContent(json_encode($materials['flashcards'], JSON_PRETTY_PRINT) ?: '[]')
                ->setVersion($version);

            $explanation = (new StudyMaterial())
                ->setLesson($lesson)
                ->setType(MaterialType::Explanation)
                ->setContent(implode("\n\n", (array) $materials['explanations']))
                ->setVersion($version);

            $example = (new StudyMaterial())
                ->setLesson($lesson)
                ->setType(MaterialType::Example)
                ->setContent(implode("\n\n", (array) $materials['examples']))
                ->setVersion($version);

            $this->entityManager->persist($summary);
            $this->entityManager->persist($flashcards);
            $this->entityManager->persist($explanation);
            $this->entityManager->persist($example);

            $query = trim($lesson->getSubject().' '.$lesson->getTitle().' '.implode(' ', $weakTopics));
            $videoQuery = $query !== '' ? $query : $lesson->getTitle();
            $recommendations = $this->youtubeRecommendationService->recommend($videoQuery, 5);
            foreach ($recommendations as $videoData) {
                $video = (new VideoRecommendation())
                    ->setLesson($lesson)
                    ->setStudyMaterial($summary)
                    ->setTitle($videoData['title'])
                    ->setUrl($videoData['url'])
                    ->setChannelName($videoData['channelName'])
                    ->setScore($videoData['score']);
                $this->entityManager->persist($video);
            }

            $aiStatus = $materialsResult['status'];
            $youTubeStatus = $this->youtubeRecommendationService->hasProvider() ? ThirdPartyStatus::Success : ThirdPartyStatus::Fallback;
            $videoPayload = [
                'query' => $videoQuery,
                'count' => count($recommendations),
                'top' => array_map(
                    static fn (array $video): array => [
                        'title' => (string) ($video['title'] ?? ''),
                        'url' => (string) ($video['url'] ?? ''),
                    ],
                    array_slice($recommendations, 0, 3),
                ),
            ];

            foreach ([$summary, $flashcards, $explanation, $example] as $material) {
                $this->thirdPartyMetaService->record(
                    $material,
                    $materialsResult['provider'],
                    $aiStatus,
                    $materialsResult['message'],
                    [
                        'type' => $material->getType()->value,
                        'version' => $version,
                        'fallbackUsed' => $materialsResult['fallbackUsed'],
                    ],
                );
                $this->thirdPartyMetaService->record(
                    $material,
                    \App\Enum\ThirdPartyProvider::Youtube,
                    $youTubeStatus,
                    'Video recommendations linked.',
                    $videoPayload,
                );
                $this->entityThirdPartyLinkRecorder->recordLinks($material);
            }

            $this->thirdPartyMetaService->record(
                $lesson,
                \App\Enum\ThirdPartyProvider::Youtube,
                $youTubeStatus,
                'Lesson recommendations updated.',
                $videoPayload,
            );
            $this->entityThirdPartyLinkRecorder->recordLinks($lesson);

            if ($lesson->getProcessingStatus() !== ProcessingStatus::Failed) {
                $lesson->setProcessingStatus($materialsResult['fallbackUsed'] ? ProcessingStatus::PartialFallback : ProcessingStatus::Done);
            }

            $jobLog
                ->setProviderStatus('DONE')
                ->setUsedFallback((bool) $materialsResult['fallbackUsed'])
                ->setLatencyMs((int) ((microtime(true) - $start) * 1000));

            $this->entityManager->persist($jobLog);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->thirdPartyMetaService->record(
                $lesson,
                $this->learningAiService->providerState()['active'],
                ThirdPartyStatus::Failed,
                'Material generation failed: '.$exception->getMessage(),
                ['error' => $exception->getMessage()],
            );
            $this->entityThirdPartyLinkRecorder->recordLinks($lesson);
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

            $questionsResult = $this->learningAiService->generateQuizQuestions($rawText, 8, [
                'title' => $lesson->getTitle(),
                'subject' => $lesson->getSubject(),
                'difficulty' => $lesson->getDifficulty()->value,
                'topics' => $analysisTopics,
                'keyConcepts' => $analysisConcepts,
                'weakTopics' => $focusTopics,
            ]);
            $questions = $questionsResult['data'];

            foreach ($questions as $row) {
                $question = (new Question())
                    ->setQuiz($quiz)
                    ->setText($row['text'])
                    ->setOptions($row['options'])
                    ->setCorrectAnswer($row['correctAnswer']);

                $tagging = $this->learningAiService->tagQuestionConcept(
                    $row['text'],
                    $rawText,
                    $lesson->getSubject(),
                );
                $this->thirdPartyMetaService->record(
                    $question,
                    $tagging['provider'],
                    $tagging['status'],
                    $tagging['message'],
                    [
                        'tags' => $tagging['data']['tags'],
                        'difficultyHint' => $tagging['data']['hint'],
                    ],
                    null,
                    $tagging['latencyMs'],
                );
                $this->entityThirdPartyLinkRecorder->recordLinks($question);
                $quiz->addQuestion($question);
            }

            $this->thirdPartyMetaService->record(
                $quiz,
                $questionsResult['provider'],
                $questionsResult['status'],
                $questionsResult['message'],
                [
                    'questionCount' => count($questions),
                    'focusTopics' => $focusTopics,
                    'fallbackUsed' => $questionsResult['fallbackUsed'],
                ],
            );
            $this->entityThirdPartyLinkRecorder->recordLinks($quiz);

            $this->entityManager->persist($quiz);
            $jobLog
                ->setProviderStatus('DONE')
                ->setUsedFallback((bool) $questionsResult['fallbackUsed'])
                ->setLatencyMs((int) ((microtime(true) - $start) * 1000));
            $this->entityManager->persist($jobLog);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            if (isset($quiz) && $quiz instanceof Quiz) {
                $this->thirdPartyMetaService->record(
                    $quiz,
                    $this->learningAiService->providerState()['active'],
                    ThirdPartyStatus::Failed,
                    'Quiz generation failed: '.$exception->getMessage(),
                    ['error' => $exception->getMessage()],
                );
                $this->entityThirdPartyLinkRecorder->recordLinks($quiz);
            }
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
