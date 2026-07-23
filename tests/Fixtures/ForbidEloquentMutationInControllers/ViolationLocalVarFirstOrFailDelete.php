<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\Post;

final class ViolationLocalVarFirstOrFailDelete
{
    public function destroy(int $id): void
    {
        // `Post::query()->where(...)->firstOrFail()` returns a hydrated `Post`,
        // held in a method-local variable. The old `Class_`-scope walk could not
        // resolve this receiver; the flow scope now does. (`query()` is used
        // rather than the static `Post::where()` magic forward so the Builder
        // generic resolves under vanilla PHPStan — larastan is not loaded in the
        // fixture environment.)
        $post = Post::query()->where('id', $id)->firstOrFail();
        $post->delete();
    }
}
