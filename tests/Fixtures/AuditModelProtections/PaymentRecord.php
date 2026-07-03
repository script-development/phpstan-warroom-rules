<?php

declare(strict_types = 1);

namespace App\Ledger;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An audit model in a territory-specific namespace (`App\Ledger`) whose short
 * name matches no default suffix. Under the DEFAULT parameters it matches
 * nothing; a consumer that configures
 * `auditModelNamespacePrefixes: ['App\Ledger']` brings it into scope and the
 * HasFactory violation fires. Proves the namespace-prefix parameter is honoured
 * end-to-end.
 */
final class PaymentRecord extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;
}
