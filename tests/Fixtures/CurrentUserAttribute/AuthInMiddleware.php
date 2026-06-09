<?php

declare(strict_types = 1);

namespace App\Http\Middleware;

use Illuminate\Support\Facades\Auth;

final class AuthInMiddleware
{
    // `App\Http\Middleware` namespace does not start with the controller
    // prefix — silent.
    public function handle(): ?object
    {
        return Auth::user();
    }
}
