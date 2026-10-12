<?php

namespace App\Infrastructure\AI\Exceptions;

final class ProviderTimeout extends ProviderException
{
    public function __construct(string $message = 'Provider timed out')
    {
        parent::__construct($message, 'timeout', true);
    }
}
