<?php

declare(strict_types = 1);

namespace App\Mcp\Tools;

use Laravel\Mcp\Response;
use Throwable;

final class ThrowableItselfIntoSink
{
    public function handle(Throwable $e): Response
    {
        // The Throwable itself passed into the sink — its message reaches the
        // client via the framework's string coercion. Fires.
        return Response::error($e);
    }
}
