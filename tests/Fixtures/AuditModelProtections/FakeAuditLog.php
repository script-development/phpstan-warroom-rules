<?php

declare(strict_types = 1);

namespace App\Support;

/**
 * A non-model class named like an audit log (a DTO / value object / service).
 * It matches the `AuditLog` suffix signal but is NOT a subtype of
 * `Illuminate\Database\Eloquent\Model`, so the type gate excludes it. Without
 * that gate it would (wrongly) fire updatedAtNotDisabled, since it declares no
 * `UPDATED_AT`. Must NOT fire.
 */
final class FakeAuditLog
{
    public function __construct(
        public string $summary = '',
    ) {}
}
