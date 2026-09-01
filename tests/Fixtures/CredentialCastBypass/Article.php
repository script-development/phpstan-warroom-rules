<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

use Illuminate\Database\Eloquent\Model;

/**
 * No credential casts at all — every write against it is clean.
 */
class Article extends Model
{
    protected $table = 'articles';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }
}
