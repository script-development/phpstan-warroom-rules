<?php

declare(strict_types = 1);

namespace App\Actions\Foo;

use App\Audit\WidgetAuditLogger;
use App\Models\Widget;
use Illuminate\Database\ConnectionInterface;

final readonly class DeleteActionWithFreshFetch
{
    public function __construct(
        private ConnectionInterface $connection,
        private WidgetAuditLogger $logger,
        private Widget $widget,
    ) {}

    public function execute(int $id): void
    {
        $this->connection->transaction(function() use ($id): void {
            $model = $this->widget->newQuery()->findOrFail($id);
            $this->logger->logDeleted($model);
            $model->delete();
        }, 3);
    }
}
