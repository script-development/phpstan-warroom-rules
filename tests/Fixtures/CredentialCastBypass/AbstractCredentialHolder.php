<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

use Illuminate\Database\Eloquent\Model;

/**
 * Declares the credential cast on an ABSTRACT base; the concrete leaf inherits
 * it and must still be caught.
 */
abstract class AbstractCredentialHolder extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'passphrase' => 'hashed',
        ];
    }
}
