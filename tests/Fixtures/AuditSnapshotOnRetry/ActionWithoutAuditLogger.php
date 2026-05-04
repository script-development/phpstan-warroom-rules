<?php

declare(strict_types = 1);

namespace App\Actions\Foo;

use App\Models\Widget;
use Illuminate\Database\ConnectionInterface;

final readonly class ActionWithoutAuditLogger
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function execute(Widget $model): void
    {
        // No audit logger injected → out of scope, no error even though
        // the closure body lacks a state reset.
        $this->connection->transaction(function() use ($model): void {
            $model->name = 'x';
            $model->save();
        }, 3);
    }
}
