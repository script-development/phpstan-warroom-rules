<?php

declare(strict_types = 1);

namespace App\Services\Untimed;

use Illuminate\Support\Facades\Http;

final class ViolationChainNoTimeout
{
    public function fetch(string $url, string $token): void
    {
        Http::withToken($token)->get($url);
    }
}
