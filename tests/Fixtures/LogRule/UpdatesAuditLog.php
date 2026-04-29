<?php

declare(strict_types = 1);

use App\Models\AuditLog;

final class UpdatesAuditLog
{
    public function tamper(AuditLog $log): void
    {
        $log->update(['user_id' => 0]);
    }
}
