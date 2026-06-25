<?php

declare(strict_types = 1);

// Stub classes referenced by HttpExceptionInAction fixtures. The rule scopes
// detection on:
//   - Containing-class namespace prefix (`App\Actions` via $scope->getNamespace())
//   - Type-based thrown-expression matching: the thrown value's type must be a
//     subtype of `Symfony\Component\HttpKernel\Exception\HttpExceptionInterface`.
//
// composer.json does NOT require symfony/http-kernel (it is not an analysis-time
// dependency of any shipped rule), so we stub the Symfony HTTP-exception family
// here with their real FQCNs — enough of the hierarchy for PHPStan's
// `ObjectType::isSuperTypeOf()` to resolve the subtype relationship without
// pulling in a full symfony/http-kernel install. Mirrors the
// CurrentUserAttribute fixtures' approach of stubbing `Illuminate\Http\Request`
// rather than requiring illuminate/http.
//
// `Illuminate\Validation\ValidationException` is stubbed too, deliberately NOT
// implementing the Symfony interface, so the out-of-scope fixture proves the
// rule never fires on it.

namespace Symfony\Component\HttpKernel\Exception {
    interface HttpExceptionInterface extends \Throwable
    {
        public function getStatusCode(): int;
    }

    class HttpException extends \RuntimeException implements HttpExceptionInterface
    {
        public function __construct(
            private int $statusCode = 500,
            string $message = '',
        ) {
            parent::__construct($message);
        }

        public function getStatusCode(): int
        {
            return $this->statusCode;
        }
    }

    class NotFoundHttpException extends HttpException
    {
        public function __construct(string $message = '')
        {
            parent::__construct(404, $message);
        }
    }

    class UnprocessableEntityHttpException extends HttpException
    {
        public function __construct(string $message = '')
        {
            parent::__construct(422, $message);
        }
    }

    class AccessDeniedHttpException extends HttpException
    {
        public function __construct(string $message = '')
        {
            parent::__construct(403, $message);
        }
    }
}

namespace Illuminate\Validation {
    // Deliberately does NOT implement Symfony\...\HttpExceptionInterface — the
    // out-of-scope fixture relies on this to prove the rule ignores it.
    class ValidationException extends \RuntimeException
    {
        public function __construct(
            public object $validator,
        ) {
            parent::__construct('The given data was invalid.');
        }
    }
}

namespace App\Exceptions {
    // A custom domain exception — the compliant alternative. Plain Throwable,
    // not an HTTP-layer exception.
    class OverrideAlreadyExistsException extends \RuntimeException {}
}
