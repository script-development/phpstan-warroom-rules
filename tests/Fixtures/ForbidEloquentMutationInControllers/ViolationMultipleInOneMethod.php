<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\User;

final class ViolationMultipleInOneMethod
{
    public function chaos(User $user, Post $post): void
    {
        $user->save();
        $post->delete();
        User::create(['name' => 'Whoops', 'email' => 'whoops@example.test']);
    }
}
