<?php

declare(strict_types = 1);

namespace App\Actions\Foo;

use App\Audit\WidgetAuditLogger;
use App\Cache\FakeCache;
use App\Models\Widget;

final readonly class UpdateActionWithUnrelatedTransaction
{
    public function __construct(
        private FakeCache $cache,
        private WidgetAuditLogger $logger,
    ) {}

    public function execute(Widget $model): void
    {
        // Receiver is a non-ConnectionInterface type — type-based gate skips
        // this call. No error.
        $this->cache->transaction(function() use ($model): void {
            $model->name = 'x';
            $model->save();
            $this->logger->logUpdated($model);
        });
    }
}
