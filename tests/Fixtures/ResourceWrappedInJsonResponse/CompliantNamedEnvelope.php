<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Http\Resources\EmailResource;
use Illuminate\Http\JsonResponse;

final class CompliantNamedEnvelope
{
    public function index(object $email): JsonResponse
    {
        // Named-envelope edge (decided: EXCLUDE). A resource collection nested
        // under a named array key is a deliberate response contract, not a bare
        // double-wrap. The first argument is an array, not a JsonResource
        // subtype, so the type gate lets it through.
        return response()->json([
            'emails' => EmailResource::collect([$email]),
        ]);
    }
}
