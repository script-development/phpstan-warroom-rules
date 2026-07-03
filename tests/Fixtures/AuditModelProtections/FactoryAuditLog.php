<?php

declare(strict_types = 1);

namespace App\Models\Audit;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit model that uses HasFactory — the primary denylist-inversion target.
 * A factory is a direct-insert path bypassing the hash-chained writer. Fires
 * enforceAuditModelProtections.hasFactoryForbidden.
 */
final class FactoryAuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;
}
