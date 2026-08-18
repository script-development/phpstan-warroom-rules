<?php

declare(strict_types = 1);

namespace App\Services\Untimed;

use Illuminate\Support\Facades\Http;

final class DeclinedMacroMidChain
{
    public function fetch(string $url, string $token): void
    {
        // Same macro guard for an INTERMEDIATE chain member — `PendingRequest`
        // is Macroable too, and the macro may set the timeout internally.
        Http::withToken($token)->github()->get($url);
    }
}
