<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Ai\OpenAiAiProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OpenAiAiProviderTest extends TestCase
{
    public function testAnalyzeLessonParsesValidJson(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'topics' => ['Routing', 'Switching'],
                        'keyConcepts' => ['Packets', 'Frames'],
                        'difficulty' => 'MEDIUM',
                        'estimatedStudyMinutes' => 40,
                        'learningObjectives' => ['Understand routing basics'],
                    ], JSON_THROW_ON_ERROR)],
                ]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $provider = new OpenAiAiProvider($client, 'openai-test-key', 'gpt-4o-mini');
        $result = $provider->analyzeLesson('Networking lesson content');

        self::assertSame(['Routing', 'Switching'], $result['topics']);
        self::assertSame('MEDIUM', $result['difficulty']);
        self::assertSame(40, $result['estimatedStudyMinutes']);
    }

    public function testJsonRepairPathWorksForMalformedInitialPayload(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'choices' => [[
                    'message' => ['content' => 'invalid-output'],
                ]],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'choices' => [[
                    'message' => ['content' => '{"tip":"Recovered tip"}'],
                ]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $provider = new OpenAiAiProvider($client, 'openai-test-key', 'gpt-4o-mini');
        $result = $provider->generateOnboardingTip('student', 'Nora', '11');

        self::assertSame('Recovered tip', $result['tip']);
    }

    public function testThrowsWhenBothInitialAndRepairPayloadAreInvalid(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'choices' => [[
                    'message' => ['content' => 'invalid-output'],
                ]],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'choices' => [[
                    'message' => ['content' => 'still-invalid'],
                ]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $provider = new OpenAiAiProvider($client, 'openai-test-key', 'gpt-4o-mini');

        $this->expectException(\RuntimeException::class);
        $provider->generateOnboardingTip('student', 'Nora', '11');
    }
}

