<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

use Illuminate\Database\Eloquent\Model;

/**
 * The property half of the same gap — a `$casts` default that is not an array
 * literal. Kept separate from ConstantCastModel so each declaration form is
 * pinned by its own fixture; a single fixture carrying both would pass with
 * either branch alone.
 *
 * @phpstan-ignore classConstant.unused
 */
class ConstantCastPropertyModel extends Model
{
    /** @var array<string, string> */
    private const array CASTS = ['constant_property_secret' => 'encrypted'];

    protected $table = 'constant_cast_property_models';

    /** @var array<string, string> */
    protected $casts = self::CASTS;
}
