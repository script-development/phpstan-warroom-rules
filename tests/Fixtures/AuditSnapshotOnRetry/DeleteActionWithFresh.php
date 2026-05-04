<?php

declare(strict_types = 1);

namespace App\Actions\Foo;

use App\Audit\WidgetAuditLogger;
use App\Models\Widget;
use Illuminate\Database\ConnectionInterface;

final readonly class DeleteActionWithFresh
{
    public function __construct(
        private ConnectionInterface $connection,
        private WidgetAuditLogger $logger,
    ) {}

    public function execute(Widget $someExpr): void
    {
        $this->connection->transaction(function() use ($someExpr): void {
            $model = $someExpr->fresh();
            $this->logger->logDeleted($model);
            $model->delete();
        }, 3);
    }
}
