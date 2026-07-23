<?php

declare(strict_types = 1);

namespace App\Mcp\Tools;

use Laravel\Mcp\Response;
use Throwable;

final class NullsafeGetMessage
{
    public function handle(?Throwable $e): Response
    {
        // The nullsafe sibling of the dominant shape — `?->` is a distinct
        // AST node (NullsafeMethodCall) but leaks identically when non-null.
        // Fires.
        return Response::error('Invalid input: ' . $e?->getMessage());
    }
}
