<?php

declare(strict_types = 1);

use Illuminate\Support\Facades\DB;

final class TruncatesRegularTable
{
    public function reset(): void
    {
        DB::table('users')->truncate();
    }
}
