<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class NoArgsAndNullPayload
{
    public function empty(): JsonResponse
    {
        // No arguments — a bare response is fine. Silent.
        return new JsonResponse;
    }

    public function noContent(): JsonResponse
    {
        // Explicit null payload (`new JsonResponse(null, 204)`) — not an array
        // type, passes the gate naturally. Silent. Steering null/204 toward a
        // NoContentResponse is a different rule's job.
        return new JsonResponse(null, 204);
    }
}
