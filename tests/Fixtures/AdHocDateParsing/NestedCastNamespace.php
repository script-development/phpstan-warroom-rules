<?php

declare(strict_types = 1);

namespace App\Casts\Reporting;

use Carbon\CarbonImmutable;

/**
 * Control for `NamespacePrefixCollision`: a real SUB-namespace of a configured
 * boundary stays exempt. Requiring a separator must not cost the sub-namespace
 * match the parameter documents.
 */
final class NestedCastNamespace
{
    public function decode(string $raw): CarbonImmutable
    {
        return CarbonImmutable::parse($raw);
    }
}
