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

    public function forceDelete(): bool
    {
        return true;
    }

    public function forceDeleteQuietly(): bool
    {
        return true;
    }
}
