<?php

declare(strict_types=1);

namespace App\Enum;

enum LessonDifficulty: string
{
    case Easy = 'EASY';
    case Medium = 'MEDIUM';
    case Hard = 'HARD';
}
