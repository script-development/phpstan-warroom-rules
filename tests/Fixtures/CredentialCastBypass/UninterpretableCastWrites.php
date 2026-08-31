<?php

declare(strict_types = 1);

namespace App\Actions\CredentialCastBypass;

use App\Models\CredentialCastBypass\ConstantCastModel;
use App\Models\CredentialCastBypass\ConstantCastPropertyModel;

/**
 * Writes against models whose cast declarations are READABLE but not
 * interpretable. Both sites must report the incomplete-cast-map diagnostic
 * REGARDLESS of the payload — the payloads below name no credential column at
 * all, which is exactly the point: with an incomplete map the rule cannot claim
 * a payload is clean.
 */
final class UninterpretableCastWrites
{
    public function methodReturningAClassConstant(): void
    {
        ConstantCastModel::query()->update(['unrelated' => 'value']);
    }

    public function propertyDefaultingToAClassConstant(): void
    {
        ConstantCastPropertyModel::query()->update(['unrelated' => 'value']);
    }
}
