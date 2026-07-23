<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\Post;

final class ViolationPlainNullableDelete
{
    public function destroy(int $id): void
    {
        // Plain `->delete()` (NO `?->`) on a nullable `?Post` receiver — pins the
        // `TypeCombinator::removeNull()` path: `Post|null` is only a `maybe()`
        // Model supertype, so without the null-strip the gate never fires.
        $post = Post::query()->where('id', $id)->first();
        $post->delete();
    }
}
