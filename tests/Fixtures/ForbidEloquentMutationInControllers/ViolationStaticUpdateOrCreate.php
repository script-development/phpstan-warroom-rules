<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\User;

final class ViolationStaticUpdateOrCreate
{
    public function upsert(string $email, string $name): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            ['name' => $name],
        );
    }
}
