<?php

declare(strict_types = 1);

namespace App\Actions;

final readonly class NullableArrayReturn
{
    // `?array` — a nullable array is still an array escape hatch. Fires.
    public function execute(): ?array
    {
        return null;
    }
}
