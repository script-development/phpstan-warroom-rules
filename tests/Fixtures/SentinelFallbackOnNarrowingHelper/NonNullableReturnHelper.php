<?php

declare(strict_types = 1);

namespace App\Imports;

use App\Support\LeafReader;

final class NonNullableReturnHelper
{
    public function __construct(
        private LeafReader $reader,
    ) {}

    public function name(mixed $leaf): string
    {
        // `required()` returns a non-nullable string — there is no failure
        // signal for a sentinel to hide.
        return $this->reader->required($leaf) ?? '';
    }
}
