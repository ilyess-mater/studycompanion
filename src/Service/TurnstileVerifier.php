<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ThirdPartyStatus;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TurnstileVerifier
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly bool $enabled,
        private readonly string $siteKey,
        private readonly string $secretKey,
        private readonly bool $strictMode,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->siteKey !== '' && $this->secretKey !== '';
    }

    public function getSiteKey(): string
    {
        return $this->siteKey;
    }

    /**
     * @return array{
     *     passed:bool,
     *     status:ThirdPartyStatus,
     *     message:string,
     *     latencyMs:int|null,
     *     externalId:string|null,
     *     payload:array<string, mixed>
     * }
     */
    public function verify(?string $token, ?string $remoteIp = null): array
    {
        if (!$this->isEnabled()) {
            return [
                'passed' => true,
                'status' => ThirdPartyStatus::Skipped,
                'message' => 'Turnstile is disabled.',
                'latencyMs' => null,
                'externalId' => null,
                'payload' => [],
            ];
        }

        if (trim((string) $token) === '') {
            return [
                'passed' => false,
                'status' => ThirdPartyStatus::Failed,
                'message' => 'Captcha verification is required.',
                'latencyMs' => null,
                'externalId' => null,
                'payload' => ['errorCodes' => ['missing-token']],
            ];
        }

        $start = microtime(true);

        try {
            $response = $this->httpClient->request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'body' => [
                    'secret' => $this->secretKey,
                    'response' => $token,
                    'remoteip' => $remoteIp ?? '',
                ],
                'timeout' => 8,
            ]);

            $payload = $response->toArray(false);
            $success = (bool) ($payload['success'] ?? false);
            $latencyMs = (int) ((microtime(true) - $start) * 1000);
            $errorCodes = is_array($payload['error-codes'] ?? null) ? $payload['error-codes'] : [];
            $challengeTs = isset($payload['challenge_ts']) ? (string) $payload['challenge_ts'] : null;

            if ($success) {
                return [
                    'passed' => true,
                    'status' => ThirdPartyStatus::Success,
                    'message' => 'Captcha verification succeeded.',
                    'latencyMs' => $latencyMs,
                    'externalId' => $challengeTs,
                    'payload' => ['hostname' => (string) ($payload['hostname'] ?? ''), 'errorCodes' => $errorCodes],
                ];
            }

            return [
                'passed' => false,
                'status' => ThirdPartyStatus::Failed,
                'message' => $errorCodes === [] ? 'Captcha verification failed.' : 'Captcha failed: '.implode(', ', $errorCodes),
                'latencyMs' => $latencyMs,
                'externalId' => $challengeTs,
                'payload' => ['hostname' => (string) ($payload['hostname'] ?? ''), 'errorCodes' => $errorCodes],
            ];
        } catch (\Throwable $exception) {
            return [
                'passed' => !$this->strictMode,
                'status' => $this->strictMode ? ThirdPartyStatus::Failed : ThirdPartyStatus::Fallback,
                'message' => $this->strictMode
                    ? 'Captcha provider is unavailable. Please try again.'
                    : 'Captcha provider unavailable, fallback mode enabled.',
                'latencyMs' => (int) ((microtime(true) - $start) * 1000),
                'externalId' => null,
                'payload' => ['exception' => $exception->getMessage()],
            ];
        }
    }
}

