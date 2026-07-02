<?php

declare(strict_types = 1);

namespace App\Http\Client\Controllers;

use App\Http\Resources\EmailResource;
use Illuminate\Http\JsonResponse;

// Sub-namespaced controller fixture (emmie's `App\Http\Client\Controllers`
// shape). This namespace does NOT start with the default `App\Http\Controllers`
// prefix, so the resource-wrap is INVISIBLE under the default config (CLEAN)
// and is only FLAGGED once a consumer opts the prefix in via
// `controllerNamespacePrefixes`. Proves the namespace gate is configurable
// while the default stays byte-for-byte the prior behaviour.
final class SubNamespacedClientController
{
    public function store(object $email): JsonResponse
    {
        return response()->json(EmailResource::fromModel($email), 201);
    }
}
