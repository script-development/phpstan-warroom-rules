<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

use Illuminate\Database\Eloquent\Model;

/**
 * Uses the trait but REDECLARES the cast on the class itself. PHP resolves a
 * class-declared member over a trait-imported one, so `trait_secret` is no
 * longer a credential column here and a builder write to it must stay silent.
 * Pins the trait-vs-class precedence DIRECTION, not merely that traits are read.
 */
class TraitOverriddenCastModel extends Model
{
    use HasHashedSecret;

    protected $table = 'trait_overridden_cast_models';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trait_secret' => 'string',
        ];
    }
}
