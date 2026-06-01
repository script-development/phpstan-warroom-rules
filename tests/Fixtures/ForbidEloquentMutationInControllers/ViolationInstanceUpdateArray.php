<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\User;

final class ViolationInstanceUpdateArray
{
    public function update(User $user, string $name): User
    {
        $user->update(['name' => $name]);

        return $user;
    }
}
