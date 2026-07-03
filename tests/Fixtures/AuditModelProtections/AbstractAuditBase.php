<?php

declare(strict_types = 1);

namespace App\Models\Audit;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An abstract intermediate audit base that itself uses HasFactory. Abstract
 * classes are exempt (they are never instantiated as audit records) — this
 * class must NOT fire directly. Its concrete leaf `ConcreteInheritedAuditLog`
 * inherits HasFactory transitively and IS flagged, proving both the abstract
 * skip and the transitive trait walk.
 */
abstract class AbstractAuditBase extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;
}
