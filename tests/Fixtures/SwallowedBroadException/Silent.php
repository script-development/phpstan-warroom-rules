<?php

declare(strict_types = 1);

namespace App\Services\Swallowing;

use Closure;
use Error;
use Exception;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class Silent
{
    public ?string $lastError = null;

    public LoggerInterface $logger;

    public ?LoggerInterface $optionalLogger = null;

    public Collaborator $service;

    public function narrowCatchIsControlFlow(): ?string
    {
        try {
            return $this->service->fetch();
        } catch (RuntimeException $e) {
            return null;
        }
    }

    public function rethrows(): void
    {
        try {
            $this->service->fetch();
        } catch (Throwable) {
            throw new RuntimeException('Fetch failed.');
        }
    }

    public function rethrowsConditionally(bool $strict): void
    {
        try {
            $this->service->fetch();
        } catch (Exception) {
            if ($strict) {
                throw new RuntimeException('Fetch failed.');
            }
        }
    }

    public function reportsWithoutReadingTheVariable(): void
    {
        try {
            $this->service->fetch();
        } catch (Throwable) {
            report(new RuntimeException('Fetch failed.'));
        }
    }

    public function reportsConditionally(): void
    {
        try {
            $this->service->fetch();
        } catch (Throwable) {
            REPORT_IF(true, new RuntimeException('Fetch failed.'));
        }
    }

    public function logsThroughTheHelper(): void
    {
        try {
            $this->service->fetch();
        } catch (Throwable) {
            logger('fetch failed');
        }
    }

    public function reportsUnlessConditionally(): void
    {
        try {
            $this->service->fetch();
        } catch (Throwable) {
            report_unless(false, new RuntimeException('Fetch failed.'));
        }
    }

    public function logsThroughTheInfoHelper(): void
    {
        try {
            $this->service->fetch();
        } catch (Throwable) {
            info('fetch failed');
        }
    }

    public function logsThroughTheFacade(): void
    {
        try {
            $this->service->fetch();
        } catch (Throwable) {
            Log::warning('fetch failed');
        }
    }

    public function logsThroughTheFacadeSpelledInAnotherCase(): void
    {
        try {
            $this->service->fetch();
        } catch (Throwable) {
            \illuminate\support\facades\LOG::warning('fetch failed');
        }
    }

    public function logsThroughAnInjectedLogger(): void
    {
        try {
            $this->service->fetch();
        } catch (Error) {
            $this->logger->error('fetch failed');
        }
    }

    public function logsThroughANullsafeLogger(): void
    {
        try {
            $this->service->fetch();
        } catch (Error) {
            $this->optionalLogger?->error('fetch failed');
        }
    }

    public function carriesTheFailureIntoState(): void
    {
        try {
            $this->service->fetch();
        } catch (Throwable $e) {
            $this->lastError = $e::class;
        }
    }

    public function carriesTheFailureIntoAClosure(): ?Closure
    {
        try {
            $this->service->fetch();
        } catch (Throwable $e) {
            return fn(): Throwable => $e;
        }

        return null;
    }
}
