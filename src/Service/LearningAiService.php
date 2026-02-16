<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ThirdPartyProvider;
use App\Enum\ThirdPartyStatus;
use App\Service\Ai\GroqAiProvider;
use App\Service\Ai\LocalNlpAiProvider;
use App\Service\Ai\OpenAiAiProvider;

class LearningAiService
{
    public function __construct(
        private readonly OpenAiAiProvider $openAiProvider,
        private readonly GroqAiProvider $groqProvider,
        private readonly LocalNlpAiProvider $localProvider,
        private readonly string $aiProvider = 'openai',
        private readonly bool $aiStrictMode = false,
        private readonly string $aiFallbackProvider = 'groq_local',
    ) {
    }

    public function hasPrimaryProvider(): bool
    {
        return match ($this->configuredProviderKey()) {
            'openai' => $this->openAiProvider->hasProvider(),
            'groq' => $this->groqProvider->hasProvider(),
            default => true,
        };
    }

    /**
     * @return array{
     *     configured:string,
     *     active:string,
     *     primaryAvailable:bool,
     *     fallbackPolicy:string
     * }
     */
    public function providerState(): array
    {
        $configured = $this->configuredProviderKey();
        $primaryAvailable = $this->hasPrimaryProvider();
        $active = $this->resolveActiveProvider($configured, $primaryAvailable)->value;

        return [
            'configured' => $configured,
            'active' => $active,
            'primaryAvailable' => $primaryAvailable,
            'fallbackPolicy' => $this->fallbackPolicy(),
        ];
    }

    /**
     * @return array{
     *     data:array{
     *         topics:list<string>,
     *         keyConcepts:list<string>,
     *         difficulty:string,
     *         estimatedStudyMinutes:int,
     *         learningObjectives:list<string>
     *     },
     *     provider:ThirdPartyProvider,
     *     status:ThirdPartyStatus,
     *     fallbackUsed:bool,
     *     message:string,
     *     latencyMs:int
     * }
     */
    public function analyzeLesson(string $text): array
    {
        return $this->invoke('analyzeLesson', [$text], 'lesson analysis');
    }

