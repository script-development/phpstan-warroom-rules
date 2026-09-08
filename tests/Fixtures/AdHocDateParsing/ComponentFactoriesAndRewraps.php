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
        CarbonImmutable::createMidnightDate($year);
    }

    /**
     * The three factories the completeness gate added to `PARSING_METHODS` are
     * held back by the same argument gate as their siblings, not by a special
     * case. `createMidnightDate` takes integer components like `createFromDate`
     * it delegates to. `parseFromLocale` and `rawCreateFromFormat` DECLARE their
     * decoded slot `string`, so no provably-non-string value can be handed to
     * them — an ABSENT slot is the only silent shape they have, addressed here
     * by naming a different parameter.
     */
    public function newlyListedFactoriesWithNothingInTheSlot(): void
    {
        CarbonImmutable::createMidnightDate(2_026, 9, 8);

        CarbonImmutable::parseFromLocale(locale: 'nl');
        CarbonImmutable::rawCreateFromFormat(format: 'Y-m-d');
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
     * The first-class-callable tripwire, on both shapes the rule registers for.
     * Nothing is decoded here — the string arrives wherever the callable is
     * later invoked — and the rule never sees these calls: PHPStan substitutes
     * `StaticMethodCallableNode` and `FunctionCallableNode`, neither of which
     * is a `CallLike`, so a `CallLike` registration cannot reach `getArgs()`
     * with one. That substitution is an upstream fact, asserted directly in
     * `ForbidAdHocDateParsingRuleTest`; these lines are what starts reporting
     * if it ever stops holding.
     *
     * @return list<callable>
     */
    public function firstClassCallables(): array
    {
        return [
            CarbonImmutable::parse(...),
            CarbonImmutable::createFromFormat(...),
            strtotime(...),
            date_create(...),
        ];
    }
}
