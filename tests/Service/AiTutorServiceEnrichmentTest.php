<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\ThirdPartyStatus;
use App\Service\AiTutorService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

class AiTutorServiceEnrichmentTest extends TestCase
{
    public function testOnboardingTipUsesFallbackWithoutApiKey(): void
    {
        $service = new AiTutorService(new MockHttpClient(), '', 'gpt-4o-mini');

        $result = $service->generateOnboardingTip('student', 'Lina', '10');

        self::assertNotSame('', $result['tip']);
        self::assertSame(ThirdPartyStatus::Skipped, $result['status']);
    }

    public function testQuestionTaggingReturnsFallbackPayloadWithoutApiKey(): void
    {
        $service = new AiTutorService(new MockHttpClient(), '', 'gpt-4o-mini');

        $result = $service->tagQuestionConcept(
            'What is the function of chlorophyll in photosynthesis?',
            'This lesson explains photosynthesis and chlorophyll role in capturing light.',
            'Biology',
        );

        self::assertNotEmpty($result['tags']);
        self::assertNotSame('', $result['hint']);
        self::assertSame(ThirdPartyStatus::Skipped, $result['status']);
    }
}

