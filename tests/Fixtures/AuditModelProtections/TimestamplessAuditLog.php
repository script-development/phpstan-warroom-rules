<?php

declare(strict_types = 1);

namespace App\Models\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * An audit model that disables timestamps WHOLESALE (`public $timestamps =
 * false;`) instead of declaring `const UPDATED_AT = null` — a model that never
 * writes `updated_at` (or `created_at`) at all, e.g. one whose recorded-at
 * instant is a domain column filled by the writer. The rule recognises the
 * disabled-timestamps shape natively as satisfying the updated_at protection —
 * no per-file `ignoreErrors` suppression needed. Must NOT fire.
 */
final class TimestamplessAuditLog extends Model
{
    public $timestamps = false;
}
