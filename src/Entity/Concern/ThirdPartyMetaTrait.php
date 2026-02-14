<?php

declare(strict_types=1);

namespace App\Entity\Concern;

use App\Enum\ThirdPartyProvider;
use App\Enum\ThirdPartyStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

trait ThirdPartyMetaTrait
{
    #[ORM\Column(name: 'third_party_meta', type: Types::JSON, nullable: true)]
    private ?array $thirdPartyMeta = null;

    public function getThirdPartyMeta(): ?array
    {
        return $this->thirdPartyMeta;
    }

    public function setThirdPartyMeta(?array $thirdPartyMeta): static
    {
        $this->thirdPartyMeta = $thirdPartyMeta;

        return $this;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function upsertIntegrationMeta(
        ThirdPartyProvider|string $provider,
        ThirdPartyStatus|string $status,
        ?string $externalId = null,
        ?int $latencyMs = null,
        string $message = '',
        array $payload = [],
    ): static {
        $providerKey = $provider instanceof ThirdPartyProvider ? $provider->value : strtoupper(trim((string) $provider));
        $statusValue = $status instanceof ThirdPartyStatus ? $status->value : strtoupper(trim((string) $status));

        $meta = $this->thirdPartyMeta ?? [];
        if (!isset($meta['integrations']) || !is_array($meta['integrations'])) {
            $meta['integrations'] = [];
        }

        $meta['integrations'][$providerKey] = [
            'status' => $statusValue,
            'externalId' => $externalId,
            'checkedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'latencyMs' => $latencyMs,
            'message' => $message,
            'payload' => $payload,
        ];

        $this->thirdPartyMeta = $meta;

        return $this;
    }
}

