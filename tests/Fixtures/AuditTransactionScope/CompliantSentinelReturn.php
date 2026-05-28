<?php

declare(strict_types = 1);

namespace App\Actions\Auth;

use App\Audit\UserAuditLogger;
use App\Exceptions\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\ConnectionInterface;

final readonly class CompliantSentinelReturn
{
    public function __construct(
        private ConnectionInterface $db,
        private UserAuditLogger $logger,
        private StatefulGuard $guard,
    ) {}

    public function execute(string $email, string $password): User
    {
        $result = $this->db->transaction(function() use ($email): User|InvalidCredentialsException {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $this->logger->logLoginFailed(null, $email);

                return new InvalidCredentialsException;
            }

            $this->logger->logLoggedIn($user);

            return $user;
        }, 3);

        if ($result instanceof InvalidCredentialsException) {
            throw $result;
        }

        // Post-commit: audit row is durable. Mutate non-transactional state.
        $this->guard->login($result);

        return $result;
    }
}
