<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class NonJsonResponseFactoryMethod
{
    public function make(): JsonResponse
    {
        // A NON-json response() factory method with an array argument. The rule
        // matches only `response()->json(...)`, not any `response()` method, so
        // this must stay silent — pins the method-name specificity of the gate.
        return response()->make(['x' => 1]);
    }
}
