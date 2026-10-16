<?php

declare(strict_types = 1);

namespace App\Http\ControllersLegacy;

use App\Http\Resources\EmailResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * WR-1291. `App\Http\ControllersLegacy` shares its opening characters with
 * `App\Http\Controllers` and is not inside it, so every controller-scoped rule
 * must stay silent here. Each method below is a shape one of those rules
 * reports inside `App\Http\Controllers`.
 */
final class LegacyController extends Controller
{
    public function inlineArray(): JsonResponse
    {
        return response()->json(['request_id' => 'r'], 202);
    }

    public function wrappedResource(object $email): JsonResponse
    {
        return response()->json(EmailResource::fromModel($email), 201);
    }

    public function mutatesModel(User $user): User
    {
        $user->save();

        return $user;
    }

    public function requestUser(Request $request): ?object
    {
        return $request->user();
    }
}
