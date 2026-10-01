<?php

declare(strict_types = 1);

namespace App\Imports;

use App\Support\LeafReader;

final class MethodCallCoalesceEmptyString
{
    public function __construct(
        private LeafReader $reader,
    ) {}

    public function gender(mixed $leaf): string
    {
        // The tc-api #360 shape — an unreadable node becomes an empty SET value
        // that gets written to the database. Fires.
        return $this->reader->text($leaf) ?? '';
    }
}
