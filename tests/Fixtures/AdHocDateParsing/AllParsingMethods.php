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
 * STRING in its decoded slot, because the component factories (`create`,
 * `createFromDate`, …) delegate to `parse()` when the year is a non-numeric
 * string and are silent on integer components (see
 * `ComponentFactoriesAndRewraps`).
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
        CarbonImmutable::createFromTime($raw);
        CarbonImmutable::create($raw);
        CarbonImmutable::make($raw);
        CarbonImmutable::createStrict($raw);
        CarbonImmutable::createSafe($raw);
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
