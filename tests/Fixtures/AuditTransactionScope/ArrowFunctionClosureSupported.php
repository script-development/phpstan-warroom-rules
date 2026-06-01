<?php

declare(strict_types = 1);

namespace App\Actions\Widget;

use App\Audit\WidgetAuditLogger;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\ConnectionInterface;

final readonly class ArrowFunctionClosureSupported
{
    public function __construct(
        private ConnectionInterface $db,
        private WidgetAuditLogger $logger,
        private Session $session,
    ) {}

    public function execute(): void
    {
        // Arrow function form — single-expression body. The walker must
        // visit the expression body, not the closure's `stmts` array.
        $this->db->transaction(fn(): mixed => $this->session->put('k', 'v'));
    }
}
