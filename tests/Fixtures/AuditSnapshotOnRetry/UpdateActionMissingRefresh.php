<?php

declare(strict_types = 1);

namespace App\Actions\Foo;

use App\Audit\WidgetAuditLogger;
use App\Models\Widget;
use Illuminate\Database\ConnectionInterface;

final readonly class UpdateActionMissingRefresh
{
    public function __construct(
        private ConnectionInterface $connection,
        private WidgetAuditLogger $logger,
    ) {}

    public function execute(Widget $model): void
    {
        $this->connection->transaction(function() use ($model): void {
            $model->name = 'x';
            $model->save();
            $this->logger->logUpdated($model);
        }, 3);
    }
}
