<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\User;

final class ViolationLocalVarBuilderUpdate
{
    public function deactivateInactive(): int
    {
        // Builder held in a method-local variable — distinct from the inline
        // `User::query()->...->update()` chain. The old `Class_`-scope walk
        // resolved `$query` to `mixed`; the flow scope resolves it to a
        // `Illuminate\Database\Eloquent\Builder` subtype.
        $query = User::query()->where('email', 'inactive@example.test');

        return $query->update(['name' => 'INACTIVE']);
    }
}
