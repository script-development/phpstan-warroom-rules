<?php

declare(strict_types = 1);

use App\Models\AuditLog;

final class DeletesAuditLog
{
    public function tamper(AuditLog $log): void
    {
        $log->delete();
    }
}
