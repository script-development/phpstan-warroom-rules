<?php

declare(strict_types = 1);

namespace App\Services\Untimed;

use Illuminate\Support\Facades\Http;

final class ViolationWithOptionsVariableNoTimeout
{
    public function fetch(string $url): void
    {
        // A variable holding a literal array resolves to a constant array
        // type — the type-aware check sees through it and can PROVE the
        // 'timeout' key is absent. Fires (a widening the old AST-literal
        // check missed: it only looked at inline Array_ nodes).
        $options = ['verify' => false];

        Http::withOptions($options)->get($url);
    }
}
