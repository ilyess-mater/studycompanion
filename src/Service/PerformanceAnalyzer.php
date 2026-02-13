<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Question;
use App\Enum\MasteryStatus;

class PerformanceAnalyzer
{
    /**
     * @param array<int, string> $answersByQuestionId
     * @param array<int, int> $responseTimeByQuestionId
     * @return array{score:float, weakTopics:list<string>, masteryStatus:MasteryStatus, answerStats:list<array{question:Question, answer:string, isCorrect:bool, responseTimeMs:int}>}
     */
    public function analyze(array $questions, array $answersByQuestionId, array $responseTimeByQuestionId): array
    {
        $total = count($questions);
        $correct = 0;
        $weakTopics = [];
        $answerStats = [];

        foreach ($questions as $index => $question) {
            if (!$question instanceof Question) {
                continue;
            }

            $questionId = $question->getId() ?? -($index + 1);

            $answer = trim((string) ($answersByQuestionId[$questionId] ?? ''));
            $responseTimeMs = (int) ($responseTimeByQuestionId[$questionId] ?? 0);
            $isCorrect = $answer !== '' && $answer === $question->getCorrectAnswer();

            if ($isCorrect) {
                ++$correct;
            } else {
                $weakTopics[] = $this->extractWeakTopic($question->getText());
            }

            if ($responseTimeMs > 35000) {
                $weakTopics[] = $this->extractWeakTopic($question->getText());
            }

            $answerStats[] = [
                'question' => $question,
                'answer' => $answer,
                'isCorrect' => $isCorrect,
                'responseTimeMs' => $responseTimeMs,
            ];
        }

        $score = $total > 0 ? round(($correct / $total) * 100, 2) : 0.0;

        $status = match (true) {
            $score >= 85 => MasteryStatus::Mastered,
            $score >= 60 => MasteryStatus::NeedsReview,
            default => MasteryStatus::NotMastered,
        };

        $weakTopics = array_values(array_unique(array_filter($weakTopics)));

        return [
            'score' => $score,
            'weakTopics' => $weakTopics,
            'masteryStatus' => $status,
            'answerStats' => $answerStats,
        ];
    }

    private function extractWeakTopic(string $questionText): string
    {
        $short = trim(mb_substr($questionText, 0, 90));

        return $short === '' ? 'General lesson understanding' : $short;
    }
}
