<?php

declare(strict_types = 1);

namespace App\Services\Untimed;

use Illuminate\Support\Facades\Http;

final class CompliantWithOptionsVariableTimeout
{
    public function fetch(string $url): void
    {
        // The same variable shape, timeout present — provably timed, silent.
        $options = ['timeout' => 5];

        Http::withOptions($options)->get($url);
    }
}
