<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Question;
use App\Enum\ThirdPartyProvider;
use App\Enum\ThirdPartyStatus;
use App\Service\Ai\GroqAiProvider;
use App\Service\Ai\LocalNlpAiProvider;
use App\Service\Ai\OpenAiAiProvider;
use App\Service\LearningAiService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LearningAiServiceProviderSelectionTest extends TestCase
{
    public function testUsesOpenAiWhenConfiguredAndAvailable(): void
    {
        $openAiClient = new MockHttpClient([
            new MockResponse(json_encode([
                'choices' => [[
                    'message' => ['content' => '{"tip":"Welcome tip from OpenAI"}'],
                ]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = new LearningAiService(
            new OpenAiAiProvider($openAiClient, 'openai-test-key', 'gpt-4o-mini'),
            new GroqAiProvider(new MockHttpClient(), '', 'llama-3.1-8b-instant'),
            new LocalNlpAiProvider(),
            'openai',
            false,
            'groq_local',
        );

        $result = $service->generateOnboardingTip('student', 'Lina', '10');

        self::assertSame(ThirdPartyProvider::OpenAi, $result['provider']);
        self::assertSame(ThirdPartyStatus::Success, $result['status']);
        self::assertFalse($result['fallbackUsed']);
        self::assertSame('Welcome tip from OpenAI', $result['data']['tip']);
    }

    public function testOpenAiFailureFallsBackToGroqWhenAvailable(): void
    {
        $openAiClient = new MockHttpClient([
            new MockResponse(json_encode([
                'choices' => [[
                    'message' => ['content' => 'not-json-output'],
                ]],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'choices' => [[
                    'message' => ['content' => 'still-not-json'],
                ]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $groqClient = new MockHttpClient([
            new MockResponse(json_encode([
                'choices' => [[
                    'message' => ['content' => '{"tip":"Groq fallback tip"}'],
                ]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = new LearningAiService(
            new OpenAiAiProvider($openAiClient, 'openai-test-key', 'gpt-4o-mini'),
            new GroqAiProvider($groqClient, 'groq-test-key', 'llama-3.1-8b-instant'),
            new LocalNlpAiProvider(),
            'openai',
            false,
            'groq_local',
        );

        $result = $service->generateOnboardingTip('student', 'Lina', '10');

        self::assertSame(ThirdPartyProvider::GroqFree, $result['provider']);
        self::assertSame(ThirdPartyStatus::Fallback, $result['status']);
        self::assertTrue($result['fallbackUsed']);
        self::assertSame('Groq fallback tip', $result['data']['tip']);
    }

    public function testFallsBackToLocalWhenNoApiKeysAreConfigured(): void
    {
        $service = new LearningAiService(
            new OpenAiAiProvider(new MockHttpClient(), '', 'gpt-4o-mini'),
            new GroqAiProvider(new MockHttpClient(), '', 'llama-3.1-8b-instant'),
            new LocalNlpAiProvider(),
            'openai',
            false,
            'groq_local',
        );

        $result = $service->generateOnboardingTip('student', 'Lina', '10');

        self::assertSame(ThirdPartyProvider::LocalNlp, $result['provider']);
        self::assertSame(ThirdPartyStatus::Fallback, $result['status']);
        self::assertTrue($result['fallbackUsed']);
        self::assertNotSame('', (string) ($result['data']['tip'] ?? ''));
    }

    public function testStrictModeThrowsWhenOpenAiUnavailable(): void
    {
        $service = new LearningAiService(
            new OpenAiAiProvider(new MockHttpClient(), '', 'gpt-4o-mini'),
            new GroqAiProvider(new MockHttpClient(), '', 'llama-3.1-8b-instant'),
            new LocalNlpAiProvider(),
            'openai',
            true,
            'groq_local',
        );

        $this->expectException(\RuntimeException::class);
        $service->generateOnboardingTip('student', 'Lina', '10');
    }

    public function testEvaluateQuizSubmissionStillProducesDynamicWeakTopics(): void
    {
        $service = new LearningAiService(
            new OpenAiAiProvider(new MockHttpClient(), '', 'gpt-4o-mini'),
            new GroqAiProvider(new MockHttpClient(), '', 'llama-3.1-8b-instant'),
            new LocalNlpAiProvider(),
            'openai',
            false,
            'groq_local',
        );

        $question = (new Question())
            ->setText('What is the role of chlorophyll in photosynthesis?')
            ->setOptions(['Energy storage', 'Light capture', 'Water transport', 'Cell division'])
            ->setCorrectAnswer('Light capture');

        $evaluation = $service->evaluateQuizSubmission([[
            'question' => $question,
            'answer' => 'Energy storage',
            'isCorrect' => false,
            'responseTimeMs' => 42000,
        ]], [
            'lessonTitle' => 'Photosynthesis',
            'lessonSubject' => 'Biology',
        ]);

        self::assertContains($evaluation['provider'], [ThirdPartyProvider::LocalNlp, ThirdPartyProvider::GroqFree, ThirdPartyProvider::OpenAi]);
        self::assertGreaterThanOrEqual(0.0, (float) $evaluation['data']['score']);
        self::assertNotEmpty($evaluation['data']['weakTopics']);
        self::assertNotSame('', (string) $evaluation['data']['explanation']);
    }
}

