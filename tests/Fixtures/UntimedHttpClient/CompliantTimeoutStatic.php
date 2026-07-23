<?php

declare(strict_types = 1);

namespace App\Services\Untimed;

use Illuminate\Support\Facades\Http;

final class CompliantTimeoutStatic
{
    public function fetch(string $url): void
    {
        Http::timeout(5)->get($url);
    }
}
