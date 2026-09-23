<?php

declare(strict_types = 1);

namespace App\Imports;

use App\Support\LeafReader;

final class UntypedParameterHelper
{
    public function __construct(
        private LeafReader $reader,
    ) {}

    public function name(mixed $leaf): string
    {
        // An untyped parameter is implicit `mixed` — the boundary tell. Fires.
        return $this->reader->raw($leaf) ?? '';
    }
}
