<?php

declare(strict_types = 1);

namespace App\Mcp\Tools;

use App\Support\ReportsToLogger;
use Throwable;

final class PsrLoggerGetMessage
{
    public function __construct(
        private ReportsToLogger $reporter,
    ) {}

    public function handle(Throwable $e): void
    {
        // Instance PSR-logger call — server-side logging, the remediation.
        // Never fires under the default config (a logger is not a sink); the
        // logger exclusion additionally holds it silent even if the logger
        // method is (mis)configured as a sink.
        $this->reporter->logger->error($e->getMessage());
    }
}
