<?php

declare(strict_types = 1);

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * WR-1291. PHP dispatches method names case-insensitively, so each call below
 * tampers with a log exactly as its lower-case spelling does.
 */
final class UpperCaseLogTampering
{
    public function deletes(AuditLog $log): void
    {
        $log->DELETE();
    }

    public function destroys(): void
    {
        AuditLog::Destroy([1]);
    }

    public function truncates(): void
    {
        DB::connection('central')->TABLE('logs')->TRUNCATE();
    }
}
