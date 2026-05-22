<?php

declare(strict_types = 1);

namespace App\Jobs;

use Illuminate\Support\Facades\Auth;

final class AuthInJob
{
    // Queue jobs run outside an HTTP request scope; authenticated user
    // resolution by attribute does not apply. Not a Controller descendant —
    // silent.
    public function handle(): ?object
    {
        return Auth::user();
    }
}
