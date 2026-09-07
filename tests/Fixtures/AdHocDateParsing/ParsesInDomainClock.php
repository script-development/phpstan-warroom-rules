<?php

declare(strict_types = 1);

namespace App\Domain\Clock;

use Carbon\CarbonImmutable;

/**
 * Configuration pin: silent ONLY when `dateParsingNamespaces` names
 * `App\Domain\Clock`, and flagged under the shipped default. Half of the proof
 * that the parameter is read rather than decorative — the other half is
 * `ParsesInSupportTime.php` going red under the same override.
 */
final class ParsesInDomainClock
{
    public function fromIsoString(string $raw): CarbonImmutable
    {
        return CarbonImmutable::parse($raw);
    }
}
