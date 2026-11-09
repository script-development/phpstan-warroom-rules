<?php

declare(strict_types = 1);

namespace App\Actions\Auth;

use App\Audit\UserAuditLogger;
use App\Models\User;
use Illuminate\Auth\AuthManager;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Session\SessionManager;

final readonly class ViolationInjectedFacadeServiceInsideClosure
{
    public function __construct(
        private ConnectionInterface $db,
        private UserAuditLogger $logger,
        private AuthManager $auth,
        private CacheManager $cache,
        private SessionManager $session,
    ) {}

    public function execute(User $user): void
    {
        $this->db->transaction(function() use ($user): void {
            $this->auth->login($user);
            $this->cache->put('user.' . $user->id, true);
            $this->session->put('user', $user->id);
            $this->logger->logLoggedIn($user);
        }, 3);
    }
}
