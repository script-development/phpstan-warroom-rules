<?php

declare(strict_types = 1);

namespace App\Imports;

use App\Support\LeafReader;

final class ShortTernaryUnknown
{
    public function __construct(
        private LeafReader $reader,
    ) {}

    public function name(mixed $leaf): string
    {
        // Short ternary elides the null test exactly like `??` does. Fires.
        return $this->reader->text($leaf) ?: 'unknown';
    }
}
