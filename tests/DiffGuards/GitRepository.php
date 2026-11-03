<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\DiffGuards;

use RuntimeException;

use function dirname;
use function is_resource;

/**
 * A throwaway repository under the system temp directory, built commit by commit.
 *
 * Every git call runs with GIT_DIR, GIT_INDEX_FILE and GIT_WORK_TREE removed and the
 * user's global config hidden, so a suite run from a git hook (which exports those) or on
 * a machine with `diff.renames=false` builds the repository the test describes.
 */
final class GitRepository
{
    public readonly string $root;

    public function __construct()
    {
        foreach (['GIT_DIR', 'GIT_INDEX_FILE', 'GIT_WORK_TREE'] as $variable) {
            putenv($variable);
        }

        putenv('GIT_CONFIG_GLOBAL=/dev/null');
        putenv('GIT_CONFIG_NOSYSTEM=1');

        $this->root = sys_get_temp_dir() . '/diff-guards-' . bin2hex(random_bytes(6));
        mkdir($this->root);
        $this->git('init', '--quiet', '--initial-branch=main');
    }

    public function __destruct()
    {
        $this->remove($this->root);
    }

    public function write(string $path, string $contents): self
    {
        $file = $this->root . '/' . $path;

        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o777, true);
        }

        file_put_contents($file, $contents);

        return $this;
    }

    public function delete(string $path): self
    {
        $this->git('rm', '--quiet', $path);

        return $this;
    }

    public function move(string $from, string $to): self
    {
        if (!is_dir(dirname($this->root . '/' . $to))) {
            mkdir(dirname($this->root . '/' . $to), 0o777, true);
        }

        $this->git('mv', $from, $to);

        return $this;
    }

    public function commit(string $message = 'change'): self
    {
        $this->git('add', '--all');
        $this->git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '--allow-empty', '-m', $message);

        return $this;
    }

    public function branch(string $name): self
    {
        $this->git('checkout', '--quiet', '-b', $name);

        return $this;
    }

    public function checkout(string $name): self
    {
        $this->git('checkout', '--quiet', $name);

        return $this;
    }

    public function git(string ...$arguments): string
    {
        $pipes = [];
        $process = proc_open(['git', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);

        if (!is_resource($process)) {
            throw new RuntimeException('git did not start');
        }

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new RuntimeException('git ' . implode(' ', $arguments) . " failed: {$err}");
        }

        return $out;
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }

            rmdir($path);

            return;
        }

        unlink($path);
    }
}
