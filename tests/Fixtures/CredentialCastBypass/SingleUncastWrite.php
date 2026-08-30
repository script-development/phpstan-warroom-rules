<?php

declare(strict_types = 1);

namespace App\Actions\CredentialCastBypass;

use App\Models\CredentialCastBypass\Article;

/**
 * One builder write against a model carrying NO credential cast. Silent under a
 * working parser; the control for the unreadable-source diagnostic, which must
 * turn this same site loud when the model's PHP cannot be read.
 */
final class SingleUncastWrite
{
    public function write(): void
    {
        Article::query()->update(['published_at' => 'now']);
    }
}
