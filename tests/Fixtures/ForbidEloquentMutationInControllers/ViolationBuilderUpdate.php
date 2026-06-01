<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\User;

final class ViolationBuilderUpdate
{
    public function deactivateInactive(): int
    {
        // `User::query()` returns `Illuminate\Database\Eloquent\Builder<User>`.
        // The receiver type-check accepts any subtype of
        // `Illuminate\Database\Eloquent\Builder` — the generic parameter is
        // not unwrapped. `$builder->update([...])` returns int (rows affected)
        // and bypasses model events, audit observers, and the explicit-
        // hydration contract from ADR-0019.
        return User::query()
            ->where('email', 'inactive@example.test')
            ->update(['name' => 'INACTIVE']);
    }
}
