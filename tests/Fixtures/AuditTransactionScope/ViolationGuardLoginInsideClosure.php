<?php

declare(strict_types = 1);

namespace App\Actions\Auth;

use App\Audit\UserAuditLogger;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\ConnectionInterface;

final readonly class ViolationGuardLoginInsideClosure
{
    public function __construct(
        private ConnectionInterface $db,
        private UserAuditLogger $logger,
        private StatefulGuard $guard,
    ) {}

    public function execute(User $user): void
    {
        $this->db->transaction(function() use ($user): void {
            $this->guard->login($user);
            $this->logger->logLoggedIn($user);
        }, 3);
    }
}
