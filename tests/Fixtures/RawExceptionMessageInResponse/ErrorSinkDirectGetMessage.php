<?php

declare(strict_types = 1);

namespace App\Mcp\Tools;

use Laravel\Mcp\Response;
use RuntimeException;

final class ErrorSinkDirectGetMessage
{
    public function handle(RuntimeException $e): Response
    {
        // Raw message passed directly (no concat) — receiver is a concrete
        // exception subtype of Throwable. Fires.
        return Response::error($e->getMessage());
    }
}
