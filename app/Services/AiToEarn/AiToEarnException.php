<?php

namespace App\Services\AiToEarn;

use RuntimeException;
use Throwable;

class AiToEarnException extends RuntimeException
{
    public function __construct(
        string $message = '',
        private readonly ?int $businessCode = null,
        private readonly string $requestId = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function businessCode(): ?int
    {
        return $this->businessCode;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }
}
