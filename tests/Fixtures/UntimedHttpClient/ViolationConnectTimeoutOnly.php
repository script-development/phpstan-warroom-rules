<?php

declare(strict_types = 1);

namespace App\Services\Untimed;

use Illuminate\Support\Facades\Http;

final class ViolationConnectTimeoutOnly
{
    public function fetch(string $url): void
    {
        // connectTimeout bounds the handshake, not the response — a hung server
        // still stalls the caller. Doctrine #8 wants the request timeout.
        Http::connectTimeout(5)->get($url);
    }
}
