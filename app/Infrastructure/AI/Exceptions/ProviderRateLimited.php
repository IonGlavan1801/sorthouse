<?php

namespace App\Infrastructure\AI\Exceptions;

final class ProviderRateLimited extends ProviderException
{
    public function __construct(string $message = 'Provider rate limit (429)')
    {
        parent::__construct($message, 'rate_limited', true);
    }
}
