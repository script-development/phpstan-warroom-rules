<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

/**
 * A trait declaring the cast map via the modern `casts()` method — the shape
 * Laravel models routinely use to compose credential handling. crit round 1,
 * issue 1: before the trait walk existed, a credential cast declared here was
 * invisible and silently exempted every model using the trait.
 */
trait HasHashedSecret
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trait_secret' => 'hashed',
        ];
    }
}
