<?php

declare(strict_types = 1);

use App\Models\AuditLog;

final class DestroysAuditLog
{
    public function tamper(): void
    {
        AuditLog::destroy([1]);
    }
}
