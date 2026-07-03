<?php

declare(strict_types = 1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An audit model living in plain `App\Models` (NOT under `App\Models\Audit`) —
 * the kendo shape, where `*AuditLog` models are scattered across `App\Models`
 * and `App\Models\Central`. It does not match the namespace signal, so it is
 * discovered purely by the `AuditLog` SUFFIX signal. Proves the suffix leg
 * catches audit models outside the audit namespace. Fires
 * enforceAuditModelProtections.hasFactoryForbidden.
 */
final class ScatteredAuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;
}
