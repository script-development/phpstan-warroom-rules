<?php

declare(strict_types = 1);

namespace App\Actions\Foo;

use App\Audit\AiOutboundLogger;
use App\Models\Widget;
use Illuminate\Database\ConnectionInterface;

final readonly class ActionWithChannelLoggerOnly
{
    public function __construct(
        private ConnectionInterface $connection,
        private AiOutboundLogger $logger,
    ) {}

    public function execute(Widget $model): void
    {
        // Only channel logger (ADR-0003 channel 1) injected → out of scope
        // even though the closure lacks a state reset.
        $this->connection->transaction(function() use ($model): void {
            $model->name = 'x';
            $model->save();
            $this->logger->log('payload');
        }, 3);
    }
}
