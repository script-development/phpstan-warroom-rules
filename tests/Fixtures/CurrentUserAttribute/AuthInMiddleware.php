<?php

declare(strict_types = 1);

namespace App\Http\Middleware;

use Illuminate\Support\Facades\Auth;

final class AuthInMiddleware
{
    // Middleware does not extend Illuminate\Routing\Controller — silent.
    public function handle(): ?object
    {
        return Auth::user();
    }
}
