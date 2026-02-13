<?php

declare(strict_types=1);

namespace App\Enum;

enum MasteryStatus: string
{
    case Mastered = 'MASTERED';
    case NeedsReview = 'NEEDS_REVIEW';
    case NotMastered = 'NOT_MASTERED';
}
