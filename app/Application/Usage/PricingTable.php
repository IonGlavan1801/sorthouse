<?php

namespace App\Application\Usage;

final class PricingTable
{
    public static function version(): string
    {
        return config('sorthouse.pricing.version');
    }

    public static function estimate(string $model, int $inputTokens, int $outputTokens): float
    {
        $table = config('sorthouse.pricing.models');
        $rate = $table[$model] ?? $table['default'];

        return round(($inputTokens * $rate['input'] + $outputTokens * $rate['output']) / 1_000_000, 6);
    }
}
