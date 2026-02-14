<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ThirdPartyStatus;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PerspectiveModerationService
{
    private const BLOCK_THRESHOLD = 0.85;
    private const WARN_THRESHOLD = 0.70;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly bool $strictMode,
    ) {
    }

    public function isEnabled(): bool
    {
        return trim($this->apiKey) !== '';
    }

    /**
     * @return array{
     *     allowed:bool,
     *     warn:bool,
     *     action:string,
     *     score:float,
     *     status:ThirdPartyStatus,
     *     message:string,
     *     latencyMs:int|null,
     *     payload:array<string, mixed>
     * }
     */
    public function moderate(string $content): array
    {
        $normalized = trim($content);
        if ($normalized === '') {
            return [
                'allowed' => true,
                'warn' => false,
                'action' => 'allow',
                'score' => 0.0,
                'status' => ThirdPartyStatus::Skipped,
                'message' => 'No content to moderate.',
                'latencyMs' => null,
                'payload' => [],
            ];
        }

        if (!$this->isEnabled()) {
            return [
                'allowed' => true,
                'warn' => false,
                'action' => 'allow',
                'score' => 0.0,
                'status' => ThirdPartyStatus::Skipped,
                'message' => 'Perspective API key is not configured.',
                'latencyMs' => null,
                'payload' => [],
            ];
        }

        $start = microtime(true);

        try {
            $response = $this->httpClient->request(
                'POST',
                'https://commentanalyzer.googleapis.com/v1alpha1/comments:analyze?key='.$this->apiKey,
                [
                    'json' => [
                        'comment' => ['text' => $normalized],
                        'languages' => ['en'],
                        'requestedAttributes' => ['TOXICITY' => (object) []],
                    ],
                    'timeout' => 10,
                ],
            );

            $payload = $response->toArray(false);
            $score = (float) ($payload['attributeScores']['TOXICITY']['summaryScore']['value'] ?? 0.0);
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            if ($score >= self::BLOCK_THRESHOLD) {
                return [
                    'allowed' => false,
                    'warn' => false,
                    'action' => 'block',
                    'score' => $score,
                    'status' => ThirdPartyStatus::Success,
                    'message' => 'Comment blocked by moderation policy.',
                    'latencyMs' => $latencyMs,
                    'payload' => ['threshold' => self::BLOCK_THRESHOLD],
                ];
            }

            if ($score >= self::WARN_THRESHOLD) {
                return [
                    'allowed' => true,
                    'warn' => true,
                    'action' => 'warn',
                    'score' => $score,
                    'status' => ThirdPartyStatus::Success,
                    'message' => 'Comment allowed with warning.',
                    'latencyMs' => $latencyMs,
                    'payload' => ['threshold' => self::WARN_THRESHOLD],
                ];
            }

            return [
                'allowed' => true,
                'warn' => false,
                'action' => 'allow',
                'score' => $score,
                'status' => ThirdPartyStatus::Success,
                'message' => 'Comment passed moderation.',
                'latencyMs' => $latencyMs,
                'payload' => ['threshold' => self::WARN_THRESHOLD],
            ];
        } catch (\Throwable $exception) {
            return [
                'allowed' => !$this->strictMode,
                'warn' => false,
                'action' => $this->strictMode ? 'block' : 'allow',
                'score' => 0.0,
                'status' => $this->strictMode ? ThirdPartyStatus::Failed : ThirdPartyStatus::Fallback,
                'message' => $this->strictMode
                    ? 'Moderation provider unavailable; comment blocked in strict mode.'
                    : 'Moderation provider unavailable; fallback mode allowed comment.',
                'latencyMs' => (int) ((microtime(true) - $start) * 1000),
                'payload' => ['exception' => $exception->getMessage()],
            ];
        }
    }
}

