<?php

declare(strict_types=1);

namespace App\Service\Ai;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiAiProvider implements AiProviderInterface
{
    private const MAX_PROMPT_TEXT_CHARS = 15000;
    private const CHUNK_SIZE = 5000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $model,
    ) {
    }

    public function hasProvider(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function analyzeLesson(string $text): array
    {
        $prepared = $this->prepareLessonContext($text);
        $prompt = <<<PROMPT
Analyze this school lesson and return strict JSON:
{
  "topics": ["..."],
  "keyConcepts": ["..."],
  "difficulty": "EASY|MEDIUM|HARD",
  "estimatedStudyMinutes": number,
  "learningObjectives": ["..."]
}
Lesson:
{$prepared}
PROMPT;

        $data = $this->requestJson(
            $prompt,
            '{"topics":["..."],"keyConcepts":["..."],"difficulty":"EASY|MEDIUM|HARD","estimatedStudyMinutes":30,"learningObjectives":["..."]}',
        );

        $difficulty = strtoupper((string) ($data['difficulty'] ?? 'MEDIUM'));
        if (!in_array($difficulty, ['EASY', 'MEDIUM', 'HARD'], true)) {
            $difficulty = 'MEDIUM';
        }

        return [
            'topics' => $this->normalizeStringList($data['topics'] ?? []),
            'keyConcepts' => $this->normalizeStringList($data['keyConcepts'] ?? []),
            'difficulty' => $difficulty,
            'estimatedStudyMinutes' => max(15, (int) ($data['estimatedStudyMinutes'] ?? 30)),
            'learningObjectives' => $this->normalizeStringList($data['learningObjectives'] ?? []),
        ];
    }

    public function generateMaterials(string $text, array $weakTopics = []): array
    {
        $prepared = $this->prepareLessonContext($text);
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
{$prepared}
PROMPT;

        $data = $this->requestJson(
            $prompt,
            '{"summary":"...","flashcards":[{"front":"...","back":"..."}],"explanations":["..."],"examples":["..."]}',
        );

        $flashcards = [];
        foreach (($data['flashcards'] ?? []) as $flashcard) {
            if (!is_array($flashcard)) {
                continue;
            }
            $front = trim((string) ($flashcard['front'] ?? ''));
            $back = trim((string) ($flashcard['back'] ?? ''));
            if ($front !== '' && $back !== '') {
                $flashcards[] = ['front' => $front, 'back' => $back];
            }
        }

        return [
            'summary' => trim((string) ($data['summary'] ?? '')),
            'flashcards' => $flashcards,
            'explanations' => $this->normalizeStringList($data['explanations'] ?? []),
            'examples' => $this->normalizeStringList($data['examples'] ?? []),
        ];
    }

    public function generateQuizQuestions(string $text, int $count = 8, array $context = []): array
    {
        $prepared = $this->prepareLessonContext($text);
        $metadata = $this->buildQuizMetadata($context);
        $topicHints = $this->normalizeStringList($context['topics'] ?? []);
        $weakTopicHints = $this->normalizeStringList($context['weakTopics'] ?? []);

        $prompt = <<<PROMPT
Generate {$count} multiple-choice questions for this exact uploaded lesson.
Use lesson metadata and excerpt together.
Rules:
- Questions must test understanding of concrete lesson concepts.
- Every question must be tied to a specific lesson topic.
- Include 4 options and 1 correct answer present in options.
Return strict JSON array:
[{"text":"...","options":["A","B","C","D"],"correctAnswer":"..."}]
Lesson metadata:
{$metadata}
Priority topics:
{$this->formatHintList($topicHints)}
Weak topics to reinforce:
{$this->formatHintList($weakTopicHints)}
Lesson:
{$prepared}
PROMPT;

        $data = $this->requestJson(
            $prompt,
            '[{"text":"...","options":["A","B","C","D"],"correctAnswer":"..."}]',
        );
        if (!is_array($data) || !array_is_list($data)) {
            throw new \RuntimeException('OpenAI quiz payload was not a list.');
        }

        $questions = [];
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

        if ($questions === []) {
            throw new \RuntimeException('OpenAI returned no valid questions.');
        }

        return $questions;
    }

    public function evaluateQuizSubmission(array $answerStats, array $context = []): array
    {
        $serialized = [];
        foreach ($answerStats as $row) {
            if (!is_array($row)) {
                continue;
            }
            $question = $row['question'] ?? null;
            if (!is_object($question) || !method_exists($question, 'getText') || !method_exists($question, 'getCorrectAnswer')) {
                continue;
            }

            $serialized[] = [
                'question' => (string) $question->getText(),
                'correctAnswer' => (string) $question->getCorrectAnswer(),
                'studentAnswer' => (string) ($row['answer'] ?? ''),
                'isCorrect' => (bool) ($row['isCorrect'] ?? false),
                'responseTimeMs' => max(0, (int) ($row['responseTimeMs'] ?? 0)),
            ];
        }

        if ($serialized === []) {
            throw new \RuntimeException('No answer rows to evaluate.');
        }

        $prompt = sprintf(
            "Evaluate this quiz submission for lesson '%s' (%s). Return strict JSON: {\"score\": number, \"weakTopics\": [\"...\"], \"explanation\": \"...\"}. Data: %s",
            (string) ($context['lessonTitle'] ?? 'Lesson'),
            (string) ($context['lessonSubject'] ?? 'General'),
            json_encode($serialized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $data = $this->requestJson(
            $prompt,
            '{"score":78.5,"weakTopics":["topic"],"explanation":"..."}',
        );
        $score = round((float) ($data['score'] ?? 0.0), 2);
        $weakTopics = $this->normalizeStringList($data['weakTopics'] ?? []);
        $explanation = trim((string) ($data['explanation'] ?? ''));

        return [
            'score' => max(0.0, min(100.0, $score)),
            'weakTopics' => $weakTopics,
            'explanation' => $explanation,
        ];
    }

    public function summarizeWeakTopics(array $weakTopics, float $score, string $lessonTitle): array
    {
        $prompt = <<<PROMPT
Write a concise remediation narrative for the student.
Return strict JSON:
{"summary":"..."}
Lesson: {$lessonTitle}
Score: {$score}
Weak topics: {$this->formatHintList($weakTopics)}
PROMPT;

        $data = $this->requestJson($prompt, '{"summary":"..."}');
        $summary = trim((string) ($data['summary'] ?? ''));
        if ($summary === '') {
            throw new \RuntimeException('OpenAI did not return a remediation summary.');
        }

        return ['summary' => $summary];
    }

    public function generateOnboardingTip(string $role, string $name, ?string $grade): array
    {
        $prompt = sprintf(
            "Create one short onboarding tip for a %s user named %s%s. Return JSON: {\"tip\":\"...\"}.",
            $role,
            $name,
            $grade !== null && $grade !== '' ? sprintf(' in grade %s', $grade) : '',
        );

        $data = $this->requestJson($prompt, '{"tip":"..."}');
        $tip = trim((string) ($data['tip'] ?? ''));
        if ($tip === '') {
            throw new \RuntimeException('OpenAI did not return onboarding tip.');
        }

        return ['tip' => $tip];
    }

    public function tagQuestionConcept(string $questionText, string $lessonContext, string $subject = ''): array
    {
        $prompt = <<<PROMPT
Tag this MCQ question with 1-3 lesson concept tags and one short difficulty hint.
Return JSON:
{
  "tags": ["..."],
  "difficultyHint": "..."
}
Subject: {$subject}
Lesson context: {$this->limit($lessonContext, 900)}
Question: {$questionText}
PROMPT;

        $data = $this->requestJson($prompt, '{"tags":["..."],"difficultyHint":"..."}');
        $tags = array_slice($this->normalizeStringList($data['tags'] ?? []), 0, 3);
        $hint = trim((string) ($data['difficultyHint'] ?? ''));

        if ($tags === [] && $hint === '') {
            throw new \RuntimeException('OpenAI did not return question tagging.');
        }

        return [
            'tags' => $tags,
            'hint' => $hint !== '' ? $hint : 'Review the concept and retry.',
        ];
    }

    public function analyzeMisconception(string $questionText, string $correctAnswer, string $studentAnswer): array
    {
        $prompt = <<<PROMPT
Identify likely misconception from this wrong answer.
Return JSON:
{
  "label": "...",
  "confidence": 0.0
}
Question: {$questionText}
Correct answer: {$correctAnswer}
Student answer: {$studentAnswer}
PROMPT;

        $data = $this->requestJson($prompt, '{"label":"...","confidence":0.0}');
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            throw new \RuntimeException('OpenAI did not return misconception label.');
        }

        return [
            'label' => $label,
            'confidence' => max(0.0, min(1.0, (float) ($data['confidence'] ?? 0.0))),
        ];
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private function requestJson(string $prompt, string $expectedSchema): array
    {
        if (!$this->hasProvider()) {
            throw new \RuntimeException('OpenAI key is missing.');
        }

        try {
            $content = $this->requestRaw($prompt, 'You are an educational AI. Return only valid JSON.');
            $decoded = $this->decodeModelJson($content);
            if ($decoded !== null) {
                return $decoded;
            }

            $repairPrompt = <<<PROMPT
Repair the following output into valid JSON only.
Expected shape example:
{$expectedSchema}
Broken output:
{$content}
PROMPT;
            $repairedContent = $this->requestRaw($repairPrompt, 'You only repair JSON and return valid JSON.');
            $repairedDecoded = $this->decodeModelJson($repairedContent);
            if ($repairedDecoded !== null) {
                return $repairedDecoded;
            }

            throw new \RuntimeException('OpenAI returned invalid JSON payload after repair attempt.');
        } catch (TransportException|\Throwable $exception) {
            throw new \RuntimeException('OpenAI request failed: '.$exception->getMessage(), 0, $exception);
        }
    }

    private function requestRaw(string $prompt, string $system): string
    {
        $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => trim($this->model) !== '' ? $this->model : 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.2,
            ],
            'timeout' => 35,
        ]);

        $payload = $response->toArray(false);

        return (string) ($payload['choices'][0]['message']['content'] ?? '');
    }

    private function prepareLessonContext(string $text): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($text)) ?? '';
        if (mb_strlen($normalized) <= self::MAX_PROMPT_TEXT_CHARS) {
            return $this->limit($normalized, self::MAX_PROMPT_TEXT_CHARS);
        }

        $chunks = str_split($normalized, self::CHUNK_SIZE);
        $chunkSummaries = [];
        foreach ($chunks as $index => $chunk) {
            $chunkSummaries[] = sprintf(
                'Chunk %d: %s',
                $index + 1,
                $this->extractiveChunkSummary($chunk),
            );
        }

        return $this->limit(implode("\n", $chunkSummaries), self::MAX_PROMPT_TEXT_CHARS);
    }

    private function extractiveChunkSummary(string $chunk): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($chunk)) ?: [];
        $sentences = array_values(array_filter(array_map(
            static fn (mixed $sentence): string => trim((string) $sentence),
            $sentences,
        ), static fn (string $sentence): bool => $sentence !== ''));

        $lead = array_slice($sentences, 0, 2);
        $keywords = $this->extractKeywords($chunk, 6);
        $keywordPart = $keywords === [] ? '' : ' Keywords: '.implode(', ', $keywords).'.';

        if ($lead !== []) {
            return implode(' ', $lead).$keywordPart;
        }

        return $this->limit(trim($chunk), 350).$keywordPart;
    }

    /**
     * @return list<string>
     */
    private function extractKeywords(string $text, int $limit): array
    {
        preg_match_all('/[a-z][a-z0-9\-]{3,}/ui', mb_strtolower($text), $matches);
        $words = $matches[0] ?? [];
        if ($words === []) {
            return [];
        }

        $freq = [];
        foreach ($words as $word) {
            $freq[$word] = ($freq[$word] ?? 0) + 1;
        }
        arsort($freq);

        return array_slice(array_keys($freq), 0, $limit);
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
        return mb_substr(trim($text), 0, $maxChars);
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
}

