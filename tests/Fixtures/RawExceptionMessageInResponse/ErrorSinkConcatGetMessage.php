<?php

declare(strict_types = 1);

namespace App\Mcp\Tools;

use Laravel\Mcp\Response;
use Throwable;

final class ErrorSinkConcatGetMessage
{
    public function handle(Throwable $e): Response
    {
        // The dominant MCP-tool shape — raw exception message concatenated
        // into the client-facing error response. Fires.
        return Response::error('Invalid input: ' . $e->getMessage());
    }
}
