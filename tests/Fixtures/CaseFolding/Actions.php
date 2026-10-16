<?php

declare(strict_types = 1);

namespace App\Actions\CaseFolding;

use App\Audit\WidgetAuditLogger;
use App\Models\Widget;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * WR-1291. PHP dispatches method names case-insensitively, so each call below
 * is the call its lower-case spelling makes, and every Action-scoped rule must
 * read it that way.
 */
final readonly class UpperCaseWritesNoTransaction
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function execute(object $a, object $b): void
    {
        $a->SAVE();
        $b->Save();
    }
}

final readonly class UpperCaseTransactionAroundWrites
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function execute(object $a, object $b): void
    {
        $this->connection->TRANSACTION(function() use ($a, $b): void {
            $a->save();
            $b->save();
        });
    }
}

final readonly class UpperCaseCacheInsideTransaction
{
    public function __construct(
        private ConnectionInterface $db,
        private WidgetAuditLogger $logger,
    ) {}

    public function execute(Widget $widget): void
    {
        $this->db->Transaction(function() use ($widget): void {
            $widget->refresh();
            $widget->save();
            Cache::PUT('widget.' . $widget->id, $widget);
            $this->logger->logUpdated($widget);
        }, 3);
    }
}

final readonly class UpperCaseTransactionMissingRefresh
{
    public function __construct(
        private ConnectionInterface $connection,
        private WidgetAuditLogger $logger,
    ) {}

    public function execute(Widget $model): void
    {
        $this->connection->TRANSACTION(function() use ($model): void {
            $model->name = 'x';
            $model->save();
            $this->logger->logUpdated($model);
        }, 3);
    }
}

final readonly class UpperCaseStateResets
{
    public function __construct(
        private ConnectionInterface $connection,
        private WidgetAuditLogger $logger,
        private Widget $widget,
    ) {}

    public function refreshed(Widget $model): void
    {
        $this->connection->transaction(function() use ($model): void {
            $model->REFRESH();
            $this->logger->logUpdated($model);
        }, 3);
    }

    public function freshFetched(int $id): void
    {
        $this->connection->transaction(function() use ($id): void {
            $model = $this->widget->NewQuery()->FindOrFail($id);
            $this->logger->logDeleted($model);
        }, 3);
    }

    public function fresh(Widget $someExpr): void
    {
        $this->connection->transaction(function() use ($someExpr): void {
            $model = $someExpr->FRESH();
            $this->logger->logDeleted($model);
        }, 3);
    }

    public function newInstance(): void
    {
        $this->connection->transaction(function(): void {
            $model = $this->widget->NEWINSTANCE();
            $this->logger->logCreated($model);
        }, 3);
    }
}

final readonly class UpperCaseExecuteReturnsArray
{
    /**
     * @return array{secret: string}
     */
    public function EXECUTE(): array
    {
        return ['secret' => 's'];
    }
}

final readonly class UpperCaseStaticTransactionSessionPut
{
    public function __construct(
        private WidgetAuditLogger $logger,
        private Session $session,
    ) {}

    public function execute(Widget $widget): void
    {
        DB::TRANSACTION(function() use ($widget): void {
            $this->session->PUT('widget_id', $widget->id);
            $this->logger->logUpdated($widget);
        }, 3);
    }
}
