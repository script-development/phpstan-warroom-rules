<?php

declare(strict_types = 1);

namespace App\Imports;

use App\Support\LeafReader;

final class NarrowedParameterHelper
{
    public function __construct(
        private LeafReader $reader,
    ) {}

    public function name(string $leaf): string
    {
        // `label(string $leaf)` takes no mixed parameter — it is not a boundary
        // helper, so its null is not an "unreadable input" signal.
        return $this->reader->label($leaf) ?? '';
    }
}
