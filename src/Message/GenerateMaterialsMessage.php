<?php

declare(strict_types=1);

namespace App\Message;

final readonly class GenerateMaterialsMessage
{
    /**
     * @param list<string> $weakTopics
     */
    public function __construct(
        public int $lessonId,
        public array $weakTopics = [],
        public int $version = 1,
    ) {
    }
}
