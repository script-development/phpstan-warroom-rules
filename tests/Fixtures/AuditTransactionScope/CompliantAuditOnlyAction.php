<?php

declare(strict_types = 1);

namespace App\Actions\Auth;

use App\Audit\UserAuditLogger;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\ConnectionInterface;

final readonly class CompliantAuditOnlyAction
{
    public function __construct(
        private ConnectionInterface $db,
        private UserAuditLogger $logger,
        private StatefulGuard $guard,
        private Session $session,
    ) {}

    public function execute(User $user): void
    {
        $this->db->transaction(function() use ($user): void {
            $this->logger->logLoggedOut($user);
        }, 3);

        // Post-commit: audit row is durable. Tear down non-transactional state.
        $this->guard->logout();
        $this->session->invalidate();
    }
}
