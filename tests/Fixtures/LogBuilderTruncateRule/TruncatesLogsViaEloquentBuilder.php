<?php

declare(strict_types = 1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Minimal Eloquent model. `AuditLog::query()` returns
 * `Illuminate\Database\Eloquent\Builder<AuditLog>`. The fixture exercises the
 * Eloquent\Builder branch of receiver-type detection. The chain hops through
 * `from('audit_logs')` (Eloquent\Builder's table-setting vocabulary, proxying
 * to Query\Builder->from()) — same intent as Query\Builder->table(), and
 * recognised by the rule's chain-walk.
 */
final class AuditLog extends Model
{
    protected $table = 'audit_logs';
}

final class TruncatesLogsViaEloquentBuilder
{
    public function tamper(): void
    {
        AuditLog::query()->from('audit_logs')->truncate();
    }
}
