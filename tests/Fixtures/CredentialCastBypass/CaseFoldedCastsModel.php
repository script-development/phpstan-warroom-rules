<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

/**
 * WR-2188. Laravel calls `$this->casts()`, which PHP dispatches to `CASTS()`;
 * the leaf's own cast and the parent's composed one both apply.
 */
class CaseFoldedCastsModel extends AbstractCredentialHolder
{
    protected $table = 'case_folded_casts_models';

    /**
     * @return array<string, string>
     */
    protected function CASTS(): array
    {
        return array_merge(parent::CASTS(), [
            'folded_secret' => 'hashed',
        ]);
    }
}
