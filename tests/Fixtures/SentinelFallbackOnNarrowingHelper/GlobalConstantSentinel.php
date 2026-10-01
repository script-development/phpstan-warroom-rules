<?php

declare(strict_types = 1);

namespace App\Imports;

use App\Support\LeafReader;

const LEAF_DEFAULT = 'n/a';

final class GlobalConstantSentinel
{
    public function __construct(
        private LeafReader $reader,
    ) {}

    public function name(mixed $leaf): string
    {
        // A bare global constant names one fixed, plausible value. Fires.
        return $this->reader->text($leaf) ?? LEAF_DEFAULT;
    }
}
