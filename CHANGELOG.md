# Changelog

All notable changes to `script-development/phpstan-warroom-rules` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **`LogRule` (BREAKING):** extended to cover the static-call shapes `Model::destroy(...)` and `Model::forceDestroy(...)` on Log-named classes. `getNodeType()` broadened from `MethodCall::class` to `CallLike::class` and `processNode` branches on `MethodCall` vs `StaticCall`. Both shapes emit the same `logRule.logModification` identifier so consumer `phpstan.neon` `ignoreErrors` entries cover the whole rule with one identifier (the previous rule's compliance teeth depended on `delete`/`forceDelete` instance shapes; on a non-soft-delete log model `Model::destroy([1])` purges and `Model::forceDestroy([1])` always purges — both slipped through). `DB::table('logs')->truncate()` is intentionally still out of scope — Builder receiver type carries no Log-named class reference and the table name lives in a string argument; matching that needs a shape-specific call-chain rule. Tracked separately. Versioning: per ADR-0021 §Versioning, this is a Major bump (new errors in code that previously passed); within 0.x this ships as `v0.3.0`. **Pre-cascade audit required across emmie, kendo, entreezuil, ublgenie before tagging** — surface any `::destroy(`/`::forceDestroy(` calls on Log-named classes and route operational-log false positives to consumer-side `phpstan.neon` `ignoreErrors` (same convention used in v0.2.0 for `ublgenie/app/Actions/DeleteBranch.php`). Resolves issue #4.
- **CI:** added PHP 8.5 to the `ci.yml` and `release.yml` test matrices alongside 8.4 (`['8.4']` → `['8.4', '8.5']`). PHP 8.5.0 was released 2025-11-20; the war-room dev environment already runs 8.5.5 locally, so PRs were getting ad-hoc 8.5 coverage during pre-push but no CI signal. Adding (rather than replacing) keeps 8.4 — the `composer.json` `^8.4` contractual minimum — covered. `shivammathur/setup-php@v2` supports 8.5 since GA. Resolves issue #5.
- **CI:** added line-coverage measurement and a threshold gate. `ci.yml` switches `coverage: none` → `coverage: pcov` on both 8.4 and 8.5 matrix legs (PCOV is line-coverage-only and faster than Xdebug — debugger features aren't needed). New composer scripts: `test:coverage` (runs PHPUnit with `--coverage-clover=build/logs/clover.xml --coverage-text`) and `coverage:check` (runs `bin/coverage-check.php`, a standalone clover parser — no extra runtime dependency added to a static-analysis package for a single CI gate). Two new CI steps replace the `Tests` step: **Tests with coverage** and **Coverage threshold gate**. Clover XML is uploaded as a per-leg artifact (`clover-php-${{ matrix.php }}`, 14-day retention) so reviewers can inspect uncovered lines without spelunking through workflow logs. **Initial threshold: 83%** — the measured baseline is 83.92% (240/286 lines across `src/`), set 0.92 percentage points lower to absorb trivial fluctuation on equivalent-but-renamed code. Class coverage (0/6) and method coverage (39%) are intentionally unmeasured by the gate v1; per the issue's deliberation, line coverage is the right v1 signal and branch/method coverage is a follow-up after the line gate is bedded in. The 16-percentage-point gap to 100% audits as defensive guard clauses on unexpected node shapes (the kind of branch the issue itself flagged as "genuinely hard to fixture" — `LogRule`'s static-call branch falls back when `$node->class` is `Expr` rather than `Name`); a follow-up issue will audit and ratchet the threshold upward to 90%+. Versioning: none (pure CI/test-infra, no consumer-visible behaviour). Resolves issue #9.

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
