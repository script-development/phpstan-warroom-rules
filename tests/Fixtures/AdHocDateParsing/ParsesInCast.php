<?php

declare(strict_types = 1);

namespace App\Casts;

use Carbon\CarbonImmutable;

/**
 * The row-to-model decode — ADR-0020 Amd 1's second boundary door. The string
 * genuinely arrives from outside here and becomes a value object exactly once.
 */
final class ParsesInCast
{
    public function get(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value);
    }
}
