<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Services\MyService;

final readonly class CompliantNonModelReceiver
{
    public function __construct(
        private MyService $service,
    ) {}

    public function destroy(): void
    {
        // `MyService::save()` / `MyService::delete()` — non-Eloquent receiver,
        // receiver-type gate rejects. Controllers freely call non-Model
        // services; only `Illuminate\Database\Eloquent\Model` subtypes carry
        // the audit-bypass risk this rule guards against.
        $this->service->save();
        $this->service->delete();
    }
}
