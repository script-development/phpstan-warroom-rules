<?php

declare(strict_types = 1);

namespace App\Mcp\Tools;

use App\Exceptions\DependentModelRelationException;
use Laravel\Mcp\Response;

final class SafeMessageExceptionThrowableItself
{
    public function handle(DependentModelRelationException $e): Response
    {
        // The allowlist covers the MESSAGE only — the Throwable itself still
        // stringifies with class, file, and trace, so this fires even when the
        // class is listed in `safeMessageExceptionClasses`.
        return Response::error($e);
    }
}
