<?php

declare(strict_types = 1);

namespace App\Actions\Widget;

use App\Audit\WidgetAuditLogger;
use App\Models\Widget;
use Closure;
use Illuminate\Database\ConnectionInterface;

final readonly class NonClosureTransactionArgumentSkipped
{
    public function __construct(
        private ConnectionInterface $db,
        private WidgetAuditLogger $logger,
    ) {}

    public function execute(Widget $widget, Closure $work): void
    {
        // First argument is a Closure variable, not a literal closure — out of
        // scope. The rule cannot inspect a variable's reaching definitions; this
        // shape is uncommon in audit-writing Actions and excluded deliberately.
        $this->db->transaction($work, 3);
    }
}
