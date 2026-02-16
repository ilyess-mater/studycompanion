<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Question;

class LocalNlpAiProvider implements AiProviderInterface
{
    public function hasProvider(): bool
    {
        return true;
    }

    public function analyzeLesson(string $text): array
    {
        $chunks = $this->sentenceChunks($text);
        $topics = array_slice($chunks, 0, 6);
        $keywords = $this->extractKeywords($text, 12);
        $keyConcepts = array_slice(array_values(array_unique(array_merge($topics, $keywords))), 0, 8);
        $difficulty = mb_strlen($text) > 8000 ? 'HARD' : (mb_strlen($text) > 3000 ? 'MEDIUM' : 'EASY');

        return [
            'topics' => $topics === [] ? ['Core Lesson Topic'] : $topics,
            'keyConcepts' => $keyConcepts === [] ? ['Definition', 'Application', 'Practice'] : $keyConcepts,
            'difficulty' => $difficulty,
            'estimatedStudyMinutes' => max(20, min(120, (int) ceil(max(300, mb_strlen($text)) / 240))),
            'learningObjectives' => [
                'Understand the main lesson concepts',
                'Apply concepts to practical examples',
                'Answer comprehension questions confidently',
            ],
        ];
    }

    public function generateMaterials(string $text, array $weakTopics = []): array
    {
        $sentences = $this->sentenceChunks($text);
        $summary = implode(' ', array_slice($sentences, 0, 5));
        if ($summary === '') {
            $summary = 'This lesson introduces core concepts and practical understanding goals.';
        }

        $seedTopics = $weakTopics === [] ? array_slice($this->extractKeywords($text, 10), 0, 5) : array_slice($weakTopics, 0, 5);
        if ($seedTopics === []) {
            $seedTopics = array_slice($sentences, 0, 4);
        }

        $flashcards = [];
        foreach ($seedTopics as $topic) {
            $flashcards[] = [
                'front' => $topic,
                'back' => 'Review the definition and one practical example for: '.$topic,
            ];
        }

        return [
            'summary' => $summary,
            'flashcards' => $flashcards,
            'explanations' => [
                'Break the lesson into smaller ideas and connect each idea with one real-life use.',
                'Compare similar concepts and identify the differences clearly.',
                $weakTopics !== [] ? 'Prioritize weak topics first: '.implode(', ', array_slice($weakTopics, 0, 4)).'.' : 'Start with the lesson objectives and map each objective to one concept.',
            ],
            'examples' => [
                'Solve a simple case using the lesson rule step by step.',
                'Explain the concept to another student using your own words.',
            ],
        ];
    }

    public function generateQuizQuestions(string $text, int $count = 8, array $context = []): array
    {
        $sentences = $this->sentenceChunks($text);
        $topicPool = $this->normalizeStringList($context['topics'] ?? []);
        $keyConceptPool = $this->normalizeStringList($context['keyConcepts'] ?? []);
        $weakTopicPool = $this->normalizeStringList($context['weakTopics'] ?? []);
        $keywordPool = $this->extractKeywords($text, 24);

        $focusPool = array_values(array_unique(array_merge(
            $weakTopicPool,
            $topicPool,
            $keyConceptPool,
            $keywordPool,
            array_slice($sentences, 0, 20),
        )));

        if ($focusPool === []) {
            $fallbackFocus = trim((string) ($context['title'] ?? 'Core lesson concept'));
            $focusPool = [$fallbackFocus !== '' ? $fallbackFocus : 'Core lesson concept'];
        }

        $title = trim((string) ($context['title'] ?? 'this lesson'));
        $subject = trim((string) ($context['subject'] ?? 'the subject'));
        $templates = [
            'According to %s, what best explains "%s" in relation to %s?',
            'In %s, which statement is most accurate about "%s"?',
            'When applying %s concepts, how should "%s" be interpreted?',
            'Within %s, which option correctly describes "%s"?',
        ];

        $questions = [];
        for ($i = 0; $i < max(1, $count); ++$i) {
            $focus = $focusPool[$i % count($focusPool)];
            $next = $focusPool[($i + 1) % count($focusPool)];
            $alt = $focusPool[($i + 2) % count($focusPool)];
            $subjectKeyword = $keywordPool[$i % max(1, count($keywordPool))] ?? $subject;

            $correct = sprintf('It links "%s" to %s outcomes in %s.', mb_substr($focus, 0, 48), mb_substr($subjectKeyword, 0, 32), $subject);
            $questionText = sprintf(
                $templates[$i % count($templates)],
                $title,
                mb_substr($focus, 0, 65),
                mb_substr($subjectKeyword, 0, 40),
            );

            $questions[] = [
                'text' => $questionText,
                'options' => [
                    $correct,
                    sprintf('It ignores "%s" and only repeats "%s".', mb_substr($focus, 0, 30), mb_substr($next, 0, 30)),
                    sprintf('It replaces "%s" with an unrelated idea: "%s".', mb_substr($focus, 0, 30), mb_substr($alt, 0, 30)),
                    sprintf('It is unrelated to %s lesson content.', $subject),
                ],
                'correctAnswer' => $correct,
            ];
        }

        return $this->mergeUniqueQuestions($questions, $count);
    }

