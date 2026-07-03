<?php

declare(strict_types = 1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A normal application model that legitimately uses HasFactory + SoftDeletes and
 * keeps a mutable updated_at. It matches NEITHER the `AuditLog` suffix nor the
 * `App\Models\Audit` namespace, so the rule must NOT fire — proving the
 * structural-identity gate keeps the rule off the ordinary model surface (the
 * whole point of scanning by shape rather than banning HasFactory everywhere).
 */
final class RegularPost extends Model
{
    use HasFactory;
    use SoftDeletes;
}
