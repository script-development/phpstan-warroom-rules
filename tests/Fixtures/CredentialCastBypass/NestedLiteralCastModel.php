<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

/**
 * The FALSE-POSITIVE direction of reading composed cast maps. Two literals must
 * NOT be read as `column => cast` pairs:
 *
 *   - a literal nested INSIDE the cast map (`'meta' => ['nested_secret' => …]`).
 *     Collecting array literals from anywhere inside a returned expression must
 *     stop at an array it already collected, or a nested value becomes a second
 *     cast map and every column named in it turns into a finding.
 *   - a literal inside a callback passed as an argument. It is that callback's
 *     return value, not this model's cast map.
 *
 * `real_secret` IS a cast and must still fire, so the fixture cannot pass by the
 * rule simply going blind on this model.
 */
class NestedLiteralCastModel extends AbstractCredentialHolder
{
    protected $table = 'nested_literal_cast_models';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), $this->decorate(static fn(): array => ['decoy_secret' => 'hashed']), [
            'real_secret' => 'hashed',
            'meta' => ['nested_secret' => 'hashed'],
        ]);
    }

    /**
     * @param callable(): array<string, string> $callback
     *
     * @return array<string, string>
     */
    private function decorate(callable $callback): array
    {
        return [];
    }
}
