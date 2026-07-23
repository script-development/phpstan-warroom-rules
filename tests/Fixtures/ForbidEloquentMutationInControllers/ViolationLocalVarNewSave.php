<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\User;

final class ViolationLocalVarNewSave
{
    public function store(): User
    {
        // Receiver born inside the method body — the old `Class_`-scope walk
        // resolved `$user` to `mixed` and NEVER fired. Per-node registration
        // gives PHPStan the flow scope, so `$user` resolves to `App\Models\User`.
        $user = new User;
        $user->save();

        return $user;
    }
}
