<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Http\Resources\EmailResource;
use Illuminate\Http\JsonResponse;

final class WrapsResourceInNewJsonResponse
{
    public function store(object $email): JsonResponse
    {
        return new JsonResponse(EmailResource::fromModel($email));
    }
}
