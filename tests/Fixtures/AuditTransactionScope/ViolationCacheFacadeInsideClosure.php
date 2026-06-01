<?php

declare(strict_types = 1);

namespace App\Actions\Widget;

use App\Audit\WidgetAuditLogger;
use App\Models\Widget;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;

final readonly class ViolationCacheFacadeInsideClosure
{
    public function __construct(
        private ConnectionInterface $db,
        private WidgetAuditLogger $logger,
    ) {}

    public function execute(Widget $widget): void
    {
        $this->db->transaction(function() use ($widget): void {
            $widget->refresh();
            $widget->name = 'updated';
            $widget->save();
            Cache::put('widget.' . $widget->id, $widget);
            $this->logger->logUpdated($widget);
        }, 3);
    }
}
