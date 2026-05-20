<?php

declare(strict_types = 1);

use Illuminate\Support\Facades\DB;

final class TruncatesLogsViaConnection
{
    public function tamper(): void
    {
        DB::connection('central')->table('logs')->truncate();
    }
}
