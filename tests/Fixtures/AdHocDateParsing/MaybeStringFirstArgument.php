<?php

declare(strict_types = 1);

namespace App\Actions\Reporting;

use Carbon\CarbonImmutable;

/**
 * The argument gate's DIRECTION. A first argument that is not provably
 * non-string fires: `mixed` and `int|string` are exactly the untyped input a
 * boundary type exists to pin down, and a rule that stayed silent on them
 * would exempt every `$request->input()` parse.
 */
final class MaybeStringFirstArgument
{
    public function untypedInput(mixed $raw): void
    {
        CarbonImmutable::create($raw);
        CarbonImmutable::createFromDate($raw);
        CarbonImmutable::make($raw);
    }

    public function unionInput(int|string $yearOrDate, ?string $maybe): void
    {
        CarbonImmutable::create($yearOrDate);
        CarbonImmutable::parse($maybe);

        new CarbonImmutable($maybe);
    }
}
