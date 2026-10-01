<?php

declare(strict_types = 1);

namespace App\Imports;

use App\Support\LeafReader;

final class NonScalarReturnHelper
{
    public function __construct(
        private LeafReader $reader,
    ) {}

    public function fallbackReader(mixed $leaf): LeafReader|string
    {
        // `reader()` returns a nullable OBJECT — a null-object fallback is a
        // different (and legitimate) pattern, out of this rule's scope.
        return $this->reader->reader($leaf) ?? '';
    }
}
