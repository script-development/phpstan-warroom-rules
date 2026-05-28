<?php

declare(strict_types = 1);

namespace App\Services\Widget;

use App\Audit\WidgetAuditLogger;
use App\Models\Widget;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\ConnectionInterface;

final readonly class NonActionNamespaceSkipped
{
    public function __construct(
        private ConnectionInterface $db,
        private WidgetAuditLogger $logger,
        private StatefulGuard $guard,
    ) {}

    public function execute(Widget $widget): void
    {
        // App\Services\* — namespace gate excludes; no error even with
        // a blocklist mutation method inside the closure.
        $this->db->transaction(function() use ($widget): void {
            $this->guard->logout();
            $widget->save();
            $this->logger->logUpdated($widget);
        }, 3);
    }
}
