<?php

declare(strict_types = 1);

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\Log;
use Throwable;

final class LogsGetMessage
{
    public function handle(Throwable $e): void
    {
        // Server-side logging of the raw message is the REMEDIATION, never a
        // leak. None of these fire — Log::/logger()/report() are not client-
        // facing sinks (and are excluded before sink matching regardless).
        Log::error('Handler failed', ['exception' => $e->getMessage()]);
        logger()->error($e->getMessage());
        report($e);
    }
}
