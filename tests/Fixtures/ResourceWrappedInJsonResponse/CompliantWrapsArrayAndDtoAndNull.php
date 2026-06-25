<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\DataTransferObjects\EmailData;
use Illuminate\Http\JsonResponse;

final class CompliantWrapsArrayAndDtoAndNull
{
    public function array(): JsonResponse
    {
        // Plain message-envelope array — legit, type gate passes it.
        return response()->json(['message' => 'ok']);
    }

    public function dto(): JsonResponse
    {
        // DTO payload — legit.
        return response()->json(new EmailData('a@b.test'));
    }

    public function scalar(): JsonResponse
    {
        // Scalar — legit.
        return response()->json(true);
    }

    public function noContent(): JsonResponse
    {
        // response()->json(null, 204) — legit.
        return response()->json(null, 204);
    }
}
