<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\Post;

final class ViolationNullsafeDelete
{
    public function destroy(int $id): void
    {
        // `first()` returns `?Post`; the nullsafe `?->delete()` is a
        // `NullsafeMethodCall` (a sibling of `MethodCall` under `CallLike`).
        // `TypeCombinator::removeNull()` strips the `|null` so the Model gate
        // fires — without it, `Post|null` is only a `maybe()` Model supertype.
        $post = Post::query()->where('id', $id)->first();
        $post?->delete();
    }
}
