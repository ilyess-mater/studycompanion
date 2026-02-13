<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\LessonDifficulty;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiTutorService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $openAiApiKey,
        private readonly string $openAiModel,
    ) {
    }

    public function hasProvider(): bool
    {
        return $this->openAiApiKey !== '';
    }

    /**
     * @return array{topics:list<string>, keyConcepts:list<string>, difficulty:LessonDifficulty, estimatedStudyMinutes:int, learningObjectives:list<string>}
     */
    public function analyzeLesson(string $text): array
    {
        $trimmed = $this->limit($text, 14000);

        $prompt = <<<PROMPT
Analyze this school lesson and return JSON:
{
  "topics": ["..."],
  "keyConcepts": ["..."],
  "difficulty": "EASY|MEDIUM|HARD",
  "estimatedStudyMinutes": number,
  "learningObjectives": ["..."]
}
Lesson:
{$trimmed}
PROMPT;

        $data = $this->requestJson($prompt);
        if ($data !== null) {
            $difficulty = LessonDifficulty::tryFrom((string) ($data['difficulty'] ?? '')) ?? LessonDifficulty::Medium;

            return [
                'topics' => $this->normalizeStringList($data['topics'] ?? []),
                'keyConcepts' => $this->normalizeStringList($data['keyConcepts'] ?? []),
                'difficulty' => $difficulty,
                'estimatedStudyMinutes' => max(15, (int) ($data['estimatedStudyMinutes'] ?? 30)),
                'learningObjectives' => $this->normalizeStringList($data['learningObjectives'] ?? []),
            ];
        }

        return $this->fallbackAnalysis($text);
    }

    /**
     * @param list<string> $weakTopics
     *
     * @return array{summary:string, flashcards:list<array{front:string, back:string}>, explanations:list<string>, examples:list<string>}
     */
    public function generateMaterials(string $text, array $weakTopics = []): array
    {
        $topicHint = $weakTopics === []
            ? 'Generate general lesson learning materials.'
            : 'Focus strongly on these weak topics: '.implode(', ', $weakTopics);

        $prompt = <<<PROMPT
{$topicHint}
Return strict JSON:
{
  "summary": "...",
  "flashcards": [{"front":"...","back":"..."}],
  "explanations": ["..."],
  "examples": ["..."]
}
Lesson:
{$this->limit($text, 14000)}
PROMPT;

        $data = $this->requestJson($prompt);
        if ($data !== null) {
            $flashcards = [];
            foreach (($data['flashcards'] ?? []) as $flashcard) {
                if (is_array($flashcard)) {
                    $front = trim((string) ($flashcard['front'] ?? ''));
                    $back = trim((string) ($flashcard['back'] ?? ''));
                    if ($front !== '' && $back !== '') {
                        $flashcards[] = ['front' => $front, 'back' => $back];
                    }
                }
            }

            return [
                'summary' => trim((string) ($data['summary'] ?? '')),
                'flashcards' => $flashcards,
                'explanations' => $this->normalizeStringList($data['explanations'] ?? []),
                'examples' => $this->normalizeStringList($data['examples'] ?? []),
            ];
        }

        return $this->fallbackMaterials($text, $weakTopics);
    }

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
    public function generateQuizQuestions(string $text, int $count = 8, array $context = []): array
    {
        $metadata = $this->buildQuizMetadata($context);
        $topicHints = $this->normalizeStringList($context['topics'] ?? []);
        $weakTopicHints = $this->normalizeStringList($context['weakTopics'] ?? []);

        $prompt = <<<PROMPT
Generate {$count} multiple-choice questions for this exact uploaded lesson.
Use lesson metadata and excerpt together.
Rules:
- Questions must test understanding of concrete lesson concepts, not generic study advice.
- Every question must be tied to a specific lesson topic.
- Include 4 options and 1 correct answer that is present in options.
Return strict JSON array of objects:
[{"text":"...","options":["A","B","C","D"],"correctAnswer":"..."}]
Lesson metadata:
{$metadata}
Priority topics:
{$this->formatHintList($topicHints)}
Weak topics to reinforce:
{$this->formatHintList($weakTopicHints)}
Lesson:
{$this->limit($text, 12000)}
PROMPT;

        $data = $this->requestJson($prompt);
        $questions = [];

        if (is_array($data) && array_is_list($data)) {
            $seenTexts = [];
            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $textValue = trim((string) ($row['text'] ?? ''));
                $options = $this->normalizeStringList($row['options'] ?? []);
                $correct = trim((string) ($row['correctAnswer'] ?? ''));

                if ($textValue === '' || $correct === '') {
                    continue;
                }

                if (!in_array($correct, $options, true)) {
                    array_unshift($options, $correct);
                }

                $options = array_values(array_unique($options));
                if (count($options) < 2) {
                    continue;
                }

                $options = array_slice($options, 0, 4);
                if (!in_array($correct, $options, true)) {
                    $options[0] = $correct;
                }

                $questionKey = mb_strtolower(preg_replace('/\s+/', ' ', $textValue) ?? '');
                if ($questionKey === '' || isset($seenTexts[$questionKey])) {
                    continue;
                }
                $seenTexts[$questionKey] = true;

                $questions[] = [
                    'text' => $textValue,
                    'options' => $options,
                    'correctAnswer' => $correct,
                ];
            }
        }

        $fallback = $this->fallbackQuiz($text, $count, $context);

        return $this->mergeUniqueQuestions($questions, $fallback, $count);
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function requestJson(string $prompt): array|null
    {
        if ($this->openAiApiKey === '') {
            return null;
        }

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->openAiApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->openAiModel,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are an educational AI. Return only valid JSON.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.4,
                ],
                'timeout' => 30,
            ]);

            $payload = $response->toArray(false);
            $content = (string) ($payload['choices'][0]['message']['content'] ?? '');
            if ($content === '') {
                return null;
            }

            return $this->decodeModelJson($content);
        } catch (TransportException|\Throwable) {
            return null;
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

    private function limit(string $text, int $maxChars): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($text)) ?? '';

        return mb_substr($normalized, 0, $maxChars);
    }

    /**
     * @return array{topics:list<string>, keyConcepts:list<string>, difficulty:LessonDifficulty, estimatedStudyMinutes:int, learningObjectives:list<string>}
     */
    private function fallbackAnalysis(string $text): array
    {
        $chunks = $this->sentenceChunks($text);
        $topics = array_slice($chunks, 0, 5);
        $difficulty = mb_strlen($text) > 8000 ? LessonDifficulty::Hard : (mb_strlen($text) > 3000 ? LessonDifficulty::Medium : LessonDifficulty::Easy);

        return [
            'topics' => $topics === [] ? ['Core Lesson Topic'] : $topics,
            'keyConcepts' => array_slice($topics === [] ? ['Definition', 'Example', 'Practice'] : $topics, 0, 6),
            'difficulty' => $difficulty,
            'estimatedStudyMinutes' => max(20, min(120, (int) ceil(mb_strlen($text) / 250))),
            'learningObjectives' => [
                'Understand the main lesson concepts',
                'Apply concepts to practical examples',
                'Answer comprehension questions confidently',
            ],
        ];
    }

    /**
     * @param list<string> $weakTopics
     * @return array{summary:string, flashcards:list<array{front:string, back:string}>, explanations:list<string>, examples:list<string>}
     */
    private function fallbackMaterials(string $text, array $weakTopics): array
    {
        $sentences = $this->sentenceChunks($text);
        $summary = implode(' ', array_slice($sentences, 0, 4));
        if ($summary === '') {
            $summary = 'This lesson introduces core concepts and practical understanding goals.';
        }

        $flashcards = [];
        $seedTopics = $weakTopics === [] ? array_slice($sentences, 0, 4) : array_slice($weakTopics, 0, 4);
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
            ],
            'examples' => [
                'Solve a simple case using the lesson rule step by step.',
                'Explain the concept to another student using your own words.',
            ],
        ];
    }

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
    private function fallbackQuiz(string $text, int $count, array $context = []): array
    {
        $sentences = $this->sentenceChunks($text);
        $topicPool = $this->normalizeStringList($context['topics'] ?? []);
        $keyConceptPool = $this->normalizeStringList($context['keyConcepts'] ?? []);
        $weakTopicPool = $this->normalizeStringList($context['weakTopics'] ?? []);

        $focusPool = array_values(array_unique(array_merge(
            $weakTopicPool,
            $topicPool,
            $keyConceptPool,
            array_slice($sentences, 0, 16),
        )));

        if ($focusPool === []) {
            $fallbackFocus = trim((string) ($context['title'] ?? 'Core lesson concept'));
            $focusPool = [$fallbackFocus !== '' ? $fallbackFocus : 'Core lesson concept'];
        }

        $title = trim((string) ($context['title'] ?? 'the lesson'));
        $subject = trim((string) ($context['subject'] ?? 'the subject'));
        $keywords = $this->extractKeywords($text, 24);
        if ($keywords === []) {
            $keywords = array_map(
                static fn (string $item): string => mb_substr($item, 0, 28),
                array_slice($focusPool, 0, 24),
            );
        }

        $questions = [];
        for ($i = 0; $i < $count; ++$i) {
            $focus = $focusPool[$i % count($focusPool)];
            $relatedKeyword = $keywords[$i % count($keywords)];
            $distractorOne = $focusPool[($i + 1) % count($focusPool)];
            $distractorTwo = $focusPool[($i + 2) % count($focusPool)];

            $correct = sprintf('It explains "%s" in the context of %s.', $focus, $subject);
            $questions[] = [
                'text' => sprintf(
                    'In %s, what best describes the concept "%s" linked to "%s"?',
                    $title === '' ? 'this lesson' : $title,
                    mb_substr($focus, 0, 70),
                    mb_substr($relatedKeyword, 0, 40),
                ),
                'options' => [
                    $correct,
                    sprintf('It only repeats "%s" without relation to %s.', mb_substr($distractorOne, 0, 42), $subject),
                    sprintf('It replaces "%s" entirely with "%s".', mb_substr($focus, 0, 30), mb_substr($distractorTwo, 0, 30)),
                    sprintf('It is unrelated to the %s lesson content.', $subject),
                ],
                'correctAnswer' => $correct,
            ];
        }

        return $questions;
    }

    /**
     * @return list<array{text:string, options:list<string>, correctAnswer:string}>
     */
    private function mergeUniqueQuestions(array $primary, array $fallback, int $count): array
    {
        $result = [];
        $seen = [];

        foreach (array_merge($primary, $fallback) as $row) {
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
     * @param array{
     *     title?: string,
     *     subject?: string,
     *     difficulty?: string,
     *     topics?: list<string>,
     *     keyConcepts?: list<string>,
     *     weakTopics?: list<string>
     * } $context
     */
    private function buildQuizMetadata(array $context): string
    {
        $parts = [];

        $title = trim((string) ($context['title'] ?? ''));
        if ($title !== '') {
            $parts[] = 'Title: '.$title;
        }

        $subject = trim((string) ($context['subject'] ?? ''));
        if ($subject !== '') {
            $parts[] = 'Subject: '.$subject;
        }

        $difficulty = trim((string) ($context['difficulty'] ?? ''));
        if ($difficulty !== '') {
            $parts[] = 'Difficulty: '.$difficulty;
        }

        $keyConcepts = $this->normalizeStringList($context['keyConcepts'] ?? []);
        if ($keyConcepts !== []) {
            $parts[] = 'Key concepts: '.implode('; ', array_slice($keyConcepts, 0, 10));
        }

        return $parts === [] ? 'No metadata provided' : implode("\n", $parts);
    }

    /**
     * @param list<string> $items
     */
    private function formatHintList(array $items): string
    {
        if ($items === []) {
            return 'None';
        }

        return implode('; ', array_slice($items, 0, 12));
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function decodeModelJson(string $content): array|null
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $trimmed, $matches) === 1) {
            $decoded = json_decode(trim($matches[1]), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        if (preg_match('/(\{[\s\S]+\}|\[[\s\S]+\])/m', $trimmed, $matches) === 1) {
            $decoded = json_decode(trim($matches[1]), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
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

        $freq = [];
        foreach ($words as $word) {
            if (in_array($word, $stopWords, true)) {
                continue;
            }
            $freq[$word] = ($freq[$word] ?? 0) + 1;
        }

        arsort($freq);

        return array_slice(array_keys($freq), 0, $limit);
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
