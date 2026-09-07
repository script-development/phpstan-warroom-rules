<?php

declare(strict_types = 1);

namespace App\Actions\Reporting;

use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Deliberately in a NON-boundary namespace: every method and function named
 * here IS in the rule's lists, so what keeps this file silent is the argument
 * gate alone — no string (nor anything that could be one) is handed to the
 * call. Integer components assemble a date, an existing value object is
 * re-wrapped, and a zero-argument factory is a clock read.
 */
final class ComponentFactoriesAndRewraps
{
    public function fromIntegerComponents(int $year, int $month, int $day): void
    {
        CarbonImmutable::create(2_026, 9, 7);
        CarbonImmutable::create($year, $month, $day);
        CarbonImmutable::createFromDate(2_026, 9, 7);
        CarbonImmutable::createFromTime(9, 41, 0);
        CarbonImmutable::createStrict(2_026, 9, 7);
        CarbonImmutable::createSafe(2_026, 9, 7);
        CarbonImmutable::createFromDate($year, $month, $day, 'Europe/Amsterdam');
    }

    public function fromIntegerOrNullComponents(?int $year): void
    {
        CarbonImmutable::createFromDate($year);
    }

    public function rewrapsAnExistingValue(CarbonImmutable $at, DateTimeImmutable $native): void
    {
        CarbonImmutable::make($at);
        CarbonImmutable::parse($native);
        CarbonImmutable::instance($native);

        new CarbonImmutable($native);
    }

    public function zeroArgumentFactoriesAreClockReads(): void
    {
        CarbonImmutable::create();
        CarbonImmutable::parse();
        CarbonImmutable::make(null);

        new CarbonImmutable(null, 'Europe/Amsterdam');

        date_create();
        date_create_immutable();
    }

    /**
     * A first-class callable decodes nothing at THIS site — the string arrives
     * wherever the callable is invoked — and PHPStan does not deliver the node
     * to the rule at all. Accepted false negative; this line is the tripwire.
     *
     * @return callable(string): CarbonImmutable
     */
    public function firstClassCallable(): callable
    {
        return CarbonImmutable::parse(...);
    }
}