    public function evaluateQuizSubmission(array $answerStats, array $context = []): array
    {
        $total = count($answerStats);
        $correct = 0;
        $weakFrequency = [];

        foreach ($answerStats as $row) {
            if (!is_array($row)) {
                continue;
            }

            $question = $row['question'] ?? null;
            $isCorrect = (bool) ($row['isCorrect'] ?? false);
            $responseTimeMs = max(0, (int) ($row['responseTimeMs'] ?? 0));

            if ($isCorrect) {
                ++$correct;
            }

            if ($isCorrect && $responseTimeMs < 35000) {
                continue;
            }

            $topics = $this->topicsFromQuestion($question instanceof Question ? $question : null);
            foreach ($topics as $topic) {
                $weakFrequency[$topic] = ($weakFrequency[$topic] ?? 0) + ($isCorrect ? 1 : 2);
            }
        }

        $score = $total > 0 ? round(($correct / $total) * 100, 2) : 0.0;
        arsort($weakFrequency);
        $weakTopics = array_slice(array_keys($weakFrequency), 0, 6);
        if ($weakTopics === [] && $score < 100.0) {
            $weakTopics = ['General lesson understanding'];
        }

        $lessonTitle = trim((string) ($context['lessonTitle'] ?? 'this lesson'));
        $summary = $this->summarizeWeakTopics($weakTopics, $score, $lessonTitle);

        return [
            'score' => $score,
            'weakTopics' => $weakTopics,
            'explanation' => $summary['summary'],
        ];
    }

    public function summarizeWeakTopics(array $weakTopics, float $score, string $lessonTitle): array
    {
        $title = trim($lessonTitle) !== '' ? $lessonTitle : 'this lesson';
        $focus = $weakTopics === []
            ? 'core concepts and short-form revision'
            : implode(', ', array_slice($weakTopics, 0, 4));

        return [
            'summary' => sprintf(
                'For %s (score %.2f%%), focus next on %s, then retake an adaptive quiz.',
                $title,
                $score,
                $focus,
            ),
        ];
    }

    public function generateOnboardingTip(string $role, string $name, ?string $grade): array
    {
        $tip = $role === 'teacher'
            ? 'Create one group, monitor weak-topic trends weekly, and post lesson-specific feedback.'
            : 'Upload one lesson, review generated materials, then complete the quiz and fix weak topics.';

        if ($role === 'student' && $grade !== null && trim($grade) !== '') {
            $tip = sprintf('Grade %s plan: upload one lesson, review summary + flashcards, then take the adaptive quiz.', trim($grade));
        }

        return ['tip' => $tip];
    }

    public function tagQuestionConcept(string $questionText, string $lessonContext, string $subject = ''): array
    {
        $tags = array_slice($this->extractKeywords($questionText.' '.$lessonContext.' '.$subject, 5), 0, 3);
        if ($tags === []) {
            $tags = ['core-concept'];
        }

        return [
            'tags' => $tags,
            'hint' => 'Review the concept definition before selecting the best option.',
        ];
    }

