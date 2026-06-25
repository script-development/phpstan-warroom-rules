<?php

declare(strict_types = 1);

namespace App\Actions\Location;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final readonly class ThrowsTypedHttpExceptionValue
{
    public function execute(HttpExceptionInterface $error, bool $forbidden): void
    {
        // Throw of a value whose static type is an HTTP-exception subtype but
        // where no concrete exception class is constructed at the throw site —
        // an import-checking arch test (which keys on the `new XxxHttpException`
        // import) has nothing to catch here; the type-aware rule resolves the
        // thrown expression's type and fires.
        if ($forbidden) {
            throw $error;
        }
    }
}
