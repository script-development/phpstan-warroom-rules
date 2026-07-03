<?php

declare(strict_types = 1);

namespace App\Models\AuditReport;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A sibling-namespace model: `App\Models\AuditReport` starts with the literal
 * text `App\Models\Audit` but is NOT under the `App\Models\Audit\` namespace.
 * The trailing separator on the namespace match keeps it out of scope — it uses
 * HasFactory yet must NOT fire. Drops the separator and this over-matches. Pins
 * that the namespace prefix matches on a namespace boundary, not a text prefix.
 */
final class ReportSummary extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;
}
