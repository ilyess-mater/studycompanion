<?php

declare(strict_types=1);

namespace App\Enum;

enum UserRole: string
{
    case Student = 'ROLE_STUDENT';
    case Teacher = 'ROLE_TEACHER';
}
