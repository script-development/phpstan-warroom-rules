<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Illuminate\Auth\AuthManager;
use Illuminate\Support\Facades\Auth;

final readonly class InjectedAuthGuardChainInController
{
    public function __construct(
        private AuthManager $authManager,
        private InjectedAuthGuardChainAuditor $auditor,
    ) {}

    public function show(): mixed
    {
        return $this->authManager->guard('api')->user();
    }

    public function update(): mixed
    {
        return Auth::guard('api')->user();
    }

    public function destroy(): ?object
    {
        return $this->auditor->user();
    }
}

final class InjectedAuthGuardChainAuditor
{
    public function user(): ?object
    {
        return null;
    }
}
