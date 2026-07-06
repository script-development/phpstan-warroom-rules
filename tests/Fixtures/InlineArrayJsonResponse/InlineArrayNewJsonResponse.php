<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class InlineArrayNewJsonResponse
{
    public function status(): JsonResponse
    {
        // The seed shape (kendo `TwoFactorController::status()`). Fires.
        return new JsonResponse(['enabled' => true, 'has_recovery_codes' => false]);
    }
}
