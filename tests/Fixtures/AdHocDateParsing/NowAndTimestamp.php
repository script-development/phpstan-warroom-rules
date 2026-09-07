<?php

declare(strict_types = 1);

namespace App\Actions\Reporting;

use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Deliberately in a NON-boundary namespace: what keeps this file silent is the
 * shape of each call, not where it lives. If the namespace gate were the only
 * thing holding these back, every assertion in the negative test would be
 * measuring the gate rather than the method set.
 */
final class NowAndTimestamp
{
    public function clockReads(): CarbonImmutable
    {
        CarbonImmutable::today();
        CarbonImmutable::yesterday();
        CarbonImmutable::tomorrow();

        return CarbonImmutable::now();
    }

    public function fromTimestamp(int $seconds): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($seconds);
    }

    public function nowNatively(): DateTimeImmutable
    {
        return new DateTimeImmutable;
    }

    public function movesAnAlreadyDecodedValue(CarbonImmutable $at): string
    {
        return $at->addDays(1)->startOfDay()->format('Y-m-d');
    }
}
