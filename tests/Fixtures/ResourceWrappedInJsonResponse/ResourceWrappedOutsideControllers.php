<?php

declare(strict_types = 1);

namespace App\Services;

use App\Http\Resources\EmailResource;
use Illuminate\Http\JsonResponse;

final class ResourceWrappedOutsideControllers
{
    public function build(object $email): JsonResponse
    {
        // Outside App\Http\Controllers — the namespace gate keeps the rule
        // silent. (Wrapping a resource in a non-controller is unusual but out
        // of this rule's scope by design.)
        return response()->json(EmailResource::fromModel($email));
    }
}
