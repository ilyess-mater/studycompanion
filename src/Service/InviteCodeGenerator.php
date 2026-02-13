<?php

declare(strict_types=1);

namespace App\Service;

class InviteCodeGenerator
{
    public function generate(int $length = 8): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $maxIndex = strlen($alphabet) - 1;
        $token = '';

        for ($i = 0; $i < $length; ++$i) {
            $token .= $alphabet[random_int(0, $maxIndex)];
        }

        return $token;
    }
}
