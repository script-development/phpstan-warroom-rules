<?php

declare(strict_types = 1);

namespace App\Actions\Reporting;

use Carbon\CarbonImmutable;
use DateTime;
use DateTimeImmutable;

/**
 * Denominator fixture: every entry of `PARSING_METHODS` and
 * `PARSING_FUNCTIONS`, plus both native constructors and the static
 * `DateTime::createFromFormat` form, each on its own line — each handed a
 * STRING in its decoded slot.
 *
 * The three component factories here (`create`, `createFromDate`,
 * `createMidnightDate`) belong in this list because the string lands in
 * `$year`, and Carbon's `create()` delegates to `parse()` when `$year` is a
 * non-numeric string; handed integer components instead they are silent (see
 * `ComponentFactoriesAndRewraps`). The component factories that cannot reach
 * that branch at all — `createFromTime`, `createStrict`, `createSafe` — are on
 * `NON_DECODING_FACTORIES`, and `NonDecodingComponentFactories` hands each of
 * them a string to keep it that way.
 *
 * The point is not coverage for its own sake. A method or function name
 * dropped from either list — by an edit or by a mutation operator removing an
 * array item — is otherwise invisible: the remaining entries keep every other
 * assertion green, and the rule quietly stops seeing one shape. Naming each
 * entry exactly once turns any such removal into a failed assertion at a named
 * line.
 */
final class AllParsingMethods
{
    public function everyStaticMethod(string $raw): void
    {
        CarbonImmutable::parse($raw);
        CarbonImmutable::rawParse($raw);
        CarbonImmutable::parseFromLocale($raw);
        CarbonImmutable::createFromFormat('Y-m-d', $raw);
        CarbonImmutable::rawCreateFromFormat('Y-m-d', $raw);
        CarbonImmutable::createFromIsoFormat('YYYY-MM-DD', $raw);
        CarbonImmutable::createFromLocaleFormat('Y-m-d', 'nl', $raw);
        CarbonImmutable::createFromLocaleIsoFormat('YYYY-MM-DD', 'nl', $raw);
        CarbonImmutable::createFromTimeString($raw);
        CarbonImmutable::createFromDate($raw);
        CarbonImmutable::createMidnightDate($raw);
        CarbonImmutable::create($raw);
        CarbonImmutable::make($raw);
    }

    public function everyFunction(string $raw): void
    {
        strtotime($raw);
        date_create($raw);
        date_create_immutable($raw);
        date_parse($raw);
        date_parse_from_format('Y-m-d', $raw);
        date_create_from_format('Y-m-d', $raw);
        date_create_immutable_from_format('Y-m-d', $raw);
    }

    public function everyNativeShape(string $raw): void
    {
        new DateTime($raw);
        new DateTimeImmutable($raw);
        DateTime::createFromFormat('Y-m-d', $raw);
    }
}
