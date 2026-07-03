<?php

declare(strict_types = 1);

namespace App\Models\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit model that FORGOT `const UPDATED_AT = null` — it inherits the framework
 * default `const UPDATED_AT = 'updated_at'`, so an audit row could be mutated on
 * write. This is the "forgot a protection" omission the inversion exists to
 * catch. Fires enforceAuditModelProtections.updatedAtNotDisabled.
 */
final class MutableAuditLog extends Model {}
