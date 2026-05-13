<?php

declare(strict_types = 1);

namespace App\Services;

/**
 * A non-Builder service that happens to expose a `truncate()` method, and
 * for good measure exposes a `table()` method too. Guards against
 * false-positives on method-name alone — the rule must short-circuit on the
 * type-based receiver gate before reaching the chain walk.
 */
final class StringTruncator
{
    public function table(string $name): self
    {
        return $this;
    }

    public function truncate(): bool
    {
        return true;
    }
}

final class UsesStringTruncator
{
    public function reset(): void
    {
        (new StringTruncator)->table('logs')->truncate();
    }
}
