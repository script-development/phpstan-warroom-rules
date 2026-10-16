<?php

declare(strict_types = 1);

namespace App\ActionsLegacy;

use App\Audit\WidgetAuditLogger;
use App\Models\Widget;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * WR-1291. `App\ActionsLegacy` shares its opening characters with
 * `App\Actions` and is not inside it, so every Action-scoped rule must stay
 * silent here. Each class below is a shape one of those rules reports inside
 * `App\Actions`.
 */
final readonly class LegacyMultipleWritesNoTransaction
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function execute(object $a, object $b): void
    {
        $a->save();
        $b->save();
    }
}

final readonly class LegacyInjectsDatabaseManager
{
    public function __construct(
        private DatabaseManager $db,
    ) {}

    public function execute(): void
    {
        $this->db->connection()->statement('SELECT 1');
    }
}

final readonly class LegacyBareArrayReturn
{
    /**
     * @return array{secret: string}
     */
    public function execute(): array
    {
        return ['secret' => 's'];
    }
}

final readonly class LegacyThrowsHttpException
{
    public function execute(): void
    {
        throw new HttpException(422, 'Override already exists.');
    }
}

final readonly class LegacyCacheInsideTransaction
{
    public function __construct(
        private ConnectionInterface $db,
        private WidgetAuditLogger $logger,
    ) {}

    public function execute(Widget $widget): void
    {
        $this->db->transaction(function() use ($widget): void {
            $widget->refresh();
            $widget->save();
            Cache::put('widget.' . $widget->id, $widget);
            $this->logger->logUpdated($widget);
        }, 3);
    }
}

final readonly class LegacyUpdateMissingRefresh
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
