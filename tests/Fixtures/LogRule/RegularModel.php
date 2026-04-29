<?php

declare(strict_types = 1);

namespace App\Models;

final class RegularModel
{
    public function update(array $attributes): bool
    {
        return true;
    }

    public function delete(): bool
    {
        return true;
    }
}
