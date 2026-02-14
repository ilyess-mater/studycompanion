<?php

declare(strict_types=1);

namespace App\Exception;

class ThirdPartyClientException extends \RuntimeException
{
    public function __construct(
        private readonly string $provider,
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getProvider(): string
    {
        return $this->provider;
    }
}

