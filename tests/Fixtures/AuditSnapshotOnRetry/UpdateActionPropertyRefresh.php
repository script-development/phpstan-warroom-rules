<?php

declare(strict_types = 1);

namespace App\Actions\Foo;

use App\Audit\WidgetAuditLogger;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;

final readonly class UpdateActionPropertyRefresh
{
    public function __construct(
        private ConnectionInterface $connection,
        private WidgetAuditLogger $logger,
        private User $user,
    ) {}

    public function execute(): void
    {
        $this->connection->transaction(function(): void {
            $this->user->refresh();
            $this->user->save();
            $this->logger->logUpdated($this->user);
        }, 3);
    }
}
