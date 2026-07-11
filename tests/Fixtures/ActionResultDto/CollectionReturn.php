<?php

declare(strict_types = 1);

namespace App\Actions;

use Illuminate\Support\Collection;

final readonly class CollectionReturn
{
    // A `Collection` is a typed, method-bearing return — not a raw struct.
    // Silent (only `array` / `iterable` are the escape hatches this rule bans).
    public function execute(): Collection
    {
        return new Collection;
    }
}
