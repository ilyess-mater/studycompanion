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
     * @return list<array{text:string, options:list<string>, correctAnswer:string}>
     */
    public function generateQuizQuestions(string $text, int $count = 8): array
    {
        $prompt = <<<PROMPT
Generate {$count} multiple-choice questions for this lesson.
Return strict JSON array of objects:
[{"text":"...","options":["A","B","C","D"],"correctAnswer":"..."}]
Lesson:
{$this->limit($text, 12000)}
PROMPT;

        $data = $this->requestJson($prompt);

        if (is_array($data) && array_is_list($data)) {
            $questions = [];
            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $textValue = trim((string) ($row['text'] ?? ''));
                $options = $this->normalizeStringList($row['options'] ?? []);
                $correct = trim((string) ($row['correctAnswer'] ?? ''));

                if ($textValue === '' || count($options) < 2 || $correct === '') {
                    continue;
                }

                if (!in_array($correct, $options, true)) {
                    $options[0] = $correct;
                }

                $questions[] = [
                    'text' => $textValue,
                    'options' => array_slice($options, 0, 4),
                    'correctAnswer' => $correct,
                ];
            }

            if ($questions !== []) {
                return $questions;
            }
        }

        return $this->fallbackQuiz($text, $count);
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

            $decoded = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            return $decoded;
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
     * @return list<array{text:string, options:list<string>, correctAnswer:string}>
     */
    private function fallbackQuiz(string $text, int $count): array
    {
        $sentences = $this->sentenceChunks($text);
        if ($sentences === []) {
            $sentences = ['The lesson focuses on understanding key concepts and applying them correctly.'];
        }

        $questions = [];
        for ($i = 0; $i < $count; ++$i) {
            $topic = $sentences[$i % count($sentences)];
            $correct = 'Key idea from topic '.($i + 1);
            $questions[] = [
                'text' => sprintf('Which statement best matches this lesson point: "%s"?', mb_substr($topic, 0, 110)),
                'options' => [
                    $correct,
                    'Unrelated memorization detail',
                    'Contradictory interpretation',
                    'Random external topic',
                ],
                'correctAnswer' => $correct,
            ];
        }

        return $questions;
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
