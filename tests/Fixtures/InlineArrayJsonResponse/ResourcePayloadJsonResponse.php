<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Http\Resources\EmailResource;
use Illuminate\Http\JsonResponse;

final class ResourcePayloadJsonResponse
{
    public function show(object $email): JsonResponse
    {
        // A Resource payload is not an array type — the type gate passes it.
        // (The sibling `ForbidResourceWrappedInJsonResponseRule` is the rule
        // that polices this shape; this rule stays silent on it.)
        return new JsonResponse(EmailResource::fromModel($email));
    }
}
