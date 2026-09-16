<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

use Illuminate\Database\Eloquent\Model;

/**
 * A `casts()` method form whose body is emptied when this file is routed
 * through PHPStan's `CleaningParser` — the AST a consumer gets for a model
 * outside the current invocation's analysed set (WR-1462).
 *
 * It declares a credential cast on purpose: the point of the guard is that a
 * write to `passphrase` must NOT come back clean merely because the body was
 * removed. Its own file, rather than a shared one, so stripping it cannot take
 * another fixture's body with it.
 */
class StrippedCastModel extends Model
{
    protected $table = 'stripped_cast_models';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['passphrase' => 'encrypted'];
    }
}
