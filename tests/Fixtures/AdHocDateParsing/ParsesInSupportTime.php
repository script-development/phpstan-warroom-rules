<?php

declare(strict_types = 1);

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * The boundary door itself. Every call below is a violation ANYWHERE else and
 * is exactly what this namespace exists to hold — so a rule that fires here
 * has inverted its own gate.
 */
final class ParsesInSupportTime
{
    public function fromIsoString(string $raw): CarbonImmutable
    {
        return CarbonImmutable::parse($raw);
    }

    public function fromFormat(string $raw): CarbonImmutable|false
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $raw);
    }

    public function fromNative(string $raw): DateTimeImmutable
    {
        return new DateTimeImmutable($raw);
    }

    public function toTimestamp(string $raw): false|int
    {
        return strtotime($raw);
    }
}
