<?php

declare(strict_types = 1);

namespace App\Imports;

use App\Support\LeafReader;

final class NonLiteralFallback
{
    public function __construct(
        private LeafReader $reader,
    ) {}

    public function fromVariable(mixed $leaf, string $fallback): string
    {
        // A variable fallback may itself carry the failure forward — too
        // false-positive-rich to flag.
        return $this->reader->text($leaf) ?? $fallback;
    }

    public function fromCall(mixed $leaf): string
    {
        // Same for a call: the second read is a decision, not a literal sentinel.
        return $this->reader->text($leaf) ?? $this->reader->required($leaf);
    }
}
