<?php

declare(strict_types = 1);

namespace App\Http\Controllers\Concerns;

use App\Models\User;

trait MutatesUsers
{
    public function persist(): void
    {
        // Mutation inside a trait declared in a controllers namespace. Per-node
        // registration reaches trait bodies analysed through the using class —
        // the old `Class_` walk never did (a trait file has no `Class_` node).
        // Fires; the message names the USING class (call-site class reflection).
        $user = new User;
        $user->save();
    }
}

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\MutatesUsers;

final class ViolationInTraitFile
{
    use MutatesUsers;
}
