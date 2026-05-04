<?php

declare(strict_types = 1);

use App\Models\AuditLog;

final class ForceDestroysAuditLog
{
    public function tamper(): void
    {
        AuditLog::forceDestroy([1]);
    }
}
