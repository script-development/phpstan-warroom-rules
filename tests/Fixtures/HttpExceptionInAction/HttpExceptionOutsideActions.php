<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class HttpExceptionOutsideActions
{
    public function store(bool $exists): void
    {
        // Controllers (and FormRequests, exception renderers, middleware) may
        // raise HTTP-layer exceptions — the namespace gate keeps the rule out
        // of `App\Http\Controllers`.
        if ($exists) {
            throw new HttpException(422, 'Override already exists.');
        }
    }
}
