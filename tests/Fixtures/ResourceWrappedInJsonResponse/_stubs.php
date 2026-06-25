<?php

declare(strict_types = 1);

// Stub classes referenced by ResourceWrappedInJsonResponse fixtures. The rule
// scopes detection on:
//   - Containing-class namespace prefix (`App\Http\Controllers` via $scope->getNamespace())
//   - AST shape: `response()->json($payload, ...)` (response() helper FuncCall
//     receiver + `json` method) OR `new JsonResponse($payload, ...)`
//   - Type gate: $payload's resolved type must be a subtype of
//     `Illuminate\Http\Resources\Json\JsonResource`.
//
// composer.json does NOT require illuminate/http (the framework HTTP layer is
// not an analysis-time dependency of any shipped rule), so we stub the
// JsonResource base + JsonResponse + the response() helper here with their real
// FQCNs — enough for PHPStan's `ObjectType::isSuperTypeOf()` + return-type
// inference to resolve the relationships without pulling in a full
// illuminate/http install. Mirrors the CurrentUserAttribute fixtures' approach
// of stubbing `Illuminate\Http\Request` rather than requiring illuminate/http.

namespace Illuminate\Http\Resources\Json {
    class JsonResource
    {
        public function __construct(
            public mixed $resource,
        ) {}
    }
}

namespace Illuminate\Http {
    class JsonResponse
    {
        public function __construct(
            public mixed $data = null,
            public int $status = 200,
        ) {}
    }

    // Minimal response factory exposing json(); the response() helper returns it.
    class ResponseFactoryStub
    {
        public function json(mixed $data = null, int $status = 200): JsonResponse
        {
            return new JsonResponse($data, $status);
        }
    }
}

namespace App\Http\Resources {
    use Illuminate\Http\Resources\Json\JsonResource;

    final class EmailResource extends JsonResource
    {
        public static function fromModel(object $model): self
        {
            return new self($model);
        }

        /**
         * @param iterable<object> $models
         *
         * @return list<self>
         */
        public static function collect(iterable $models): array
        {
            $out = [];

            foreach ($models as $model) {
                $out[] = self::fromModel($model);
            }

            return $out;
        }
    }
}

namespace App\DataTransferObjects {
    final readonly class EmailData
    {
        public function __construct(
            public string $address,
        ) {}
    }
}

namespace {
    use Illuminate\Http\ResponseFactoryStub;

    // The response() helper is normally declared in illuminate/foundation's
    // helpers file (not in vendor here). Stub it so fixtures can call
    // `response()->json(...)`; the concrete return type carries a json() method
    // so PHPStan resolves the chain.
    if (!\function_exists('response')) {
        function response(): ResponseFactoryStub
        {
            return new ResponseFactoryStub;
        }
    }
}
