<?php

declare(strict_types = 1);

namespace App\Actions\Reporting;

use Carbon\CarbonImmutable;

/**
 * The three component factories on `NON_DECODING_FACTORIES` that a reader would
 * expect to decode, in a NON-boundary namespace, each handed a string.
 *
 * Every line here is silent for a mechanism, not for a carve-out. `create()`
 * parses `$year` and nothing else, so `createFromTime()`'s `$hour` reaches
 * `sprintf()` as a component; `createStrict()` declares `?int`, so no string
 * arrives to parse; `createSafe()` rejects every component that is not an
 * `int` before it calls `create()`. `testNoAllowedFactoryCanDecodeAStringInADecodedSlot`
 * checks each of those claims by calling the factory rather than by reading it.
 *
 * A future author who re-lists any of these three as a decoder gets a red line
 * here instead of a silently widened rule — which is what this file is for,
 * because a line whose only property is silence is otherwise indistinguishable
 * from a line nobody wrote.
 *
 * The integer-component forms sit here too rather than in
 * `ComponentFactoriesAndRewraps`: that file's silence comes from the argument
 * gate alone, and these three are not in the rule's method table at all.
 */
final class NonDecodingComponentFactories
{
    public function handedAString(string $raw): void
    {
        CarbonImmutable::createFromTime($raw);
        CarbonImmutable::createStrict($raw);
        CarbonImmutable::createSafe($raw);
    }

    public function handedIntegerComponents(int $year, int $month, int $day): void
    {
        CarbonImmutable::createFromTime(9, 41, 0);
        CarbonImmutable::createStrict(2_026, 9, 9);
        CarbonImmutable::createSafe($year, $month, $day);
    }
}
