<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Guard;

final readonly class InjectedAuthInController
{
    public function __construct(
        private AuthManager $authManager,
        private Guard $guard,
    ) {}

    public function show(): ?object
    {
        return $this->authManager->user();
    }

    public function update(): mixed
    {
        return $this->guard->user();
    }

    public function destroy(Guard $guard): mixed
    {
        return $guard->user();
    }
}
