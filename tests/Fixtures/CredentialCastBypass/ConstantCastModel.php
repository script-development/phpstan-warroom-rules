<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

use Illuminate\Database\Eloquent\Model;

/**
 * A `casts()` return carrying NO array literal at all. The source is perfectly
 * readable — the DECLARATION SHAPE is what this rule cannot interpret, so the
 * cast map is incomplete and the rule must say so rather than read it as
 * "declares nothing". MISSING is not the same outcome as FAILED, and neither is
 * the same as CLEAN.
 *
 * @phpstan-ignore classConstant.unused
 */
class ConstantCastModel extends Model
{
    /** @var array<string, string> */
    private const array CASTS = ['constant_secret' => 'hashed'];

    protected $table = 'constant_cast_models';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return self::CASTS;
    }
}
