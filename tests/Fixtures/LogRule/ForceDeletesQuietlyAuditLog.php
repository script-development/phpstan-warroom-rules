<?php

declare(strict_types = 1);

use App\Models\AuditLog;

final class ForceDeletesQuietlyAuditLog
{
    public function tamper(AuditLog $log): void
    {
        $log->forceDeleteQuietly();
    }
}
