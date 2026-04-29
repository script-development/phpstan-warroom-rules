# Changelog

All notable changes to `script-development/phpstan-warroom-rules` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] — 2026-04-29

### Added

- `EnforceActionTransactionsRule` — flags `App\Actions\*` classes whose `execute()` performs ≥2 writes without `->transaction()`. Doctrine: ADR-0011.
- `ForbidDatabaseManagerInActionsRule` — flags `App\Actions\*` constructors that inject `Illuminate\Database\DatabaseManager`. Doctrine: ADR-0021 §Why ConnectionInterface.
- `ForbidAbortHelperRule` — flags `abort()`, `abort_if()`, `abort_unless()` function calls. Doctrine: war-room §Explicit over implicit.
- `LogRule` — flags `update()` / `delete()` calls on classes whose name contains `"Log"` or `"logs"`. Doctrine: ADR-0001 §Append-only.
- `ConnectionTransactionReturnTypeExtension` — resolves `$connection->transaction(fn () => $foo)` to the closure's return type instead of `mixed`.

### Notes

- Rules ported from emmie's `backend/app/PHPStan/`. The territory-specific `Terminology` exception in `LogRule` was dropped — per-territory false positives are now suppressed via consumer `phpstan.neon ignoreErrors`.
- Test coverage is smoke-level for v0.1.0; full matrix for `EnforceActionTransactionsRule` (non-DB property exclusions, nested closure transaction detection, full 18-method write list) lands in a follow-up.
- Action namespace assumption: rules that scope to Actions match `App\Actions\*`. Lift to a parameter when a non-conforming territory onboards.

[Unreleased]: https://github.com/script-development/phpstan-warroom-rules/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/script-development/phpstan-warroom-rules/releases/tag/v0.1.0
