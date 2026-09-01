<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy `$casts` PROPERTY form — the second declaration shape the rule reads.
 */
class ApiKey extends Model
{
    protected $table = 'api_keys';

    /** @var array<string, string> */
    protected $casts = [
        'secret' => 'encrypted',
        'label' => 'string',
    ];
}
