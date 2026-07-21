<?php

declare(strict_types = 1);

namespace App\Services\Untimed;

use Illuminate\Support\Facades\Http;

final class CompliantTimeoutMidChain
{
    /**
     * @param array<string, mixed> $data
     */
    public function push(string $url, string $token, array $data): void
    {
        Http::withToken($token)->timeout(5)->post($url, $data);
    }
}
