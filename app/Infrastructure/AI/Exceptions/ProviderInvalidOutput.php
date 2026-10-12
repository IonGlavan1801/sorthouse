<?php

namespace App\Infrastructure\AI\Exceptions;

final class ProviderInvalidOutput extends ProviderException
{
    public function __construct(string $message = 'Provider returned output that is not valid JSON for the requested schema')
    {
        parent::__construct($message, 'invalid_output', true);
    }
}
