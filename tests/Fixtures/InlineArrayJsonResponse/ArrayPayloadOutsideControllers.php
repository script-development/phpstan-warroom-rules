<?php

declare(strict_types = 1);

namespace App\Services;

use Illuminate\Http\JsonResponse;

final class ArrayPayloadOutsideControllers
{
    public function build(): JsonResponse
    {
        // Outside the controller namespace prefixes — the namespace gate keeps
        // the rule silent.
        return new JsonResponse(['enabled' => true]);
    }
}
