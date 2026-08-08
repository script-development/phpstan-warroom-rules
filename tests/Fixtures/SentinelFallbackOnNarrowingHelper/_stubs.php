<?php

declare(strict_types = 1);

// Stub helpers referenced by SentinelFallbackOnNarrowingHelper fixtures. The
// rule keys on SHAPE, so the stubs enumerate the shape matrix rather than any
// real territory helper:
//
//   - `LeafReader::text()` / `::number()` / `::flag()` — the narrowing shape:
//     a `mixed` parameter in, a nullable scalar out. These MUST fire under a
//     literal sentinel fallback.
//   - `LeafReader::staticText()` — same shape as a static call.
//   - `LeafReader::label()` — a `string` parameter, so no boundary tell; must
//     never fire.
//   - `LeafReader::required()` — a non-nullable `string` return, so there is no
//     failure signal to hide; must never fire.
//   - `Application\Support\ImposterReader` — sits under a namespace that only
//     LOOKS like the configured `App\` prefix; pins the namespace-boundary match.
//
// The plain-function case lives in its own fixture file rather than here,
// because a function is not classmap-autoloadable — it is only visible to
// PHPStan through the analysed file that declares it.

namespace App\Support {
    final class LeafReader
    {
        public const string UNKNOWN = 'unknown';

        // The narrowing shape: mixed external data in, nullable scalar out.
        // null means "this input was unreadable".
        public function text(mixed $leaf): ?string
        {
            return \is_string($leaf) ? $leaf : null;
        }

        public function number(mixed $leaf): ?int
        {
            return \is_int($leaf) ? $leaf : null;
        }

        public function flag(mixed $leaf): ?bool
        {
            return \is_bool($leaf) ? $leaf : null;
        }

        // Nullable UNION return — still a nullable scalar contract.
        public function scalar(mixed $leaf): string|int|null
        {
            return \is_string($leaf) || \is_int($leaf) ? $leaf : null;
        }

        public static function staticText(mixed $leaf): ?string
        {
            return \is_string($leaf) ? $leaf : null;
        }

        // No `mixed` parameter — the caller already narrowed, so this is not a
        // boundary helper.
        public function label(string $leaf): ?string
        {
            return $leaf === '' ? null : $leaf;
        }

        // Non-nullable return — no failure signal exists to be hidden.
        public function required(mixed $leaf): string
        {
            return \is_string($leaf) ? $leaf : '';
        }

        // Nullable OBJECT return — not a scalar, so out of scope.
        public function reader(mixed $leaf): ?self
        {
            return \is_string($leaf) ? $this : null;
        }
    }
}

namespace Application\Support {
    // Namespace-boundary decoy: `Application\` must NOT match the `App\` prefix.
    final class ImposterReader
    {
        public function text(mixed $leaf): ?string
        {
            return \is_string($leaf) ? $leaf : null;
        }
    }
}
