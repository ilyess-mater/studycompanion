<?php

declare(strict_types=1);

namespace App\Enum;

enum FocusSessionStatus: string
{
    case Scheduled = 'SCHEDULED';
    case Active = 'ACTIVE';
    case Completed = 'COMPLETED';
    case AutoSubmitted = 'AUTO_SUBMITTED';
}
