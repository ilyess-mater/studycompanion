<?php

declare(strict_types=1);

namespace App\Enum;

enum ProcessingStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case Done = 'DONE';
    case Failed = 'FAILED';
    case PartialFallback = 'PARTIAL_FALLBACK';
}
