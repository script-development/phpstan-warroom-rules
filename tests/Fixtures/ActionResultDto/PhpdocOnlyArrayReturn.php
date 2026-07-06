<?php

declare(strict_types = 1);

namespace App\Actions;

final readonly class PhpdocOnlyArrayReturn
{
    /**
     * Untyped `execute()` with a phpdoc-only `@return array{...}`. This is the
     * DOCUMENTED DELIBERATE MISS: the rule is signature-only and does not chase
     * phpdoc shapes (an untyped `execute()` already violates a native
     * return-type contract enforced by each consumer's own tooling). Silent —
     * pinned here so the boundary stays explicit.
     *
     * @return array{secret: string, qr_code: string}
     */
    public function execute()
    {
        return ['secret' => 's', 'qr_code' => 'q'];
    }
}
