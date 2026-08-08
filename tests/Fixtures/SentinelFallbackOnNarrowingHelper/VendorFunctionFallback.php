<?php

declare(strict_types = 1);

namespace App\Imports;

final class VendorFunctionFallback
{
    public function email(mixed $leaf): string
    {
        // A builtin/vendor function is outside the configured first-party
        // namespaces — its null contract is not ours to reason about.
        return \filter_var($leaf, \FILTER_VALIDATE_EMAIL) ?? '';
    }
}
