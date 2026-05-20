<?php

declare(strict_types = 1);

use Illuminate\Database\ConnectionInterface;

final readonly class TruncatesLogsViaInjectedDb
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function tamper(): void
    {
        // Includes a `where()` hop to exercise the chain-walk advance branch:
        // the walk starts at the `where(...)` MethodCall (not `table`/`from`),
        // advances to its `->var` which is the `$this->db->table('logs')`
        // MethodCall, and matches there.
        $this->db->table('logs')->where('id', 1)->truncate();
    }
}
