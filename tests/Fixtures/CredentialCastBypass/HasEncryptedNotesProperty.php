<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

/**
 * The second declaration shape inside a trait — a `$casts` PROPERTY rather than
 * a `casts()` method.
 */
trait HasEncryptedNotesProperty
{
    /** @var array<string, string> */
    protected $casts = [
        'trait_notes' => 'encrypted',
    ];
}
