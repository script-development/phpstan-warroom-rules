<?php

declare(strict_types = 1);

namespace App\Services\Swallowing;

use Error;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class Firing
{
    public bool $failed = false;

    public Collaborator $service;

    public function returnsNull(): ?string
    {
        try {
            return $this->service->fetch();
        } catch (Throwable $e) {
            return null;
        }
    }

    public function emptyWithoutVariable(): void
    {
        try {
            $this->service->fetch();
        } catch (Exception) {
        }
    }

    public function unionWithABroadMember(): bool
    {
        try {
            $this->service->fetch();
        } catch (ModelNotFoundException|Throwable $e) {
            return false;
        }

        return true;
    }

    public function recordsStateWithoutTheException(): void
    {
        try {
            $this->service->fetch();
        } catch (Error $e) {
            $this->failed = true;
        }
    }

    public function callsSomethingThatIsNotAReporter(): void
    {
        try {
            $this->service->fetch();
        } catch (Exception $e) {
            $this->service->error('fetch failed');
            Cache::forget('fetch');
            cleanup();
        }
    }

    public function onlyOverwritesTheCaughtVariable(): void
    {
        try {
            $this->service->fetch();
        } catch (Throwable $e) {
            $e = null;
        }
    }

    public function nestedClosureNeitherReportsNorReads(): void
    {
        try {
            $this->service->fetch();
        } catch (Throwable $e) {
            $retry = fn(): int => 1;
        }
    }
}

final class Collaborator
{
    public function fetch(): string
    {
        return '';
    }

    public function error(string $message): void {}
}

function cleanup(): void {}
