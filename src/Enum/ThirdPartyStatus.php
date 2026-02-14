<?php

declare(strict_types=1);

namespace App\Enum;

enum ThirdPartyStatus: string
{
    case Success = 'SUCCESS';
    case Failed = 'FAILED';
    case Fallback = 'FALLBACK';
    case Skipped = 'SKIPPED';
}

