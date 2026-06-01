<?php

declare(strict_types = 1);

namespace App\Actions\Widget;

use App\Audit\WidgetAuditLogger;
use App\Jobs\NotifyWidgetUpdated;
use App\Models\Widget;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Bus;

final readonly class ViolationBusDispatchInsideClosure
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
            Bus::dispatch(new NotifyWidgetUpdated($widget));
            $this->logger->logUpdated($widget);
        }, 3);
    }
}
