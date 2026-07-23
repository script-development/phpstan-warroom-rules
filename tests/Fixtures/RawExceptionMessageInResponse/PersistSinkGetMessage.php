<?php

declare(strict_types = 1);

namespace App\Mcp\Tools;

use App\Support\InvoiceLog;
use Throwable;

final class PersistSinkGetMessage
{
    public function __construct(
        private InvoiceLog $log,
    ) {}

    public function handle(Throwable $e): void
    {
        // A consumer-configured PERSIST sink (instance call on a typed
        // receiver). Only fires when App\Support\InvoiceLog::recordError is
        // added to rawExceptionMessageSinks.
        $this->log->recordError('failed: ' . $e->getMessage());
    }
}
