<?php

declare(strict_types=1);

namespace App\Enum;

enum MaterialType: string
{
    case Summary = 'SUMMARY';
    case Flashcards = 'FLASHCARDS';
    case Explanation = 'EXPLANATION';
    case Example = 'EXAMPLE';
    case VideoRecommendation = 'VIDEO_RECOMMENDATION';
}
