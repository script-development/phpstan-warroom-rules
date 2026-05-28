<?php

declare(strict_types = 1);

namespace App\Actions\Widget;

use App\Audit\WidgetAuditLogger;
use App\Models\Widget;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Storage;

final readonly class ViolationStorageDeleteInsideClosure
{
    public function __construct(
        private ConnectionInterface $db,
        private WidgetAuditLogger $logger,
    ) {}

    public function execute(Widget $widget): void
    {
        $this->db->transaction(function() use ($widget): void {
            $widget->refresh();
            $widget->delete();
            Storage::delete('widgets/' . $widget->id . '.json');
            $this->logger->logDeleted($widget);
        }, 3);
    }
}
