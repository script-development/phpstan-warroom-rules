<?php

declare(strict_types = 1);

namespace App\Services\Foo;

use App\Audit\WidgetAuditLogger;
use App\Models\Widget;
use Illuminate\Database\ConnectionInterface;

final readonly class NonActionClassWithTransaction
{
    public function __construct(
        private ConnectionInterface $connection,
        private WidgetAuditLogger $logger,
    ) {}

    public function execute(Widget $model): void
    {
        // App\Services\* — namespace gate excludes; no error even with
        // entity audit logger and non-compliant first statement.
        $this->connection->transaction(function() use ($model): void {
            $model->name = 'x';
            $model->save();
            $this->logger->logUpdated($model);
        }, 3);
    }
}
