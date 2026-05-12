# phpstan-warroom-rules

[![Packagist Version](https://img.shields.io/packagist/v/script-development/phpstan-warroom-rules.svg)](https://packagist.org/packages/script-development/phpstan-warroom-rules)
[![PHP Version](https://img.shields.io/packagist/dependency-v/script-development/phpstan-warroom-rules/php.svg)](https://packagist.org/packages/script-development/phpstan-warroom-rules)
[![CI](https://github.com/script-development/phpstan-warroom-rules/actions/workflows/ci.yml/badge.svg)](https://github.com/script-development/phpstan-warroom-rules/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/script-development/phpstan-warroom-rules.svg)](LICENSE)

Canonical PHPStan rules enforcing war-room doctrine across `script-development` Laravel territories.

Distributed via Composer as `script-development/phpstan-warroom-rules`. Doctrine source is [ADR-0021](https://adrs.script.nl/decisions/phpstan-rules-package).

## Why

Several doctrine claims need static-analysis enforcement that out-of-the-box PHPStan + Larastan cannot provide:

- Multi-write Actions must wrap operations in a database transaction.
- Audit log records are append-only.
- The `abort()` family of helpers is forbidden in favor of explicit HTTP exception throws.
- Action constructors inject `ConnectionInterface`, never `DatabaseManager`.

These rules originated inside `emmie` and have been promoted to a shared package so every consuming territory gets the same enforcement on `composer require`.

## Installation

```bash
composer require --dev script-development/phpstan-warroom-rules
```

The package ships with `phpstan/extension-installer` metadata. If you have the installer, the extension is auto-loaded. Otherwise, add it to your `phpstan.neon`:

```neon
includes:
    - vendor/script-development/phpstan-warroom-rules/extension.neon
```

## Rules

| Rule | Identifier | Detects | Forbids / Requires |
|---|---|---|---|
| `EnforceActionTransactionsRule` | `enforceActionTransactions.missingTransaction` | Action `execute()` methods | If ≥2 write operations appear without `->transaction()`, error. |
| `ForbidDatabaseManagerInActionsRule` | `forbidDatabaseManager.inAction` | Action constructors | Constructor parameter typed `DatabaseManager` is an error. Inject `ConnectionInterface` instead. |
| `ForbidAbortHelperRule` | `forbidAbortHelper.abortUsed` | Function calls | `abort()`, `abort_if()`, `abort_unless()` are errors. Throw an explicit `HttpException` subclass instead. |
| `LogRule` | `logRule.logModification` | `update()` / `delete()` calls | If the receiver type's class name contains `"Log"` or `"logs"` (case-insensitive), error. |
| `EnforceAuditSnapshotOnRetryRule` | `enforceAuditSnapshotOnRetry.firstStatementMustResetState` | `App\Actions\*` whose constructor injects an entity audit logger | The first statement inside `$connection->transaction(...)` must reset the model's in-memory state (`$model->refresh()`, fresh fetch, or fresh instantiation). Doctrine: ADR-0001 §Snapshot-on-Retry Safety. |
| `EnforceResourceDataValidatorOptInRule` | `enforceResourceDataValidatorOptIn.missingValidatorCall` | Classes extending `App\Http\Resources\ResourceData` | If the class declares a non-empty `EAGER_LOAD_COUNT` / `EAGER_LOAD_SUM` constant but never calls `validateRelationsLoaded()` in any method, error. |

### `EnforceActionTransactionsRule` — write-method list

The rule counts the following methods as "writes":

`save`, `saveQuietly`, `create`, `update`, `delete`, `forceDelete`, `sync`, `attach`, `detach`, `insert`, `upsert`, `updateOrCreate`, `firstOrCreate`, `push`, `restore`, `toggle`, `syncWithoutDetaching`, `syncWithPivotValues`.

Calls on properties typed as non-database services (`FilesystemManager`, `Filesystem`, `Cache\Repository`, `LogManager`, `LoggerInterface`, `Mailer`) are excluded — `$this->files->delete($path)` does not trigger the rule.

### `LogRule` — false positives

The rule uses substring matching on class names. It will fire on classes named `Catalog`, `Blog`, `Terminology`, or any business model containing `log` as a substring. Suppress per-territory via `phpstan.neon`:

```neon
parameters:
    ignoreErrors:
        -
            identifier: logRule.logModification
            path: app/Models/Catalog.php
```

Each ignore should carry a comment with rationale. Future versions may add an explicit allow-list parameter — file an issue if you have a recurring need.

### `EnforceResourceDataValidatorOptInRule` — configurable base class

The rule scopes to classes extending `App\Http\Resources\ResourceData` by default. If a territory ships its abstract resource base under a different FQCN, override the `resourceDataBaseClass` parameter in `phpstan.neon`:

```neon
parameters:
    resourceDataBaseClass: 'App\Resources\BaseResource'
```

Inheritance is matched via PHPStan reflection (FQCN ancestor traversal), not short-name matching — a class named `ResourceData` in an unrelated namespace will not be matched. Compliant call shapes are `self::validateRelationsLoaded($model)`, `static::validateRelationsLoaded($model)`, and `$this->validateRelationsLoaded($model)` — the production base method is `protected static`, but the instance form is also accepted for compatibility with the source-of-truth Pest arch test's permissive matcher. Empty-array constants (`EAGER_LOAD_COUNT = []`) do not fire — they are no-ops.

### Action namespace assumption

`EnforceActionTransactionsRule` and `ForbidDatabaseManagerInActionsRule` only fire on classes whose namespace starts with `App\Actions`. This matches the Laravel convention used in every `script-development` territory. Territories using a different actions namespace should open a PR to make this configurable.

## Type extension

`ConnectionTransactionReturnTypeExtension` is registered alongside the rules. It resolves the return type of `$connection->transaction(fn () => $foo)` to the closure's return type instead of `mixed`, enabling strict typing of transaction call sites.

## Versioning

Semantic versioning:

- **Major** — a rule's behavior changes in a way that surfaces *new* errors in code that previously passed (e.g. expanding the write-method list, tightening `LogRule`'s match).
- **Minor** — a new rule is added, or a rule gains an option that doesn't change defaults.
- **Patch** — bug fixes, false-positive suppression, performance improvements.

Pin to a 0.x minor version today (`^0.2`); future 1.0 release will allow `^1.0` pinning. See `CLAUDE.md` § Versioning for the 0.x caret-semantics rationale.

## License

MIT — see `LICENSE`.
