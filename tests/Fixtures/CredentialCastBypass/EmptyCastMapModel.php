<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

use Illuminate\Database\Eloquent\Model;

/**
 * A `casts()` that genuinely declares NOTHING — `return [];`, the readable
 * declaration of an empty map.
 *
 * The false-positive control for the stripped-body guard (WR-1462): the guard
 * fires when a `casts()` body carries no `return` at all, and an empty array
 * literal is a return. Treating this shape as unreadable would fire on every
 * castless model in every consumer.
 */
class EmptyCastMapModel extends Model
{
    protected $table = 'empty_cast_map_models';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }
}