    public function analyzeMisconception(string $questionText, string $correctAnswer, string $studentAnswer): array
    {
        $normalizedCorrect = mb_strtolower(trim($correctAnswer));
        $normalizedStudent = mb_strtolower(trim($studentAnswer));

        $label = 'Needs concept reinforcement';
        $confidence = 0.35;

        if ($normalizedStudent === '') {
            $label = 'No answer selected';
            $confidence = 0.9;
        } elseif ($normalizedStudent === $normalizedCorrect) {
            $label = 'Correct understanding';
            $confidence = 0.99;
        } elseif (str_contains($normalizedCorrect, $normalizedStudent) || str_contains($normalizedStudent, $normalizedCorrect)) {
            $label = 'Partially correct but incomplete reasoning';
            $confidence = 0.6;
        } elseif (mb_strlen($questionText) > 0 && mb_strlen($studentAnswer) > 0) {
            $label = 'Confused related concepts';
            $confidence = 0.5;
        }

        return [
            'label' => $label,
            'confidence' => $confidence,
        ];
    }

    /**
     * @return list<string>
     */
    private function topicsFromQuestion(?Question $question): array
    {
        if (!$question instanceof Question) {
            return ['General lesson understanding'];
        }

        $meta = $question->getThirdPartyMeta();
        if (is_array($meta)) {
            $integrations = $meta['integrations'] ?? null;
            if (is_array($integrations)) {
                foreach ($integrations as $integration) {
                    if (!is_array($integration)) {
                        continue;
                    }
                    $payload = $integration['payload'] ?? null;
                    if (!is_array($payload)) {
                        continue;
                    }
                    $tags = $this->normalizeStringList($payload['tags'] ?? []);
                    if ($tags !== []) {
                        return array_slice($tags, 0, 3);
                    }
                }
            }
        }

        $snippet = trim(mb_substr($question->getText(), 0, 72));

        return [$snippet !== '' ? $snippet : 'General lesson understanding'];
    }

    /**
     * @return list<array{text:string, options:list<string>, correctAnswer:string}>
     */
    private function mergeUniqueQuestions(array $questions, int $count): array
    {
        $result = [];
        $seen = [];

        foreach ($questions as $row) {
            if (!is_array($row)) {
                continue;
            }

            $text = trim((string) ($row['text'] ?? ''));
            $correct = trim((string) ($row['correctAnswer'] ?? ''));
            $options = $this->normalizeStringList($row['options'] ?? []);

            if ($text === '' || $correct === '' || $options === []) {
                continue;
            }

            if (!in_array($correct, $options, true)) {
                array_unshift($options, $correct);
            }

            $options = array_values(array_unique(array_slice($options, 0, 4)));
            if (!in_array($correct, $options, true)) {
                $options[0] = $correct;
            }

            if (count($options) < 2) {
                continue;
            }

            $key = mb_strtolower(preg_replace('/\s+/', ' ', $text) ?? '');
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $result[] = [
                'text' => $text,
                'options' => $options,
                'correctAnswer' => $correct,
            ];

            if (count($result) >= $count) {
                break;
            }
        }

        return $result;
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

    /**
     * @return list<string>
     */
    private function extractKeywords(string $text, int $limit = 20): array
    {
        $normalized = mb_strtolower($text);
        preg_match_all('/[a-z][a-z0-9\-]{3,}/u', $normalized, $matches);
        $words = $matches[0] ?? [];

        if ($words === []) {
            return [];
        }

        $stopWords = [
            'about', 'after', 'again', 'against', 'because', 'before', 'between',
            'could', 'every', 'first', 'from', 'have', 'into', 'lesson', 'more',
            'other', 'should', 'their', 'there', 'these', 'those', 'through',
            'under', 'using', 'what', 'when', 'where', 'which', 'while', 'with',
            'your', 'this', 'that', 'they', 'them', 'were', 'will', 'than',
        ];

        $frequency = [];
        foreach ($words as $word) {
            if (in_array($word, $stopWords, true)) {
                continue;
            }
            $frequency[$word] = ($frequency[$word] ?? 0) + 1;
        }

        arsort($frequency);

        return array_slice(array_keys($frequency), 0, $limit);
    }

    /**
     * @return list<string>
     */
    private function sentenceChunks(string $text): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($text)) ?: [];
        $output = [];
        foreach ($sentences as $sentence) {
            $trimmed = trim($sentence);
            if ($trimmed !== '' && mb_strlen($trimmed) > 12) {
                $output[] = mb_substr($trimmed, 0, 120);
            }
        }

        return array_values(array_unique($output));
    }
}

