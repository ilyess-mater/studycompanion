<?php

declare(strict_types=1);

namespace App\Message;

final readonly class GenerateQuizMessage
{
    /**
     * @param list<string> $focusTopics
     */
    public function __construct(
        public int $lessonId,
        public array $focusTopics = [],
    )
    {
    }
}
