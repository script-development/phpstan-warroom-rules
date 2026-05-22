<?php

declare(strict_types = 1);

namespace App\Actions;

use Illuminate\Support\Facades\Auth;

final readonly class AuthInAction
{
    // Actions handle authenticated-user resolution via constructor DI (or
    // explicit DTO passing). The class does not extend Controller — silent.
    public function execute(): ?object
    {
        return Auth::user();
    }
}
