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

    /**
     * A static method OUTSIDE the parsing set, handed a string. The argument
     * gate would let this through; only the method-name check holds it back,
     * so this is what keeps that check from being masked by the gate.
     */
    public function staticHelpersWithAString(string $raw): bool
    {
        CarbonImmutable::setLocale('nl');

        return CarbonImmutable::hasFormat($raw, 'Y-m-d');
    }

    public function movesAnAlreadyDecodedValue(CarbonImmutable $at): string
    {
        return $at->addDays(1)->startOfDay()->format('Y-m-d');
    }
}
