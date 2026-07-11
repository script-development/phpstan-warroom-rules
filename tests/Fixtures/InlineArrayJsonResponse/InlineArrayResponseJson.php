<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class InlineArrayResponseJson
{
    public function accepted(string $requestId): JsonResponse
    {
        // The `response()->json([...])` factory twin (kendo
        // `ProjectIssueController:246` shape). Fires — the json() factory always
        // builds a base JsonResponse, so an array payload is in scope.
        return response()->json(['request_id' => $requestId], 202);
    }
}
