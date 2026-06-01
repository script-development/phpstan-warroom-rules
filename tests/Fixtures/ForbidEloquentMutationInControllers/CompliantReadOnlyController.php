<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\User;

final class CompliantReadOnlyController
{
    public function show(int $id): ?User
    {
        // Read methods are deliberately permitted — controllers reading Models
        // is necessary for route-model binding, ResourceData hydration, and
        // policy checks.
        return User::query()
            ->where('id', $id)
            ->first();
    }

    public function index(): mixed
    {
        return User::query()
            ->where('email', 'foo@bar')
            ->paginate(25);
    }

    public function count(): int
    {
        return User::query()->count();
    }

    public function exists(int $id): bool
    {
        return User::query()->where('id', $id)->exists();
    }

    public function pluck(): mixed
    {
        return User::query()->pluck('email');
    }

    public function find(int $id): ?User
    {
        return User::find($id);
    }

    public function get(): mixed
    {
        return User::query()->get();
    }
}
