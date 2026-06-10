<?php

declare(strict_types = 1);

// Stub classes referenced by FormRequestToDto fixtures. The rule scopes
// detection on PHPStan reflection (extends-base check + native-method
// lookup) — these stubs let PHPStan resolve fixture types without pulling
// in a full Laravel application. `Illuminate\Foundation\Http\FormRequest`
// is stubbed because the foundation component is not a standalone Composer
// package — consumers resolve it via laravel/framework; this package's
// analysis-time dependencies deliberately exclude the full framework.

namespace Illuminate\Foundation\Http;

abstract class FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        // No-op for fixtures — the production base in consuming territories
        // returns the validator's validated payload.
        return [];
    }
}

namespace App\DataTransferObjects;

final readonly class StoreUserData
{
    public function __construct(
        public string $name = '',
    ) {}
}
