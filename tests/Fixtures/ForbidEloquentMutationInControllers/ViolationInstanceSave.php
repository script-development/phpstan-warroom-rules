<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\User;

final class ViolationInstanceSave
{
    public function store(User $user): User
    {
        $user->name = 'Updated';
        $user->save();

        return $user;
    }
}
