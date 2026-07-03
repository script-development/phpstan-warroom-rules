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
| `ForbidHttpExceptionInActionsRule` | `forbidHttpExceptionInActions.httpExceptionInAction` | `throw` statements inside `App\Actions\*` classes (namespace prefix, incl. sub-namespaces) | Throwing a `Symfony\Component\HttpKernel\Exception\HttpException`-family exception (`HttpException` + every subclass — `NotFoundHttpException`, `AccessDeniedHttpException`, `UnprocessableEntityHttpException`, …) from an Action is an error. Type-aware: the thrown expression's type must be a subtype of `Symfony\Component\HttpKernel\Exception\HttpExceptionInterface` (catches subclasses, fully-qualified throws, and typed-value throws an import-checking arch test would miss). HTTP status concerns belong to the HTTP layer — put a uniqueness rule in the FormRequest, or throw a custom domain exception the renderer maps to a status. `Illuminate\Validation\ValidationException` is **out of scope** (not a Symfony `HttpException`; Actions legitimately throw it for stateful validation). Type-aware sibling of `ForbidAbortHelperRule`. Doctrine: war-room §Architectural Principles — Explicit over implicit (#1) + Form Request → DTO → Action pipeline (#3). |
| `LogRule` | `logRule.logModification` | `update()` / `delete()` calls | If the receiver type's class name contains `"Log"` or `"logs"` (case-insensitive), error. |
| `LogBuilderTruncateRule` | `logRule.logModification` | `Builder->truncate()` calls | If the fluent chain's most recent `table()` call targets a Log-named table (string-literal argument matching `"log"` / `"logs"`, case-insensitive), error. Sibling rule to `LogRule`; shares the `logRule.logModification` identifier so a single `ignoreErrors` entry covers both. Eloquent `from()` chains and Model-`$table`-property-driven tables are acceptable misses. Doctrine: ADR-0001 §Append-only. |
| `EnforceAuditSnapshotOnRetryRule` | `enforceAuditSnapshotOnRetry.firstStatementMustResetState` | `App\Actions\*` whose constructor injects an entity audit logger | The first statement inside `$connection->transaction(...)` must reset the model's in-memory state (`$model->refresh()`, fresh fetch, or fresh instantiation). Doctrine: ADR-0001 §Snapshot-on-Retry Safety. |
| `EnforceAuditTransactionScopeRule` | `enforceAuditTransactionScope.nonTransactionalMutationInClosure` | `App\Actions\*` whose `execute()` calls `transaction(...)` with a literal closure | Mutating `StatefulGuard` / `Session` / `Cache` / `Bus` / `Queue` / `Mailer` / `Notification` / `Broadcaster` / `Filesystem` state (or their `Illuminate\Support\Facades\*` counterparts) inside the closure is an error. Reads (`Auth::user()`, `Session::get()`, `Cache::get()`) are permitted. Doctrine: ADR-0029 (Audit Row Durability Contract) §Decision rule 3. |
| `ForbidEloquentMutationInControllersRule` | `forbidEloquentMutationInControllers.eloquentMutationInController` | `App\Http\Controllers\*` (including sub-namespaces; configurable via `controllerNamespacePrefixes`) | Calling Eloquent persistence APIs (`save`, `update`, `delete`, `create`, `destroy`, `forceDelete`, `forceFill`, `push`, `restore`, `touch`, and their `*OrFail` / `*Quietly` / `*OrCreate` variants — 24-method blocklist) on `Illuminate\Database\Eloquent\Model` subclasses or `Illuminate\Database\Eloquent\Builder` chains is an error. Reads (`find`, `where`, `get`, `first`, `paginate`, `pluck`, `count`, `exists`, `query`) are permitted. Delegate mutations to an Action. Doctrine: ADR-0011 (Action Class Architecture) + ADR-0019 (Explicit Model Hydration). |
| `EnforceResourceDataValidatorOptInRule` | `enforceResourceDataValidatorOptIn.missingValidatorCall` | Classes extending `App\Http\Resources\ResourceData` | If the class declares a non-empty `EAGER_LOAD_COUNT` / `EAGER_LOAD_SUM` constant but never calls `validateRelationsLoaded()` in any method, error. |
| `EnforceFormRequestToDtoRule` | `enforceFormRequestToDto.missingToDtoMethod` | Concrete classes extending `Illuminate\Foundation\Http\FormRequest` | If the class neither declares nor inherits a `toDto()` method, error. Abstract intermediates (`BaseFormRequest`) are exempt. Hand Actions a typed DTO, not `$request->validated()` arrays. Doctrine: ADR-0012 (FormRequest → DTO Flow). |
| `EnforceCurrentUserAttributeRule` | `enforceCurrentUserAttribute.useAttributeInsteadOfRequestUser` | `Request::user()` / `Auth::user()` / `auth()->user()` calls inside `App\Http\Controllers\*` classes (namespace prefix, incl. sub-namespaces; configurable via `controllerNamespacePrefixes`) | Use `#[\Illuminate\Container\Attributes\CurrentUser] User $user` on the method parameter. Scope is decided by namespace, not class ancestry — a base-less `final` controller in `App\Http\Controllers` fires; FormRequests (`App\Http\Requests`), middleware (`App\Http\Middleware`), services, Actions (`App\Actions`), jobs, and console commands are silent because their namespaces do not start with the controller prefix (container-attribute injection does not apply to FormRequest methods regardless). |
| `EnforceCurrentUserAttributeRule` | `enforceCurrentUserAttribute.useAttributeInsteadOfRequestUser` | `Request::user()` / `Auth::user()` / `auth()->user()` calls inside `App\Http\Controllers\*` classes (namespace prefix, incl. sub-namespaces) | Use `#[\Illuminate\Container\Attributes\CurrentUser] User $user` on the method parameter. Scope is decided by namespace, not class ancestry — a base-less `final` controller in `App\Http\Controllers` fires; FormRequests (`App\Http\Requests`), middleware (`App\Http\Middleware`), services, Actions (`App\Actions`), jobs, and console commands are silent because their namespaces do not start with the controller prefix (container-attribute injection does not apply to FormRequest methods regardless). |
| `EnforceAuditModelProtectionsRule` | `enforceAuditModelProtections.hasFactoryForbidden` / `.softDeletesForbidden` / `.updatedAtNotDisabled` | Eloquent models recognised as audit records by SHAPE — short name ends with a configured suffix (default `AuditLog`) OR FQCN sits under a configured namespace (default `App\Models\Audit`) | Three append-only protections, each firing independently: using `HasFactory` (a factory is a direct-insert path bypassing the hash-chained writer), using `SoftDeletes` (audit rows are never removed), or not disabling `updated_at` (an audit row is written once and never mutated — declare `public const UPDATED_AT = null;`) is an error. Discovery is by pattern, never a hand-maintained class list — a denylist inversion, so a newly-added audit model cannot escape the protections by omission. Abstract intermediates are exempt (their concrete leaves carry inherited violations). Non-model classes named `*AuditLog` are excluded by the Eloquent `Model` type gate. Doctrine: ADR-0001 §Append-only. |

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

