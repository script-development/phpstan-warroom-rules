<?php

declare(strict_types = 1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Acceptable-miss negative fixture. `AuditLog::query()->from('audit_logs')->truncate()`
 * uses Eloquent's `from()` vocabulary to set the table rather than the
 * Query\Builder `table()` vocabulary. The rule's chain-walk recognises
 * `table()` only — `from()`-set tables are an acceptable miss in the same
 * family as variable table names. The receiver-type gate still passes
 * (Eloquent\Builder is a supported receiver), but the chain walk finds no
 * `table()` call and therefore does not fire.
 *
 * Model-property-driven tables (`$table = 'audit_logs'` on the Model itself,
 * with no `table()`/`from()` in the chain) are likewise an acceptable miss —
 * the table name never appears in the call chain.
 *
 * Rare-but-coherent shape `$eloquentBuilder->table('logs')->truncate()`
 * would still fire (Eloquent\Builder receiver + `table()` call in chain).
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
