<?php

declare(strict_types = 1);
use Carbon\CarbonImmutable;

// No namespace declaration: `$scope->getNamespace()` is null, which is outside
// every configured prefix, so the rule fires. Consumers analyse `app/`, which
// is namespaced throughout — a global-namespace parse is by definition not
// inside a boundary namespace.

final class GlobalNamespaceParse
{
    public function execute(string $raw): CarbonImmutable
    {
        return CarbonImmutable::parse($raw);
    }
}
