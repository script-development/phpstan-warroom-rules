<?php

declare(strict_types = 1);

namespace App\Mcp\Tools;

use Throwable;

final class LoggerHelperGetMessage
{
    public function handle(Throwable $e): void
    {
        // `logger()->error(...)` — the helper form of server-side logging. The
        // logger exclusion must hold this silent EVEN when the logger method is
        // configured as a sink (the exclusion short-circuits before sink match).
        logger()->error($e->getMessage());
    }
}
