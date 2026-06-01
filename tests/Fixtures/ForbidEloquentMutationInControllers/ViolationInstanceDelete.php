<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\Post;

final class ViolationInstanceDelete
{
    public function destroy(Post $post): void
    {
        $post->delete();
    }
}
