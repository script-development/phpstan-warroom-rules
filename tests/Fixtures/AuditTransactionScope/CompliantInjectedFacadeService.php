<?php

declare(strict_types = 1);

namespace App\Actions\Auth;

use App\Audit\UserAuditLogger;
use App\Models\User;
use Illuminate\Auth\AuthManager;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\ConnectionInterface;

final readonly class CompliantInjectedFacadeService
{
    public function __construct(
        private ConnectionInterface $db,
        private UserAuditLogger $logger,
        private AuthManager $auth,
        private CacheManager $cache,
        private CompliantInjectedFacadeServiceLedger $ledger,
    ) {}

    public function execute(User $user): void
    {
        $this->db->transaction(function() use ($user): void {
            $this->auth->user();
            $this->cache->get('user.' . $user->id);
            $this->ledger->put('user', $user->id);
            $this->logger->logLoggedIn($user);
        }, 3);

        $this->auth->login($user);
        $this->cache->put('user.' . $user->id, true);
    }
}

final class CompliantInjectedFacadeServiceLedger
{
    public function put(string $key, int $value): void {}
}
