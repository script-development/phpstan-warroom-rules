<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Http\Resources\EmailResource;
use Illuminate\Http\JsonResponse;

final class WrapsResourceInResponseJson
{
    public function store(object $email): JsonResponse
    {
        return response()->json(EmailResource::fromModel($email), 201);
    }
}
