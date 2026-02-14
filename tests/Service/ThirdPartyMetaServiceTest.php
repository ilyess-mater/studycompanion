<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\ThirdPartyProvider;
use App\Enum\ThirdPartyStatus;
use App\Service\ThirdPartyMetaService;
use PHPUnit\Framework\TestCase;

class ThirdPartyMetaServiceTest extends TestCase
{
    public function testRecordStoresIntegrationAndSummaryCounts(): void
    {
        $service = new ThirdPartyMetaService();
        $user = (new User())
            ->setName('Test')
            ->setEmail('meta@test.local')
            ->setPassword('hash');

        $service->record(
            $user,
            ThirdPartyProvider::OpenAi,
            ThirdPartyStatus::Success,
            'ok',
            ['sample' => 'value'],
            'external-1',
            120,
        );
        $service->record(
            $user,
            ThirdPartyProvider::Youtube,
            ThirdPartyStatus::Fallback,
            'fallback',
        );

        $meta = $user->getThirdPartyMeta();
        self::assertIsArray($meta);
        self::assertArrayHasKey('integrations', $meta);
        self::assertArrayHasKey('OPENAI', $meta['integrations']);
        self::assertArrayHasKey('YOUTUBE', $meta['integrations']);
        self::assertSame('SUCCESS', $meta['integrations']['OPENAI']['status']);

        $summary = $service->summarize($meta);
        self::assertSame(2, $summary['total']);
        self::assertSame(1, $summary['success']);
        self::assertSame(1, $summary['fallback']);
        self::assertSame(0, $summary['failed']);
    }
}