    /**
     * @param list<string> $weakTopics
     * @return array{
     *     data:array{
     *         summary:string,
     *         flashcards:list<array{front:string, back:string}>,
     *         explanations:list<string>,
     *         examples:list<string>
     *     },
     *     provider:ThirdPartyProvider,
     *     status:ThirdPartyStatus,
     *     fallbackUsed:bool,
     *     message:string,
     *     latencyMs:int
     * }
     */
    public function generateMaterials(string $text, array $weakTopics = []): array
    {
        return $this->invoke('generateMaterials', [$text, $weakTopics], 'material generation');
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
     * @return array{
     *     data:list<array{text:string, options:list<string>, correctAnswer:string}>,
     *     provider:ThirdPartyProvider,
     *     status:ThirdPartyStatus,
     *     fallbackUsed:bool,
     *     message:string,
     *     latencyMs:int
     * }
     */
    public function generateQuizQuestions(string $text, int $count = 8, array $context = []): array
    {
        return $this->invoke('generateQuizQuestions', [$text, $count, $context], 'quiz generation');
    }

    /**
     * @param list<array{question:\App\Entity\Question, answer:string, isCorrect:bool, responseTimeMs:int}> $answerStats
     * @param array{
     *     lessonTitle?: string,
     *     lessonSubject?: string
     * } $context
     * @return array{
     *     data:array{
     *         score:float,
     *         weakTopics:list<string>,
     *         explanation:string
     *     },
     *     provider:ThirdPartyProvider,
     *     status:ThirdPartyStatus,
     *     fallbackUsed:bool,
     *     message:string,
     *     latencyMs:int
     * }
     */
    public function evaluateQuizSubmission(array $answerStats, array $context = []): array
    {
        return $this->invoke('evaluateQuizSubmission', [$answerStats, $context], 'quiz evaluation');
    }

    /**
     * @param list<string> $weakTopics
     * @return array{
     *     data:array{summary:string},
     *     provider:ThirdPartyProvider,
     *     status:ThirdPartyStatus,
     *     fallbackUsed:bool,
     *     message:string,
     *     latencyMs:int
     * }
     */
    public function summarizeWeakTopics(array $weakTopics, float $score, string $lessonTitle): array
    {
        return $this->invoke('summarizeWeakTopics', [$weakTopics, $score, $lessonTitle], 'weak-topic summary');
    }

    /**
     * @return array{
     *     data:array{tip:string},
     *     provider:ThirdPartyProvider,
     *     status:ThirdPartyStatus,
     *     fallbackUsed:bool,
     *     message:string,
     *     latencyMs:int
     * }
     */
    public function generateOnboardingTip(string $role, string $name, ?string $grade): array
    {
        return $this->invoke('generateOnboardingTip', [$role, $name, $grade], 'onboarding tip');
    }

    /**
     * @return array{
     *     data:array{
     *         tags:list<string>,
     *         hint:string
     *     },
     *     provider:ThirdPartyProvider,
     *     status:ThirdPartyStatus,
     *     fallbackUsed:bool,
     *     message:string,
     *     latencyMs:int
     * }
     */
    public function tagQuestionConcept(string $questionText, string $lessonContext, string $subject = ''): array
    {
        return $this->invoke('tagQuestionConcept', [$questionText, $lessonContext, $subject], 'question concept tagging');
    }

    /**
     * @return array{
     *     data:array{
     *         label:string,
     *         confidence:float
     *     },
     *     provider:ThirdPartyProvider,
     *     status:ThirdPartyStatus,
     *     fallbackUsed:bool,
     *     message:string,
     *     latencyMs:int
     * }
     */
    public function analyzeMisconception(string $questionText, string $correctAnswer, string $studentAnswer): array
    {
        return $this->invoke('analyzeMisconception', [$questionText, $correctAnswer, $studentAnswer], 'misconception analysis');
    }

    /**
     * @param list<mixed> $args
     * @return array{
     *     data:array|list<mixed>,
     *     provider:ThirdPartyProvider,
     *     status:ThirdPartyStatus,
     *     fallbackUsed:bool,
     *     message:string,
     *     latencyMs:int
     * }
     */
    private function invoke(string $method, array $args, string $feature): array
    {
        $configured = $this->configuredProviderKey();
        $primary = $this->primaryProvider($configured);
        $errorMessage = null;

        if ($primary['available']) {
            $start = microtime(true);
            try {
                /** @var array|list<mixed> $data */
                $data = $primary['provider']->{$method}(...$args);

                return [
                    'data' => $data,
                    'provider' => $primary['enum'],
                    'status' => ThirdPartyStatus::Success,
                    'fallbackUsed' => false,
                    'message' => ucfirst($feature).' generated by '.$this->providerLabel($primary['enum']).'.',
                    'latencyMs' => (int) ((microtime(true) - $start) * 1000),
                ];
            } catch (\Throwable $exception) {
                $errorMessage = $exception->getMessage();
                if ($this->aiStrictMode) {
                    throw $exception;
                }
            }
        } else {
            $errorMessage = ucfirst($configured).' key is missing.';
            if ($this->aiStrictMode) {
                throw new \RuntimeException('AI strict mode enabled: '.$errorMessage);
            }
        }

        $fallback = $this->secondaryProvider($configured);
        if ($fallback !== null && $fallback['available']) {
            $start = microtime(true);
            try {
                /** @var array|list<mixed> $fallbackData */
                $fallbackData = $fallback['provider']->{$method}(...$args);

                return [
                    'data' => $fallbackData,
                    'provider' => $fallback['enum'],
                    'status' => ThirdPartyStatus::Fallback,
                    'fallbackUsed' => true,
                    'message' => sprintf(
                        '%s unavailable (%s). %s fallback used for %s.',
                        ucfirst($configured),
                        $errorMessage ?? 'unknown error',
                        $this->providerLabel($fallback['enum']),
                        $feature,
                    ),
                    'latencyMs' => (int) ((microtime(true) - $start) * 1000),
                ];
            } catch (\Throwable $fallbackException) {
                $errorMessage = trim(($errorMessage ?? '').' '.$fallbackException->getMessage());
                if ($this->aiStrictMode) {
                    throw $fallbackException;
                }
            }
        }

        $start = microtime(true);
        /** @var array|list<mixed> $localData */
        $localData = $this->localProvider->{$method}(...$args);

        return [
            'data' => $localData,
            'provider' => ThirdPartyProvider::LocalNlp,
            'status' => $configured === 'local' ? ThirdPartyStatus::Success : ThirdPartyStatus::Fallback,
            'fallbackUsed' => $configured !== 'local',
            'message' => $configured === 'local'
                ? sprintf('Local NLP provider handled %s.', $feature)
                : sprintf(
                    '%s unavailable (%s). Local NLP fallback used for %s.',
                    ucfirst($configured),
                    $errorMessage ?? 'unknown error',
                    $feature,
                ),
            'latencyMs' => (int) ((microtime(true) - $start) * 1000),
        ];
    }

    private function configuredProviderKey(): string
    {
        $provider = strtolower(trim($this->aiProvider));

        return in_array($provider, ['openai', 'groq', 'local'], true) ? $provider : 'openai';
    }

    private function fallbackPolicy(): string
    {
        $policy = strtolower(trim($this->aiFallbackProvider));

        return in_array($policy, ['groq_local', 'local_only'], true) ? $policy : 'groq_local';
    }

    /**
     * @return array{
     *     provider:object,
     *     enum:ThirdPartyProvider,
     *     available:bool
     * }
     */
    private function primaryProvider(string $configured): array
    {
        return match ($configured) {
            'openai' => [
                'provider' => $this->openAiProvider,
                'enum' => ThirdPartyProvider::OpenAi,
                'available' => $this->openAiProvider->hasProvider(),
            ],
            'groq' => [
                'provider' => $this->groqProvider,
                'enum' => ThirdPartyProvider::GroqFree,
                'available' => $this->groqProvider->hasProvider(),
            ],
            default => [
                'provider' => $this->localProvider,
                'enum' => ThirdPartyProvider::LocalNlp,
                'available' => true,
            ],
        };
    }

    /**
     * @return array{
     *     provider:object,
     *     enum:ThirdPartyProvider,
     *     available:bool
     * }|null
     */
    private function secondaryProvider(string $configured): ?array
    {
        if ($configured === 'openai' && $this->fallbackPolicy() === 'groq_local') {
            return [
                'provider' => $this->groqProvider,
                'enum' => ThirdPartyProvider::GroqFree,
                'available' => $this->groqProvider->hasProvider(),
            ];
        }

        return null;
    }

    private function resolveActiveProvider(string $configured, bool $primaryAvailable): ThirdPartyProvider
    {
        if ($configured === 'local') {
            return ThirdPartyProvider::LocalNlp;
        }

        if ($primaryAvailable) {
            return $configured === 'openai' ? ThirdPartyProvider::OpenAi : ThirdPartyProvider::GroqFree;
        }

        $secondary = $this->secondaryProvider($configured);
        if ($secondary !== null && $secondary['available']) {
            return $secondary['enum'];
        }

        return ThirdPartyProvider::LocalNlp;
    }

    private function providerLabel(ThirdPartyProvider $provider): string
    {
        return match ($provider) {
            ThirdPartyProvider::OpenAi => 'OpenAI',
            ThirdPartyProvider::GroqFree => 'Groq',
            default => 'Local NLP',
        };
    }
}
