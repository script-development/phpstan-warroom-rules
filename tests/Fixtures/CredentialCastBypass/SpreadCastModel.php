<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

/**
 * The other composition idiom — array spread. The returned node IS an array
 * literal, so this form always resolved; the fixture pins that it keeps
 * resolving, and that the spread item (no string key) contributes nothing
 * rather than confusing the pair reader.
 */
class SpreadCastModel extends AbstractCredentialHolder
{
    protected $table = 'spread_cast_models';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [...parent::casts(), 'spread_secret' => 'encrypted'];
    }
}
