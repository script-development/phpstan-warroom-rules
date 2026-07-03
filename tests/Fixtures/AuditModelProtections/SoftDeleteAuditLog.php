<?php

declare(strict_types = 1);

namespace App\Models\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Audit model that uses SoftDeletes — audit logs are append-only and must never
 * be removed, soft or hard. Fires
 * enforceAuditModelProtections.softDeletesForbidden.
 */
final class SoftDeleteAuditLog extends Model
{
    use SoftDeletes;

    public const UPDATED_AT = null;
}
