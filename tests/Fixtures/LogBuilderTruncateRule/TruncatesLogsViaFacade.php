<?php

declare(strict_types = 1);

use Illuminate\Support\Facades\DB;

final class TruncatesLogsViaFacade
{
    public function tamper(): void
    {
        DB::table('audit_logs')->truncate();
    }
}
