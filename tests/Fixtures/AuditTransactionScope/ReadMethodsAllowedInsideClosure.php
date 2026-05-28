<?php

declare(strict_types = 1);

namespace App\Actions\Widget;

use App\Audit\WidgetAuditLogger;
use App\Models\Widget;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

final readonly class ReadMethodsAllowedInsideClosure
{
    public function __construct(
        private ConnectionInterface $db,
        private WidgetAuditLogger $logger,
        private Session $session,
        private CacheRepository $cache,
    ) {}

    public function execute(Widget $widget): void
    {
        $this->db->transaction(function() use ($widget): void {
            // Reads are deliberately permitted — no rollback-vs-side-effect asymmetry.
            $actor = Auth::user();
            $context = $this->session->get('context');
            $cachedFlag = $this->cache->get('flag.' . $widget->id);
            $derived = Cache::get('derived.' . $widget->id);

            $widget->refresh();
            $widget->save();
            $this->logger->logUpdated($widget);
        }, 3);
    }
}
