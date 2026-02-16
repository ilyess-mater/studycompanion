<?php

declare(strict_types=1);

namespace App\Service\Ai;

interface AiProviderInterface
{
    public function hasProvider(): bool;

    /**
     * @return array{
     *     topics:list<string>,
     *     keyConcepts:list<string>,
     *     difficulty:string,
     *     estimatedStudyMinutes:int,
     *     learningObjectives:list<string>
     * }
     */
    public function analyzeLesson(string $text): array;

    /**
     * @param list<string> $weakTopics
     * @return array{
     *     summary:string,
     *     flashcards:list<array{front:string, back:string}>,
     *     explanations:list<string>,
     *     examples:list<string>
     * }
     */
    public function generateMaterials(string $text, array $weakTopics = []): array;

    /**
     * @param array{
     *     title?: string,
     *     subject?: string,
     *     difficulty?: string,
     *     topics?: list<string>,
     *     keyConcepts?: list<string>,
     *     weakTopics?: list<string>
     * } $context
     * @return list<array{text:string, options:list<string>, correctAnswer:string}>
     */
    public function generateQuizQuestions(string $text, int $count = 8, array $context = []): array;

    /**
     * @param list<array{question:\App\Entity\Question, answer:string, isCorrect:bool, responseTimeMs:int}> $answerStats
     * @param array{
     *     lessonTitle?: string,
     *     lessonSubject?: string
     * } $context
     * @return array{
     *     score:float,
     *     weakTopics:list<string>,
     *     explanation:string
     * }
     */
    public function evaluateQuizSubmission(array $answerStats, array $context = []): array;

    /**
     * @param list<string> $weakTopics
     * @return array{summary:string}
     */
    public function summarizeWeakTopics(array $weakTopics, float $score, string $lessonTitle): array;

    /**
     * @return array{tip:string}
     */
    public function generateOnboardingTip(string $role, string $name, ?string $grade): array;

    /**
     * @return array{
     *     tags:list<string>,
     *     hint:string
     * }
     */
    public function tagQuestionConcept(string $questionText, string $lessonContext, string $subject = ''): array;

    /**
     * @return array{
     *     label:string,
     *     confidence:float
     * }
     */
    public function analyzeMisconception(string $questionText, string $correctAnswer, string $studentAnswer): array;
}

