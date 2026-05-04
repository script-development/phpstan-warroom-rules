<?php

declare(strict_types = 1);

// Stub classes referenced by AuditSnapshotOnRetry fixtures. The rule scopes
// detection on AST Name nodes (constructor parameter type) and receiver-type
// resolution (ConnectionInterface subtype) — these stubs let PHPStan resolve
// fixture types without pulling in a full Laravel application.

namespace App\Audit;

final class WidgetAuditLogger
{
    public function logUpdated(object $model): void {}

    public function logCreated(object $model): void {}

    public function logDeleted(object $model): void {}
}

final class AiOutboundLogger
{
    public function log(string $payload): void {}
}

namespace App\Models;

final class Widget
{
    public string $name = '';

    public function refresh(): static
    {
        return $this;
    }

    public function save(): bool
    {
        return true;
    }

    public function delete(): bool
    {
        return true;
    }

    public function newQuery(): self
    {
        return $this;
    }

    public function newInstance(): self
    {
        return new self;
    }

    public function findOrFail(int $id): self
    {
        return $this;
    }

    public function fresh(): self
    {
        return $this;
    }
}

final class User
{
    public function refresh(): static
    {
        return $this;
    }

    public function save(): bool
    {
        return true;
    }
}

namespace App\Cache;

final class FakeCache
{
    public function transaction(\Closure $callback): mixed
    {
        return $callback();
    }
}
