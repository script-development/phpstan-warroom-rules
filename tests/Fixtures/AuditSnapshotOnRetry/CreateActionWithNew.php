<?php

declare(strict_types = 1);

namespace App\Actions\Foo;

use App\Audit\WidgetAuditLogger;
use App\Models\Widget;
use Illuminate\Database\ConnectionInterface;

final readonly class CreateActionWithNew
{
    public function __construct(
        private ConnectionInterface $connection,
        private WidgetAuditLogger $logger,
    ) {}

    public function execute(): void
    {
        $this->connection->transaction(function(): void {
            $model = new Widget;
            $model->save();
            $this->logger->logCreated($model);
        }, 3);
    }
}
