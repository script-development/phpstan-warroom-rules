<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\Post;

final class ViolationInstanceForceDelete
{
    public function purge(Post $post): void
    {
        $post->forceDelete();
    }
}
