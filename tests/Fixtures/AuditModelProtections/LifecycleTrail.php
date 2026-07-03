<?php

declare(strict_types = 1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A territory-specific audit family whose short name ends in `Trail`, not
 * `AuditLog`, and lives outside `App\Models\Audit`. Under the DEFAULT parameters
 * it matches nothing and stays clean; a consumer that configures
 * `auditModelNameSuffixes: ['Trail']` brings it into scope and the HasFactory
 * violation fires. Proves the suffix parameter is honoured end-to-end.
 */
final class LifecycleTrail extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;
}
