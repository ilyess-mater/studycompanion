<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ThirdPartyProvider;
use App\Enum\ThirdPartyStatus;

class ThirdPartyMetaService
{
    /**
     * @param array<string, mixed> $payload
     */
    public function record(
        object $entity,
        ThirdPartyProvider|string $provider,
        ThirdPartyStatus|string $status,
        string $message = '',
        array $payload = [],
        ?string $externalId = null,
        ?int $latencyMs = null,
    ): void {
        if (method_exists($entity, 'upsertIntegrationMeta')) {
            $entity->upsertIntegrationMeta($provider, $status, $externalId, $latencyMs, $message, $payload);
        }
    }

    /**
     * @param array<string, mixed>|null $meta
     * @return array{total:int,success:int,failed:int,fallback:int,skipped:int,providers:list<string>}
     */
    public function summarize(?array $meta): array
    {
        $summary = [
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'fallback' => 0,
            'skipped' => 0,
            'providers' => [],
        ];

        if (!is_array($meta)) {
            return $summary;
        }

        $integrations = $meta['integrations'] ?? null;
        if (!is_array($integrations)) {
            return $summary;
        }

        $summary['total'] = count($integrations);
        $summary['providers'] = array_values(array_map(static fn (string $key): string => $key, array_keys($integrations)));

        foreach ($integrations as $integration) {
            if (!is_array($integration)) {
                continue;
            }

            $status = strtoupper((string) ($integration['status'] ?? ''));
            if ($status === ThirdPartyStatus::Success->value) {
                ++$summary['success'];
            } elseif ($status === ThirdPartyStatus::Failed->value) {
                ++$summary['failed'];
            } elseif ($status === ThirdPartyStatus::Fallback->value) {
                ++$summary['fallback'];
            } elseif ($status === ThirdPartyStatus::Skipped->value) {
                ++$summary['skipped'];
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    public function hasProvider(?array $meta, ThirdPartyProvider|string $provider): bool
    {
        if (!is_array($meta) || !isset($meta['integrations']) || !is_array($meta['integrations'])) {
            return false;
        }

        $providerKey = $provider instanceof ThirdPartyProvider ? $provider->value : strtoupper(trim((string) $provider));

        return array_key_exists($providerKey, $meta['integrations']);
    }
}

