<?php

declare(strict_types = 1);

namespace App\Models;

final class AuditLog
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
