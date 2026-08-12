<?php

declare(strict_types = 1);

namespace App\Imports;

use App\Support\LeafReader;

final class MethodCallCoalesceZero
{
    public function __construct(
        private LeafReader $reader,
    ) {}

    public function quantity(mixed $leaf): int
    {
        // An unreadable quantity becomes a real, persistable 0. Fires.
        return $this->reader->number($leaf) ?? 0;
    }
}
