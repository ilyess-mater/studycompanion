<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\AiJobLog;
use App\Entity\Question;
use App\Entity\Quiz;
use App\Message\GenerateQuizMessage;
use App\Service\AiTutorService;
use App\Service\LessonTextExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class GenerateQuizMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LessonTextExtractor $textExtractor,
        private readonly AiTutorService $aiTutorService,
    ) {
    }

    public function __invoke(GenerateQuizMessage $message): void
    {
        $lesson = $this->entityManager->getRepository('App\\Entity\\Lesson')->find($message->lessonId);
        if ($lesson === null) {
            return;
        }

        $jobLog = (new AiJobLog())
            ->setLesson($lesson)
            ->setJobType('quiz_generation')
            ->setPromptHash(hash('sha256', 'quiz-'.$lesson->getId()));

        $start = microtime(true);

        try {
            $rawText = $this->textExtractor->extract($this->toAbsolutePath($lesson->getFilePath()));
            if ($rawText === '') {
                $rawText = (string) ($lesson->getAnalysisData()['sourceExcerpt'] ?? $lesson->getTitle());
            }

            $quiz = (new Quiz())
                ->setLesson($lesson)
                ->setDifficulty($lesson->getDifficulty());

            $questions = $this->aiTutorService->generateQuizQuestions($rawText, 8);
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

    private function toAbsolutePath(string $storedPath): string
    {
        $path = ltrim($storedPath, '/\\');

        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
