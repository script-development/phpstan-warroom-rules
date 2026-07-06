<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Http\Responses\NoContentResponse;
use Illuminate\Http\JsonResponse;

final class SubclassArrayPayload
{
    public function store(): JsonResponse
    {
        // A dedicated JsonResponse SUBCLASS is the compliant fix — even with an
        // array payload it must stay silent (exact-FQCN match excludes it;
        // matching by supertype would criminalize the fix).
        return new NoContentResponse(['ignored' => true]);
    }
}
