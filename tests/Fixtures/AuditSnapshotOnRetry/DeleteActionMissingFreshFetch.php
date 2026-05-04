<?php

declare(strict_types = 1);

namespace App\Actions\Foo;

use App\Audit\WidgetAuditLogger;
use App\Models\Widget;
use Illuminate\Database\ConnectionInterface;

final readonly class DeleteActionMissingFreshFetch
{
    public function __construct(
        private ConnectionInterface $connection,
        private WidgetAuditLogger $logger,
    ) {}

    public function execute(Widget $model): void
    {
        $this->connection->transaction(function() use ($model): void {
            $model->delete();
            $this->logger->logDeleted($model);
        }, 3);
    }
}
