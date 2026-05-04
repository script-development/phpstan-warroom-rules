# Changelog

All notable changes to `script-development/phpstan-warroom-rules` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] — 2026-05-04

### Added

- `EnforceAuditSnapshotOnRetryRule` — flags `App\Actions\*` classes whose constructor injects an entity audit logger and whose `$connection->transaction(...)` calls do not begin with an in-memory state reset (`$model->refresh()`, fresh fetch via `->newQuery()->findOrFail(...)` / `->fresh()`, or fresh instantiation via `new ...` / `->newInstance()`). Doctrine: ADR-0001 §Snapshot-on-Retry Safety. Identifier: `enforceAuditSnapshotOnRetry.firstStatementMustResetState`. Promoted from cross-territory Pest arch tests (emmie PR #187, entreezuil PR #139, ublgenie PR #166, kendo PR #1029). Receiver detection is type-based (`Illuminate\Database\ConnectionInterface` subtype) — replaces territory-specific property-name matching (`$this->db` vs `$this->connection`). Escape hatch: `// @audit-snapshot-retry-safety: <rationale>` marker preceding the transaction call.

### Changed

- **PHP constraint:** bumped `composer.json` `php` from `^8.3` to `^8.4`. The package's Pint config (`mb_str_functions: true`) normalizes `ltrim`/`trim` calls to `mb_ltrim`/`mb_trim`, which are PHP 8.4+ functions. The new rule introduced the first `mb_ltrim`/`mb_trim` callsites; aligning the constraint with the formatter's actual output. All consuming territories already run PHP 8.4 — no real-world impact.
- **`LogRule` (BREAKING):** extended `FORBIDDEN_METHODS` from `['delete', 'update']` to `['delete', 'forceDelete', 'forceDeleteQuietly', 'update']`. On a `SoftDeletes`-bearing model `->delete()` is a no-op against the underlying row and `->forceDelete()` is the only call that actually purges; the rule's compliance teeth previously rested on the migration-time convention that audit-log models never adopt `SoftDeletes`. Static-call shapes (`Model::destroy()`, `Model::forceDestroy()`, `DB::table('logs')->truncate()`) remain out of scope — `getNodeType()` returns `MethodCall::class`, and static-call coverage is tracked as issue #4. Origin: issue #1, surfaced by ally review on [Back-to-code/ublgenie-app#163](https://github.com/Back-to-code/ublgenie-app/pull/163#discussion_r3160966677). Pre-cascade audit across emmie, kendo, entreezuil, ublgenie surfaced one new violation: `ublgenie/app/Actions/DeleteBranch.php:56` (`InvoiceLog::query()->whereIn(...)->forceDelete()`) — operational/processing log, not an audit log; migrates to consumer-side `phpstan.neon` `ignoreErrors` per package convention. Versioning: per ADR-0021 §Versioning, this is a Major bump (new errors in code that previously passed); within 0.x this ships as `v0.2.0`.

## [0.1.1] — 2026-04-29

### Changed

- **Compatibility:** widened `illuminate/*` constraints from `^11.0 || ^12.0` to `^11.0 || ^12.0 || ^13.0` across the five required packages (`database`, `contracts`, `cache`, `filesystem`, `log`, `mail`). Surfaced during ADR-0021 cascade onto entreezuil (Laravel 13). No behavioral change — the package's PHPStan rules reason about class names that are stable across Laravel 11/12/13. Forward-looking: removes the constraint as a future cascade blocker.

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

[Unreleased]: https://github.com/script-development/phpstan-warroom-rules/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/script-development/phpstan-warroom-rules/releases/tag/v0.2.0
[0.1.1]: https://github.com/script-development/phpstan-warroom-rules/releases/tag/v0.1.1
[0.1.0]: https://github.com/script-development/phpstan-warroom-rules/releases/tag/v0.1.0
