<?php

declare(strict_types = 1);

namespace App\Actions\Auth;

use App\Audit\UserAuditLogger;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\DB;

final readonly class CompliantAfterCommitMutation
{
    public function __construct(
        private ConnectionInterface $db,
        private Connection $connection,
        private DatabaseManager $databaseManager,
        private UserAuditLogger $logger,
        private FilesystemManager $files,
    ) {}

    public function execute(User $user, string $path): void
    {
        $this->db->transaction(function() use ($user, $path): void {
            $this->logger->logLoggedIn($user);
            $this->db->afterCommit(fn() => $this->files->delete($path));
            $this->connection->afterCommit(fn() => $this->files->delete($path));
            $this->databaseManager->afterCommit(function() use ($path): void {
                $this->files->delete($path);
            });
            DB::afterCommit(fn() => $this->files->delete($path));
        }, 3);
    }
}
