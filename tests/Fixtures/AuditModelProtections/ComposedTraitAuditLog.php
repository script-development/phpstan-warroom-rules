<?php

declare(strict_types = 1);

namespace App\Models\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * An audit model that reaches HasFactory only through a composed trait
 * (`ComposedFactoryTrait use HasFactory`), never directly and not via a parent
 * class. The transitive walk must recurse into traits-of-traits
 * (`getTraits(true)`) to catch it — a non-recursive lookup would miss the
 * violation. Fires enforceAuditModelProtections.hasFactoryForbidden.
 */
final class ComposedTraitAuditLog extends Model
{
    use ComposedFactoryTrait;

    public const UPDATED_AT = null;
}
