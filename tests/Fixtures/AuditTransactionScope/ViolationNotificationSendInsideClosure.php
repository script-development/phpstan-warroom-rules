<?php

declare(strict_types = 1);

namespace App\Actions\Widget;

use App\Audit\WidgetAuditLogger;
use App\Models\User;
use App\Models\Widget;
use App\Notifications\WidgetUpdatedNotification;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Notification;

final readonly class ViolationNotificationSendInsideClosure
{
    public function __construct(
        private ConnectionInterface $db,
        private WidgetAuditLogger $logger,
    ) {}

    public function execute(Widget $widget, User $user): void
    {
        $this->db->transaction(function() use ($widget, $user): void {
            $widget->refresh();
            $widget->save();
            Notification::send($user, new WidgetUpdatedNotification($widget));
            $this->logger->logUpdated($widget);
        }, 3);
    }
}
