<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

/**
 * crit round 2, issue 1 — the mainstream Laravel composition idiom. The cast
 * map is built with `array_merge(parent::casts(), …)` rather than returned as a
 * literal, which the first implementation read as "declares nothing": the
 * credential cast added by the LEAF was silently invisible while the inherited
 * one was caught, so the same model was half-enforced.
 */
class ComposedCastModel extends AbstractCredentialHolder
{
    protected $table = 'composed_cast_models';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'composed_secret' => 'hashed',
            'composed_count' => 'integer',
        ]);
    }
}
