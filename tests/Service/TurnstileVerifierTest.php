<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\ThirdPartyStatus;
use App\Service\TurnstileVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class TurnstileVerifierTest extends TestCase
{
    public function testDisabledVerifierSkipsCheck(): void
    {
        $service = new TurnstileVerifier(new MockHttpClient(), false, '', '', false);

        $result = $service->verify('', null);

        self::assertTrue($result['passed']);
        self::assertSame(ThirdPartyStatus::Skipped, $result['status']);
    }

    public function testSuccessfulVerificationReturnsSuccess(): void
    {
        $response = new MockResponse(json_encode([
            'success' => true,
            'challenge_ts' => '2026-02-14T12:00:00Z',
            'hostname' => 'localhost',
        ]) ?: '{}');
        $service = new TurnstileVerifier(new MockHttpClient($response), true, 'site_key', 'secret_key', false);

        $result = $service->verify('token-value', '127.0.0.1');

        self::assertTrue($result['passed']);
        self::assertSame(ThirdPartyStatus::Success, $result['status']);
    }

    public function testTransportFailureUsesFallbackInNonStrictMode(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \RuntimeException('network down');
        });
        $service = new TurnstileVerifier($client, true, 'site_key', 'secret_key', false);

        $result = $service->verify('token', '127.0.0.1');

        self::assertTrue($result['passed']);
        self::assertSame(ThirdPartyStatus::Fallback, $result['status']);
    }
}

