<?php

declare(strict_types = 1);

// Stub classes referenced by InlineArrayJsonResponse fixtures. The rule scopes
// detection on:
//   - Containing-class namespace prefix (`controllerNamespacePrefixes`, default
//     `App\Http\Controllers`, via `$scope->getNamespace()`).
//   - AST shape: `new JsonResponse($payload, ...)` (exact-FQCN, NOT subclasses)
//     OR `response()->json($payload, ...)` (response() helper receiver + `json`).
//   - Type gate: `$payload`'s resolved type must be an array.
//
// composer.json does NOT require illuminate/http, so we stub the JsonResponse
// base + a dedicated subclass + the JsonResource base + the response() helper
// here with their real FQCNs — enough for PHPStan's FQCN resolution + array/
// type inference without a full illuminate/http install. Mirrors the sibling
// `ResourceWrappedInJsonResponse` fixtures' stub approach.

namespace Illuminate\Http {
    class JsonResponse
    {
        public function __construct(
            public mixed $data = null,
            public int $status = 200,
        ) {}
    }

    // Minimal response factory exposing json() + a non-json make(); the
    // response() helper returns it. make() exists so a fixture can prove the
    // rule matches ONLY the json() factory, not any response() method.
    class ResponseFactoryStub
    {
        public function json(mixed $data = null, int $status = 200): JsonResponse
        {
            return new JsonResponse($data, $status);
        }

        public function make(mixed $content = null, int $status = 200): JsonResponse
        {
            return new JsonResponse($content, $status);
        }
    }
}

namespace Illuminate\Http\Resources\Json {
    class JsonResource
    {
        public function __construct(
            public mixed $resource = null,
        ) {}

        public static function fromModel(object $model): static
        {
            return new static($model);
        }
    }
}

namespace App\Http\Responses {
    use Illuminate\Http\JsonResponse;

    // A dedicated JsonResponse SUBCLASS — the compliant fix. Constructing it
    // with an array payload must stay silent (exact-class match excludes it).
    final class NoContentResponse extends JsonResponse {}
}

namespace App\Http\Resources {
    use Illuminate\Http\Resources\Json\JsonResource;

    final class EmailResource extends JsonResource {}
}

namespace {
    use Illuminate\Http\ResponseFactoryStub;

    // The response() helper is normally declared in illuminate/foundation's
    // helpers file (not in vendor here). Stub it so fixtures can call
    // `response()->json(...)`.
    if (!\function_exists('response')) {
        function response(): ResponseFactoryStub
        {
            return new ResponseFactoryStub;
        }
    }
}
