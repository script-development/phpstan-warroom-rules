<?php

declare(strict_types = 1);

namespace App\Actions\Widget;

use App\Audit\WidgetAuditLogger;
use App\Mail\WidgetUpdated;
use App\Models\Widget;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\ConnectionInterface;

final readonly class ViolationMailSendInsideClosure
{
    public function __construct(
        private ConnectionInterface $db,
        private WidgetAuditLogger $logger,
        private Mailer $mailer,
    ) {}

    public function execute(Widget $widget): void
    {
        $this->db->transaction(function() use ($widget): void {
            $widget->refresh();
            $widget->save();
            $this->mailer->send(new WidgetUpdated($widget));
            $this->logger->logUpdated($widget);
        }, 3);
    }
}