`LogBuilderTruncateRule` shares the `logRule.logModification` identifier with `LogRule`. A single `ignoreErrors` entry keyed on `logRule.logModification` therefore covers both rules for the suppressed path.

### `EnforceResourceDataValidatorOptInRule` — configurable base class

The rule scopes to classes extending `App\Http\Resources\ResourceData` by default. If a territory ships its abstract resource base under a different FQCN, override the `resourceDataBaseClass` parameter in `phpstan.neon`:

```neon
parameters:
    resourceDataBaseClass: 'App\Resources\BaseResource'
```

Inheritance is matched via PHPStan reflection (FQCN ancestor traversal), not short-name matching — a class named `ResourceData` in an unrelated namespace will not be matched. Compliant call shapes are `self::validateRelationsLoaded($model)`, `static::validateRelationsLoaded($model)`, and `$this->validateRelationsLoaded($model)` — the production base method is `protected static`, but the instance form is also accepted for compatibility with the source-of-truth Pest arch test's permissive matcher. Empty-array constants (`EAGER_LOAD_COUNT = []`) do not fire — they are no-ops.

### `EnforceFormRequestToDtoRule` — configurable base class + exemptions

The rule scopes to concrete classes extending `Illuminate\Foundation\Http\FormRequest` by default. To narrow the contract to a territory-local base FQCN, override the `formRequestBaseClass` parameter in `phpstan.neon`:

