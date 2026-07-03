<?php

declare(strict_types = 1);

namespace App\Models\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * The canonical audit model: extends the real Eloquent Model, disables
 * updated_at via `const UPDATED_AT = null`, and uses neither HasFactory nor
 * SoftDeletes. Discovered by BOTH signals (namespace + suffix) — must NOT fire.
 */
final class CleanAuditLog extends Model
{
    public const UPDATED_AT = null;
}
