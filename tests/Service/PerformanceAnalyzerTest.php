<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Question;
use App\Enum\MasteryStatus;
use App\Service\PerformanceAnalyzer;
use PHPUnit\Framework\TestCase;

class PerformanceAnalyzerTest extends TestCase
{
    public function testAnalyzeComputesMasteryAndWeakTopics(): void
    {
        $analyzer = new PerformanceAnalyzer();

        $q1 = $this->makeQuestion(1, 'What is photosynthesis?', ['A', 'B', 'C', 'D'], 'A');
        $q2 = $this->makeQuestion(2, 'Where does Calvin cycle occur?', ['Leaf', 'Stroma', 'Root', 'Stem'], 'Stroma');
        $q3 = $this->makeQuestion(3, 'Which gas enters the leaf?', ['O2', 'CO2', 'He', 'Ne'], 'CO2');

        $result = $analyzer->analyze(
            [$q1, $q2, $q3],
            [1 => 'A', 2 => 'Leaf', 3 => 'CO2'],
            [1 => 5000, 2 => 42000, 3 => 6000],
        );

        self::assertSame(66.67, $result['score']);
        self::assertSame(MasteryStatus::NeedsReview, $result['masteryStatus']);
        self::assertNotEmpty($result['weakTopics']);
        self::assertCount(3, $result['answerStats']);
    }

    private function makeQuestion(int $id, string $text, array $options, string $correct): Question
    {
        $question = (new Question())
            ->setText($text)
            ->setOptions($options)
            ->setCorrectAnswer($correct);

        $reflection = new \ReflectionClass($question);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($question, $id);

        return $question;
    }
}
