<?php

namespace App\Infrastructure\AI\Exceptions;

use RuntimeException;

class ProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly string $kind = 'error', public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
