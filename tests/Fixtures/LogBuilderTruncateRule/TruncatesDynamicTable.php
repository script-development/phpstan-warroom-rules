<?php

declare(strict_types = 1);

use Illuminate\Support\Facades\DB;

final class TruncatesDynamicTable
{
    public function reset(): void
    {
        $table = 'logs';
        DB::table($table)->truncate();
    }
}
