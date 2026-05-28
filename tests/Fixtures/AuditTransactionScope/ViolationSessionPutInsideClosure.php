<?php

declare(strict_types = 1);

namespace App\Actions\Auth;

use App\Audit\UserAuditLogger;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\ConnectionInterface;

final readonly class ViolationSessionPutInsideClosure
{
    public function __construct(
        private ConnectionInterface $db,
        private UserAuditLogger $logger,
        private Session $session,
    ) {}

    public function execute(User $user): void
    {
        $this->db->transaction(function() use ($user): void {
            $this->session->put('user_id', $user->id);
            $this->logger->logLoggedIn($user);
        }, 3);
    }
}
