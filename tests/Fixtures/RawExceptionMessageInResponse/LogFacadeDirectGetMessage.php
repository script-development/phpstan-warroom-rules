<?php

declare(strict_types = 1);

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\Log;
use Throwable;

final class LogFacadeDirectGetMessage
{
    public function handle(Throwable $e): void
    {
        // `Log::error($e->getMessage())` — direct raw message to the Log facade.
        // Server-side logging; the static-logger exclusion must hold it silent
        // EVEN when Log::error is configured as a sink.
        Log::error($e->getMessage());
    }
}
