<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\ThirdPartyStatus;
use App\Service\PerspectiveModerationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PerspectiveModerationServiceTest extends TestCase
{
    public function testMissingKeySkipsModeration(): void
    {
        $service = new PerspectiveModerationService(new MockHttpClient(), '', false);

        $result = $service->moderate('Any comment');

        self::assertTrue($result['allowed']);
        self::assertSame(ThirdPartyStatus::Skipped, $result['status']);
    }

    public function testHighToxicityBlocksComment(): void
    {
        $response = new MockResponse(json_encode([
            'attributeScores' => [
                'TOXICITY' => ['summaryScore' => ['value' => 0.91]],
            ],
        ]) ?: '{}');
        $service = new PerspectiveModerationService(new MockHttpClient($response), 'key', false);

        $result = $service->moderate('Aggressive content');

        self::assertFalse($result['allowed']);
        self::assertSame('block', $result['action']);
        self::assertSame(ThirdPartyStatus::Success, $result['status']);
    }

    public function testProviderErrorFallsBackWhenNotStrict(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \RuntimeException('timeout');
        });
        $service = new PerspectiveModerationService($client, 'key', false);

        $result = $service->moderate('Normal content');

        self::assertTrue($result['allowed']);
        self::assertSame(ThirdPartyStatus::Fallback, $result['status']);
    }
}

