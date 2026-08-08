<?php

declare(strict_types = 1);

namespace App\Imports;

use App\Support\LeafReader;

final class StaticCallCoalesce
{
    public function name(mixed $leaf): string
    {
        // Same shape reached through a static call. Fires.
        return LeafReader::staticText($leaf) ?? '';
    }
}
