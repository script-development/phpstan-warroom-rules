<?php

declare(strict_types = 1);

namespace App\Actions\Auth;

use App\Audit\UserAuditLogger;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Filesystem\FilesystemManager;

use function array_map;

final readonly class ViolationMutationOutsideAfterCommit
{
    public function __construct(
        private ConnectionInterface $db,
        private UserAuditLogger $logger,
        private FilesystemManager $files,
        private ViolationMutationOutsideAfterCommitHooks $hooks,
    ) {}

    /**
     * @param list<string> $paths
     */
    public function execute(User $user, array $paths): void
    {
        $this->db->transaction(function() use ($user, $paths): void {
            $this->logger->logLoggedIn($user);
            $this->files->delete($paths);
            array_map(fn(string $path) => $this->files->delete($path), $paths);
            $this->hooks->afterCommit(fn() => $this->files->delete($paths));
            $this->db->afterCommit($this->files->delete($paths));
        }, 3);
    }
}

final class ViolationMutationOutsideAfterCommitHooks
{
    public function afterCommit(callable $callback): void {}
}
