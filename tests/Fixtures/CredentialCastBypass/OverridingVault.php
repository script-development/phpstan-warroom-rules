<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

/**
 * REDECLARES the parent's credential cast as an ordinary one. Laravel merges
 * the maps with the child winning, so `passphrase` is no longer a credential
 * here and a builder write to it must stay silent — the fixture that pins the
 * merge DIRECTION rather than merely the fact that a merge happens.
 */
class OverridingVault extends AbstractCredentialHolder
{
    protected $table = 'overriding_vaults';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'passphrase' => 'string',
        ];
    }
}
