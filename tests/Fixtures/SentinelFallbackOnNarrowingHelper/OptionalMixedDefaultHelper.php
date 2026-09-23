<?php

declare(strict_types = 1);

namespace App\Imports;

use App\Support\ConfigLookup;

final class OptionalMixedDefaultHelper
{
    public function __construct(
        private ConfigLookup $lookup,
    ) {}

    public function label(string $key): string
    {
        // `get(string $key, mixed $default = null): ?string` — the only mixed
        // parameter is OPTIONAL, so it is the caller's own default rather than
        // unvalidated external data. Not a boundary helper; stays silent.
        return $this->lookup->get($key) ?? '';
    }
}
