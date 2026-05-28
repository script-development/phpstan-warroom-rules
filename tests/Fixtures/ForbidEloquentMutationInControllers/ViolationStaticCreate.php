<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\User;

final class ViolationStaticCreate
{
    public function store(string $name, string $email): User
    {
        return User::create(['name' => $name, 'email' => $email]);
    }
}
