<?php

declare(strict_types = 1);

namespace App\Models\Audit;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A channel-log audit model whose short name ends in `EventLog`, NOT `AuditLog`
 * — the entreezuil / ublgenie shape (`AuthEventLog`, `SmsEventLog`,
 * `McpAccessLog`). It does not match the default `AuditLog` suffix, so it is
 * discovered purely by the `App\Models\Audit` NAMESPACE signal. Proves the
 * namespace leg catches audit models the suffix leg misses. Fires
 * enforceAuditModelProtections.hasFactoryForbidden.
 */
final class AuthEventLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;
}
