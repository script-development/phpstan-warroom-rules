<?php

declare(strict_types = 1);

// Stub classes referenced by ResourceDataValidatorOptIn fixtures. The rule
// scopes detection on PHPStan reflection (extends-base check) plus AST
// constant + call-site walking — these stubs let PHPStan resolve fixture
// types without pulling in a full Laravel application.

namespace App\Http\Resources;

abstract class ResourceData
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public array $data = [],
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function from(array $payload): self
    {
        // Fixture-only factory; production base in consuming territories
        // returns `static`, but `self` keeps PHPStan analysis self-contained
        // (no `new.static` ignore needed for an abstract-class instantiation
        // path that fixtures never hit).
        throw new \LogicException('Fixture stub — never invoked.');
    }

    /**
     * @param list<string> $relations
     */
    protected static function validateRelationsLoaded(object $model, array $relations = []): void
    {
        // No-op for fixtures — the production base in consuming territories
        // raises an exception when an aggregate column is absent.
    }
}

namespace App\Models;

final class Project
{
    public int $id = 1;

    public string $name = '';
}