```neon
parameters:
    formRequestBaseClass: 'App\Http\Requests\BaseFormRequest'
```

Inheritance is matched via PHPStan reflection (FQCN ancestor traversal), not short-name matching. Abstract classes never fire — a per-territory abstract `BaseFormRequest` intermediate is exempt by shape, not by name. A `toDto()` declared on a parent class or provided by a trait satisfies the contract (mirroring the source-of-truth entreezuil Pest arch test's `method_exists()` matcher).

Legitimately DTO-less requests (e.g. a `LoginRequest` whose auth flow calls `AuthManager::attempt()` directly, or read-only filter/query requests) are suppressed per territory in one of two consumer-config-driven ways — never by name inside the rule.

**Option A — per-file `ignoreErrors` (path-keyed):**

```neon
parameters:
    ignoreErrors:
        -
            identifier: enforceFormRequestToDto.missingToDtoMethod
            path: app/Http/Requests/LoginRequest.php
```

Each ignore should carry a comment with rationale.

**Option B — `formRequestToDtoExemptClasses` (class-keyed):** a list of fully-qualified class names to skip, matched by **exact FQCN**. This is the class-keyed alternative to `ignoreErrors` — predictable across file moves, and it ports a retiring local arch test's exempt-class list into package config 1:1. Default empty ⇒ no exemptions.

```neon
parameters:
    formRequestToDtoExemptClasses:
        # login handler: auth flow calls Auth::attempt() directly, no Action DTO
        - 'App\Http\Requests\Auth\LoginRequest'
```

A consumer-supplied FQCN list is *config*, not a rule-body literal — the "never by name inside the rule" convention is preserved.

#### Retiring a local FormRequest→DTO arch test

Where a territory already enforces "every concrete FormRequest exposes `toDto()`" via a local Pest arch test (e.g. entreezuil's `backend/tests/Architecture/FormRequestsTest.php`), this rule now duplicates that invariant. To retire the local test cleanly:

1. Move the arch test's exempt-class list into `formRequestToDtoExemptClasses` as FQCNs. For entreezuil that is:
   ```neon
   parameters:
       formRequestToDtoExemptClasses:
           # framework Auth::attempt() path, no Action DTO
           - 'App\Http\Requests\Auth\LoginRequest'
           # intermediate base (make it `abstract` and it drops out entirely)
           - 'App\Http\Requests\BaseFormRequest'
   ```
2. Delete the local arch test — the package rule (identifier `enforceFormRequestToDto.missingToDtoMethod`) is now the single enforcement authority.

(Territory arch-test retirement is a separate follow-up dispatch, not part of shipping this option.)

### `EnforceCurrentUserAttributeRule` — false positives

`#[\Illuminate\Container\Attributes\CurrentUser]` resolves the authenticated user at **method-entry DI time**. A controller method that resolves the user *after* `Auth::attempt()` succeeds — the canonical **login handler** on a `guest` / throttle-only route — cannot use the attribute: at method entry no user exists yet, so injection yields `null` and breaks login. The rule fires on any `Auth::user()` / `$request->user()` / `auth()->user()` inside the `App\Http\Controllers` namespace and **cannot see routes**, so it will flag these legitimate login handlers. Suppress per territory via `phpstan.neon` — never by name inside the rule:

```neon
parameters:
    ignoreErrors:
        -
            identifier: enforceCurrentUserAttribute.useAttributeInsteadOfRequestUser
            # login handler: Auth::user() resolves after Auth::attempt() on a guest route
            path: app/Http/Controllers/Auth/AuthenticatedSessionController.php
```

Confirmed cross-territory (n=2, 2026-06-15): entreezuil `AuthenticatedSessionController::store`, ublgenie `AuthController::store`. Each consumer adds this on its `^0.4` bump.

### Configurable controller namespaces (`controllerNamespacePrefixes`)

The three controller-scoped rules — `ForbidEloquentMutationInControllersRule`, `EnforceCurrentUserAttributeRule`, and `ForbidResourceWrappedInJsonResponseRule` — decide "is this class a controller?" by namespace prefix, not class ancestry (consumer controllers are base-less `final` classes with no `extends Controller`, so an ancestry walk catches nothing). The prefix set is the shared `controllerNamespacePrefixes` parameter, default `['App\Http\Controllers']`:

```neon
parameters:
    controllerNamespacePrefixes:
        - 'App\Http\Controllers'
```

A class is in scope when its namespace `str_starts_with` **any** listed prefix, so canonical sub-namespaces (kendo's `App\Http\Controllers\Central\*`) are covered by the default automatically. The default reproduces the prior hardcoded gate byte-for-byte — leave it unset and nothing changes.

#### Covering sub-namespaced controllers

A territory that ships controllers **outside** `App\Http\Controllers` — e.g. emmie's `App\Http\Client\Controllers` and `App\Http\Admin\Controllers` — opts them into both rules by listing their prefixes:

```neon
parameters:
    controllerNamespacePrefixes:
        - 'App\Http\Controllers'
        - 'App\Http\Client\Controllers'
        - 'App\Http\Admin\Controllers'
```

All three rules then flag inline Eloquent mutations, `Request::user()` / `Auth::user()` / `auth()->user()` calls, and `JsonResource`-wrapped JSON responses in those namespaces too. Prefixes are *config* — no consumer namespace is ever hardcoded in a rule body, preserving the "never by name inside the rule" convention. (Each backslash is single — NEON only unescapes `\\` inside double quotes; single-quoted `\\` stays two literal characters and would match nothing.)
### `EnforceAuditModelProtectionsRule` — configurable discovery (denylist inversion)

This rule is the **inverse** of an allowlist arch test. The Pest predecessors it supersedes (kendo `tests/Arch/AuditTest.php`, entreezuil `tests/Architecture/AuditTest.php`, ublgenie `tests/Arch/AuditTest.php`) enumerate audit models — by a hand-maintained FQCN list or a namespace directory sweep — and assert each lacks `HasFactory` / `SoftDeletes` / a mutable `updated_at`. A hand-maintained list silently exempts every future audit model added outside it. This rule scans for the audit-model *shape* and flags any that lacks a protection, so nothing escapes by being forgotten.

**Discovery** — an Eloquent `Model` subclass is an audit record if its short name ends with any configured suffix **OR** its FQCN sits under any configured namespace prefix. The two signals are a union covering both fleet strategies. Defaults:

```neon
parameters:
    auditModelNamespacePrefixes:
        - 'App\Models\Audit'   # entreezuil / ublgenie convention (incl. channel logs: AuthEventLog, SmsEventLog)
    auditModelNameSuffixes:
        - 'AuditLog'           # kendo *AuditLog models, scattered across App\Models + App\Models\Central
```

A consumer whose audit models use a different family widens either list — for example, to bring a kendo-style channel-log pair (`AiOutboundLog`, `AiMcpLog`) into scope alongside the `*AuditLog` entity models:

```neon
parameters:
    auditModelNameSuffixes:
        - 'AuditLog'
        - 'OutboundLog'
        - 'McpLog'
```

Configuration expresses **patterns**, never enumerated class names — no consumer class name is ever hardcoded in the rule body, and a non-model class named `*AuditLog` (a DTO, a service) is excluded by the Eloquent `Model` type gate.

**Protections** — three checks fire independently (a model missing several yields several errors at the class line):

| Identifier | Fires when |
|---|---|
| `enforceAuditModelProtections.hasFactoryForbidden` | the model uses `HasFactory` (transitively — an inherited trait on an abstract base counts). A factory is a direct-insert path that bypasses the hash-chained audit writer. |
| `enforceAuditModelProtections.softDeletesForbidden` | the model uses `SoftDeletes`. Audit rows are append-only and never removed. |
| `enforceAuditModelProtections.updatedAtNotDisabled` | the model does not declare `public const UPDATED_AT = null`. The framework `Model` base sets `UPDATED_AT = 'updated_at'`, so a model that never overrides it keeps a mutable timestamp — an audit row is written once and never mutated. A model that disables timestamps wholesale (`public $timestamps = false;`) never writes `updated_at` at all and is recognised natively as compliant. |

Abstract intermediates (`abstract class BaseAuditLog`) are exempt — the concrete leaf carries any inherited violation.

**Migrating off the local arch test** — move the arch test's model-discovery convention into the parameters above, delete the local `HasFactory` / `SoftDeletes` / `updated_at` model checks, and the package rule becomes the single enforcement authority. (The append-only `update()` / `delete()` ban on `*Log` classes is a separate concern already covered by `LogRule`.) A `$timestamps = false` model needs no suppression — the rule recognises disabled-wholesale timestamps natively. A remaining genuine non-audit false positive is suppressed per-file via `ignoreErrors` keyed on the specific identifier, with a rationale comment:

```neon
parameters:
    ignoreErrors:
        -
            identifier: enforceAuditModelProtections.hasFactoryForbidden
            # seeded read-model projection, not an audit record; factory is test-only
            path: app/Models/Audit/SomeProjectionLog.php
```

### Action namespace assumption

`EnforceActionTransactionsRule` and `ForbidDatabaseManagerInActionsRule` only fire on classes whose namespace starts with `App\Actions`. This matches the Laravel convention used in every `script-development` territory. Territories using a different actions namespace should open a PR to make this configurable.

## Type extension

`ConnectionTransactionReturnTypeExtension` is registered alongside the rules. It resolves the return type of `$connection->transaction(fn () => $foo)` to the closure's return type instead of `mixed`, enabling strict typing of transaction call sites.

## Production dependencies

The `illuminate/*` packages (`database`, `contracts`, `cache`, `filesystem`, `log`, `mail`) sit in `require`, not `require-dev`, on purpose. The rules and `ConnectionTransactionReturnTypeExtension` reflect against Illuminate contracts and classes (e.g. `Illuminate\Database\ConnectionInterface`, the cache/mail/queue facades the audit-scope rule reasons about) *at analysis time* — when a consumer runs PHPStan, this package's code resolves those symbols, so they are genuine analysis-time (runtime-for-the-extension) dependencies, not test-only tooling. Moving them to `require-dev` would omit them from a normal `composer require --dev` install and break consumers that analyse non-Laravel or partial trees where the Illuminate symbols are not otherwise present.

## Versioning

Semantic versioning:

- **Major** — a rule's behavior changes in a way that surfaces *new* errors in code that previously passed (e.g. expanding the write-method list, tightening `LogRule`'s match).
- **Minor** — a new rule is added, or a rule gains an option that doesn't change defaults.
- **Patch** — bug fixes, false-positive suppression, performance improvements.

Pin to a 0.x minor version today (`^0.2`); future 1.0 release will allow `^1.0` pinning. See `CLAUDE.md` § Versioning for the 0.x caret-semantics rationale.

## License

MIT — see `LICENSE`.
