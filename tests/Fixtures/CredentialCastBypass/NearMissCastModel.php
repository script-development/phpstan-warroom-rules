<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

use Illuminate\Database\Eloquent\Model;

/**
 * Cast values that merely START with a credential cast's letters without being
 * one. `encrypted` is credential-bearing exactly, and as the `encrypted:` prefix
 * form; `encryptedish` is a different cast and must not be matched.
 */
class NearMissCastModel extends Model
{
    protected $table = 'near_miss_cast_models';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blob' => 'encryptedish',
            'digest' => 'hashedish',
        ];
    }
}
