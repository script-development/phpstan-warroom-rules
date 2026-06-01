<?php

declare(strict_types = 1);

namespace App\Actions\Auth;

use App\Audit\UserAuditLogger;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\ConnectionInterface;

final readonly class CompliantPostCommitMutation
{
    public function __construct(
        private ConnectionInterface $db,
        private UserAuditLogger $logger,
        private Session $session,
    ) {}

    public function execute(User $user): User
    {
        $result = $this->db->transaction(function() use ($user): User {
            $user->refresh();
            $user->last_login_at = now();
            $user->save();
            $this->logger->logUpdated($user);

            return $user;
        }, 3);

        // Post-commit: audit row is durable. Mutate non-transactional state.
        $this->session->put('last_user_id', $result->id);

        return $result;
    }
}
