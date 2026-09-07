<?php

declare(strict_types = 1);

namespace App\CastsReport;

use Carbon\CarbonImmutable;

/**
 * `App\CastsReport` shares a character prefix with the configured `App\Casts`
 * boundary without being inside it. A prefix test that does not require a
 * namespace separator exempts this class and every other one that merely starts
 * with the same letters.
 */
final class NamespacePrefixCollision
{
    public function decode(string $raw): CarbonImmutable
    {
        return CarbonImmutable::parse($raw);
    }
}
