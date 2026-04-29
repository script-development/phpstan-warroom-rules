# Changelog

All notable changes to `script-development/phpstan-warroom-rules` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `EnforceActionTransactionsRule` — flags `App\Actions\*` classes whose `execute()` performs ≥2 writes without `->transaction()`. Doctrine: ADR-0011.
- `ForbidDatabaseManagerInActionsRule` — flags `App\Actions\*` constructors that inject `Illuminate\Database\DatabaseManager`. Doctrine: ADR-0021 §Why ConnectionInterface.
- `ForbidAbortHelperRule` — flags `abort()`, `abort_if()`, `abort_unless()` function calls. Doctrine: war-room §Explicit over implicit.
- `LogRule` — flags `update()` / `delete()` calls on classes whose name contains `"Log"` or `"logs"`. Doctrine: ADR-0001 §Append-only.
- `ConnectionTransactionReturnTypeExtension` — resolves `$connection->transaction(fn () => $foo)` to the closure's return type instead of `mixed`.

[Unreleased]: https://github.com/script-development/phpstan-warroom-rules/compare/v0.0.0...HEAD
