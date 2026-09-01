<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

use Illuminate\Database\Eloquent\Model;

/**
 * Declares NO casts of its own — every credential cast arrives through traits,
 * one directly and one via a trait-of-a-trait.
 */
class TraitCastModel extends Model
{
    use ComposesHashedSecret;
    use HasEncryptedNotesProperty;

    protected $table = 'trait_cast_models';
}
