<?php

declare(strict_types = 1);

namespace App\Imports;

use App\Support\LeafReader;

final class NullFallback
{
    public function __construct(
        private LeafReader $reader,
    ) {}

    public function name(mixed $leaf): ?string
    {
        // `?? null` preserves the failure signal — never flagged.
        return $this->reader->text($leaf) ?? null;
    }
}
