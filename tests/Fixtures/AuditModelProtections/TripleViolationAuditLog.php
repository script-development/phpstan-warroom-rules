<?php

declare(strict_types = 1);

namespace App\Models\Audit;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Audit model missing all three protections at once: HasFactory + SoftDeletes +
 * no `const UPDATED_AT = null`. Proves each protection fires independently — the
 * rule emits three separate errors on the same class line.
 */
final class TripleViolationAuditLog extends Model
{
    use HasFactory;
    use SoftDeletes;
}
