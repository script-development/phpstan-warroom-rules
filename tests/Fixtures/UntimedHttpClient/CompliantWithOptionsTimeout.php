<?php

declare(strict_types = 1);

namespace App\Services\Untimed;

use Illuminate\Support\Facades\Http;

final class CompliantWithOptionsTimeout
{
    public function fetch(string $url): void
    {
        Http::withOptions(['timeout' => 5])->get($url);
    }
}
