<?php

declare(strict_types = 1);

namespace App\Mcp\Tools;

use App\Support\InvoiceLog;
use Throwable;

final class PersistSinkStaticGetMessage
{
    public function handle(Throwable $e): void
    {
        InvoiceLog::recordError('failed: ' . $e->getMessage());
    }
}
