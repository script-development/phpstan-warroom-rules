<?php

declare(strict_types = 1);

namespace App\Services\Untimed;

use Illuminate\Support\Facades\Http;

final class ViolationBareStaticGet
{
    public function fetch(string $url): void
    {
        Http::get($url);
    }
}
