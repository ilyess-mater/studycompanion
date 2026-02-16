<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ThirdPartyProvider;
use App\Enum\ThirdPartyStatus;

class EntityThirdPartyLinkRecorder
{
    public function __construct(
        private readonly ThirdPartyMetaService $thirdPartyMetaService,
        private readonly SimpleEntityLinkService $simpleEntityLinkService,
    ) {
    }

    public function recordLinks(object $entity): void
    {
        $links = $this->simpleEntityLinkService->buildLinks($entity);

        $this->thirdPartyMetaService->record(
            $entity,
            ThirdPartyProvider::WebLink,
            $links === [] ? ThirdPartyStatus::Skipped : ThirdPartyStatus::Success,
            $links === [] ? 'No simple links available for entity.' : 'Simple external links attached.',
            ['links' => $links],
        );
    }
}

