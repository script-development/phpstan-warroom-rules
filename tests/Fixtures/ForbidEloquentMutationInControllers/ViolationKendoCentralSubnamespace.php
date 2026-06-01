<?php

declare(strict_types = 1);

namespace App\Http\Controllers\Central;

use App\Models\Post;

/**
 * Regression guard for the namespace-gate: `App\Http\Controllers\Central\*` is
 * a kendo-shape sub-namespace. The rule uses `str_starts_with($namespace,
 * 'App\Http\Controllers')` so sub-namespaces pass naturally; this fixture
 * locks the contract — a future refactor that switches to strict equality
 * (`=== 'App\Http\Controllers'`) would silently break kendo's multi-tenant
 * controller coverage.
 */
final class IssueController
{
    public function close(Post $issue): void
    {
        $issue->save();
    }
}
