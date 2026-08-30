<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modern Laravel `casts()` method form, mixing a credential cast, an
 * `encrypted:*` variant and an ordinary cast that must never fire.
 */
class User extends Model
{
    protected $table = 'users';

    /**
     * @return HasMany<ApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'api_token' => 'encrypted',
            'recovery_codes' => 'encrypted:array',
            'login_count' => 'integer',
        ];
    }
}
