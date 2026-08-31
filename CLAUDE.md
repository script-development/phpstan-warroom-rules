# phpstan-warroom-rules — Canonical PHPStan Rules

Composer package distributing war-room-doctrine PHPStan rules across `script-development` Laravel territories. Sister to fs-packages on the PHP side.

## Stack

- **Language:** PHP 8.4+ (uses `private const string` syntax; `mb_ltrim`/`mb_trim` via Pint `mb_str_functions`)
- **Static analysis:** PHPStan 2.x (target framework — the package extends it)
- **Test:** PHPUnit 11 (extends `PHPStan\Testing\RuleTestCase`)
- **Format:** Pint (canonical config from war-room `templates/pint.json`)
- **Publish:** Auto-sync to public packagist.org via repository webhook (`https://packagist.org/api/github`, push-trigger; `dev-*` aliases on branch push, versioned releases on tag push via `release.yml`). OIDC Trusted Publishing on Packagist is currently a Private Packagist–only feature (`packagist/artifact-publish-github-action`); public packagist.org has no OIDC option today. Migration to Private Packagist is tracked in Issue #11 — out of scope until adopted (would change ally-side Composer consumption).

## Doctrine source

- Canonical reference: [ADR-0021](https://adrs.script.nl/decisions/phpstan-rules-package)
- Origin campaign: war-room `campaigns/war-room/2026-04-29-phpstan-rules-canonical-promotion.md`
- Rules originated inside emmie's `backend/app/PHPStan/` and were promoted here for cross-territory distribution.

## Rules shipped

| Rule | Doctrine | Identifier |
|---|---|---|
| `EnforceActionTransactionsRule` | ADR-0011 | `enforceActionTransactions.missingTransaction` |
| `ForbidDatabaseManagerInActionsRule` | ADR-0021 §Why ConnectionInterface | `forbidDatabaseManager.inAction` |
| `ForbidAbortHelperRule` | War-room §Explicit over implicit | `forbidAbortHelper.abortUsed` |
| `ForbidHttpExceptionInActionsRule` | War-room §Explicit over implicit + §FormRequest → DTO → Action | `forbidHttpExceptionInActions.httpExceptionInAction` (type-aware sibling of `ForbidAbortHelperRule`; bans throwing the `Symfony\…\HttpException` family from `App\Actions\*`. `Illuminate\Validation\ValidationException` out of scope. shipped v0.5.0) |
| `ForbidResourceWrappedInJsonResponseRule` | War-room §Explicit over implicit + ADR-0009 | `forbidResourceWrappedInJsonResponse.resourceWrapped` (type-aware; bans wrapping a `JsonResource` in `response()->json()` / `new JsonResponse()` in `App\Http\Controllers\*`. Named-envelope nesting excluded. shipped v0.5.0) |
| `ForbidInlineArrayJsonResponseInControllersRule` | ADR-0009 | `forbidInlineArrayJsonResponseInControllers.arrayPayload` (type-aware; bans constructing the base `JsonResponse` (exact-FQCN, NOT subclasses) / `response()->json()` with an ARRAY payload in `App\Http\Controllers\*`. Inverse of `ForbidResourceWrappedInJsonResponseRule`. `fromJsonString` a deliberate miss. Seed kendo PR #1653. shipped v0.8.0) |
| `ForbidRawExceptionMessageInResponseRule` | War-room §Explicit over implicit + info-disclosure hardening | `forbidRawExceptionMessageInResponse.rawMessageInResponse` (flags a raw `Throwable::getMessage()` — directly or via string concat — or the `Throwable` itself flowing into a client-facing response sink. Default sink `Laravel\Mcp\Response::error`; additional `FQCN::method` sinks via the `rawExceptionMessageSinks` param, default `[]`. Type-aware — only a `getMessage()` on a `\Throwable` receiver fires. Server-side logging (`Log::`/`logger()`/PSR `LoggerInterface`/`report()`) never flags. `// @leak-safe:` comment exemption. shipped v0.8.0) |
| `LogRule` | ADR-0001 §Append-only | `logRule.logModification` (covers instance `update`/`delete`/`forceDelete`/`forceDeleteQuietly`; static `Model::destroy()` / `Model::forceDestroy()` shipped in v0.3.0) |
| `LogBuilderTruncateRule` | ADR-0001 §Append-only | `logRule.logModification` (shared with `LogRule`; covers `Builder->truncate()` on Log-named tables — shipped in v0.3.0) |
| `EnforceAuditSnapshotOnRetryRule` | ADR-0001 §Snapshot-on-Retry Safety | `enforceAuditSnapshotOnRetry.firstStatementMustResetState` |
| `EnforceAuditTransactionScopeRule` | ADR-0029 | `enforceAuditTransactionScope.nonTransactionalMutationInClosure` |
| `ForbidEloquentMutationInControllersRule` | ADR-0011 + ADR-0019 | `forbidEloquentMutationInControllers.eloquentMutationInController` |
| `EnforceResourceDataValidatorOptInRule` | ADR-0009 §EAGER_LOAD validator opt-in | `enforceResourceDataValidatorOptIn.missingValidatorCall` |
| `EnforceFormRequestToDtoRule` | ADR-0012 §FormRequest → DTO Flow | `enforceFormRequestToDto.missingToDtoMethod` |
| `EnforceCurrentUserAttributeRule` | War-room §Explicit over implicit | `enforceCurrentUserAttribute.useAttributeInsteadOfRequestUser` |
| `ForbidUntimedHttpClientRule` | War-room §Explicit HTTP timeouts (#8) | `forbidUntimedHttpClient.missingTimeout` (type-aware; flags an `Http` facade OR injected `Illuminate\Http\Client\Factory` chain that reaches a send verb with no explicit `->timeout()` / `withOptions(['timeout'])`. Conservative — fires only on fully-visible single-expression chains; declines split/helper-built chains + Guzzle/SDK surfaces to hold FP at zero. COMPLEMENTS, does not replace, the per-territory `ExternalHttpTimeoutTest`. on `main`, `[Unreleased]`) |
| `EnforceAuditModelProtectionsRule` | ADR-0001 §Append-only | `enforceAuditModelProtections.hasFactoryForbidden` / `.softDeletesForbidden` / `.updatedAtNotDisabled` (denylist-inversion; discovers audit models by shape — `auditModelNameSuffixes` default `AuditLog` OR `auditModelNamespacePrefixes` default `App\Models\Audit` — and flags `HasFactory` / `SoftDeletes` / missing `const UPDATED_AT = null`. shipped v0.7.0) |
| `EnforceActionResultDtoRule` | ADR-0020 + ADR-0011 | `enforceActionResultDto.arrayReturnFromExecute` (signature-only; flags an `array` / `?array` / `array\|Dto` union / `iterable` native return type on `App\Actions\*` `execute()`. Phpdoc-only `@return array{...}` is a deliberate miss; no `list<T>` carve-out. Seed kendo PR #1653. shipped v0.8.0) |
| `ForbidCredentialCastBypassRule` | War-room §Explicit over implicit (#1) + §Rotation-invariant credential handling (#10) | `forbidCredentialCastBypass.castBypassedByBuilderWrite` / `.modelSourceUnreadable` / `.castMapIncomplete` / `.configuredModelMissing` (flags a `hashed` / `encrypted` / `encrypted:*` cast column appearing as a key in a QUERY-BUILDER write payload — `update`/`insert`/`insertOrIgnore`/`insertGetId`/`upsert`/`updateOrInsert` on an Eloquent `Builder`, query `Builder` or `Relation`. Casts fire on the model path only, so a builder write stores the credential in plaintext with a green suite. The model path and the model-routing builder verbs (`create`/`updateOrCreate`/`firstOrCreate`/`createOrFirst`) are silent STRUCTURALLY — the receiver type gate, not an exemption list. Model comes from the builder/relation generic; `DB::table('…')` carries none and resolves only via the opt-in `credentialCastTableModels` map, default `[]`. Cast maps read from model SOURCE via the injected `@defaultAnalysisParser` — both `casts()` and `$casts`, merged across the ancestry AND the trait use-chain (class-declared beats trait-imported beats inherited). Composed maps are read as well — the literal inside `array_merge(parent::casts(), …)` and an array spread both contribute. THREE fail-open shapes are each reported under their own identifier rather than reading as castless: `.modelSourceUnreadable` (source cannot be located/parsed), `.castMapIncomplete` (read, but a declaration carries no array literal at all — `return self::CASTS;`), `.configuredModelMissing` (`credentialCastTableModels` names a nonexistent class). Payload keys read from the CONSTANT ARRAY TYPE, so a hoisted variable is caught. Seed lokalekeuze PR #65. on `main`, `[Unreleased]`) |
| `ConnectionTransactionReturnTypeExtension` | (type extension, no rule) | — (resolves `ConnectionInterface::transaction()` to the closure's return type. **Load-bearing on `illuminate/* ^12` only** — it annotates `@return mixed`; Laravel 13 annotates `@template TReturn`/`@return TReturn` and infers the same type unaided. Its teeth are measured by the `check-lowest-laravel` CI job, not by the unit suite on a Laravel-13 tree. WR-0855, WR-0860) |

Phase 2 expands the rule set: `EnforceAuditSnapshotOnRetryRule` (ADR-0001 §Snapshot-on-Retry Safety) was the first Phase 2 addition, promoted from cross-territory Pest arch tests (emmie PR #187, entreezuil PR #139, ublgenie PR #166, kendo PR #1029). `EnforceResourceDataValidatorOptInRule` (ADR-0009 §EAGER_LOAD validator opt-in) is the second Phase 2 addition, promoted from kendo PR #1084 under war-room enforcement queue #55. `EnforceFormRequestToDtoRule` (ADR-0012) is the third Phase 2 addition, promoted from entreezuil's `tests/Arch/FormRequestsTest.php` under the same queue #55 (instance 2). `EnforceExplicitHydrationRule` (ADR-0019) is the next Phase 2 candidate.

## Conventions

- **Namespace:** `ScriptDevelopment\PhpstanWarroomRules\` (PSR-4, `src/`).
- **Action namespace assumption:** Rules that scope to Actions match `App\Actions\*` — Laravel convention used by every consuming territory. If we onboard a territory with a different actions namespace, lift this into a parameter rather than fork.
- **Doctrine source in docblock:** Every rule's class-level docblock names its doctrine source (ADR or war-room principle). When a rule is added, the docblock is the contract.
- **Error identifiers read `cameLCase.cameLCase`,** and `RuleIdentifierConventionTest` checks that **per rule file** — every file in `src/Rules/` must contribute at least one identifier the test can read, and one that hands `identifier()` an expression it cannot resolve fails by name. It used to scan all rules into one list and assert only that the list was non-empty, which stayed true while `EnforceAuditModelProtectionsRule` (three identifiers routed through a private helper) contributed nothing at all (WR-0853). An identifier that is not a literal must be a string constant of the same class; there is no exemption list, deliberately.
- **No territory-specific exceptions hardcoded.** Per-territory false positives are suppressed via consumer `phpstan.neon` `ignoreErrors`, never by name in the rule code. (See LogRule history: emmie's hardcoded `Terminology::class` exception was dropped during package promotion.)

## Commands

| Command | Purpose |
|---|---|
| `composer test` | Run PHPUnit tests against rule fixtures |
| `composer test:coverage` | PHPUnit with clover coverage output (`build/logs/clover.xml`) |
| `composer coverage:check` | Line-coverage threshold gate (`bin/coverage-check.php`) |
| `composer mutation` | Run Infection mutation testing (developer-facing, `--threads=max --show-mutations`) |
| `composer mutation:ci` | Run Infection with `--logger-github` for inline PR annotations + threshold gate |
| `composer phpstan` | Self-analysis on the package's own source |
| `composer format` | Pint write |
| `composer format:check` | Pint check |

## CI

`.github/workflows/ci.yml` runs three jobs, all rolled up into the single required check `ci-passed`:

| Job | Matrix | Runs |
|---|---|---|
| `check` | PHP `8.4`, `8.5` | audit + format + phpstan + coverage + coverage gate + mutation. Resolves `illuminate/*` to the **highest** satisfying release. |
| `check-lowest-laravel` | PHP `8.4`, `8.5` × `illuminate/* ^12.0` | phpstan + tests only. |
| `check-production-tree` | PHP `8.4`, `--no-dev` install | phpstan only, on the tree a consumer actually installs. |

`ci-passed` requires every upstream lane to report `success`. It previously failed only on `failure` or `cancelled`, so a **skipped** lane passed the rollup — nothing skips today, but `ci-passed` is the only required check on `main`, so the first `if:` added upstream would have hollowed the gate out while still reporting green (WR-0852).

**Why the second job exists (WR-0855):** the package supports `illuminate/* ^12 || ^13`, but a PHP-only matrix always resolves to the highest major, so the lower of the two supported majors was never exercised — and `ConnectionTransactionReturnTypeExtension`'s entire reason for existing is a `@return mixed` annotation that Laravel 13 no longer carries. The job asserts the resolved major really is 12 before analysing; a silent fallback to 13 would make the leg pass for the wrong reason.

**Laravel 11 support was dropped (WR-0860, Commander ruling 2026-08-24).** The manifest advertised `^11.0` that Composer refuses to install: CVE-2026-48019's advisory covers all of `>=11.0.0,<12.0.0` and 11.x is past its security window, so `audit.block-insecure` rejects every 11.x release of `illuminate/mail` (measured — `composer update` exits 2 naming `v11.0.0, ..., v11.51.0`). Since no consumer runs Laravel 11, the constraint was removed rather than worked around. **Laravel 12 is now both the declared floor and the `check-lowest-laravel` pin — keep those two in step.**

**Why the third job exists (WR-0854):** six rules name an `illuminate/http` or `symfony/http-kernel` FQCN as an analysis anchor, and neither package is a dependency — those classes exist here only as hand-written fixtures under `tests/Fixtures/`, which `autoload-dev` classmaps. `composer phpstan` was therefore green against a tree no consumer ever installs, and reported **7 `class.notFound`** the moment the dev autoloader was removed. The anchors are now declared for analysis in `stubs/analysis-anchors.php` (wired through `phpstan.neon.dist`'s `scanFiles`, which `extension.neon` does not include, so consumers are untouched), and this job installs `--no-dev` and analyses. It first asserts a fixture-declared class is **not** autoloadable — without that control the job reports a clean analysis both when the production tree is sound and when the fixtures have crept back into the runtime autoloader.

**Why the anchors are `::class` and not FQCN strings.** Pint's `class_keyword` fixer calls `class_exists()` on every string literal and rewrites the resolvable ones back to `::class`; the Pint phar bundles five of the six, and half-bundles `Illuminate\Foundation\Http\FormRequest` so a literal for it makes `composer format` fatal outright. A plain FQCN string does not survive a format run, which is why the anchors are declared to PHPStan instead.

## Versioning

SemVer per ADR-0021:

- **Major** — new errors in code that previously passed.
- **Minor** — new rules added, or new options without changing defaults.
- **Patch** — bug fixes, false-positive narrowing, performance.

**Pre-1.0 (`0.x`) convention:** within `0.x` the package treats minor bumps as breaking, because Composer's `^0.x` caret locks at minor. A v0.2.0 release does not propagate to consumers pinned `^0.1.0` — they must update their pin to `^0.2` (or a wider constraint that crosses minor). Current pins per consumer are tracked in `campaigns/phpstan-warroom-rules/2026-05-06-first-contact-wave.md` § Outcome.

**Today (v0.x):** consuming territories pin `^0.{minor}` (e.g. `^0.2`). Each minor bump requires a coordinated consumer-side pin update. The CHANGELOG `[Unreleased]` block tracks each pending bump's audit demands.

**At 1.0 (when stability target is met):** consuming territories pin `^1.0` and inherit minor + patch automatically. Any rule that would surface new errors in already-clean code waits for a major bump.

## Release process

- `main` is always release-ready.
- Pull requests must update `CHANGELOG.md` under an `[Unreleased]` section.
- A release PR moves `[Unreleased]` to a versioned heading and tags the merge commit (`v1.x.y`).
- Packagist's webhook auto-sync picks up the tag and publishes the release; `release.yml` re-runs CI gates on the tagged commit and creates a GitHub release referencing the matching CHANGELOG entry.

## War Room ADR Projections

> Distilled operational rules from cross-project Architecture Decision Records.
> Canonical full ADRs at [adrs.script.nl](https://adrs.script.nl). This section is owned by the war room — do not edit directly.
> Last synced: 2026-08-11 (latest released tag v0.8.0)

### Applicable

- **ADR-0015 (ADR Governance)** — this section exists because ADR-0015 mandates it for non-BIO territories.
- **ADR-0021 (Canonical PHPStan Rules Package)** — this territory is the implementation. Doctrine source: ADR-0021 §Doctrine source in docblock, §Identifier convention, §No territory-specific exceptions, §Action namespace assumption, §Versioning, §Release process. Self-quality contract documented above.

### Non-applicable (the rules ship, the package does not consume them)

> Each bullet's rule→doctrine mapping is authoritative per the rule class's docblock "Doctrine source" line (ADR-0021 §Doctrine source in docblock).

- ADR-0001 (Audit Logging) — package distributes `LogRule` + `LogBuilderTruncateRule` (both §Append-only), `EnforceAuditSnapshotOnRetryRule` (§Snapshot-on-Retry Safety), and `EnforceAuditModelProtectionsRule` (§Append-only — flags audit-log models, discovered by shape, that use `HasFactory` / `SoftDeletes` or fail to disable `updated_at`; a denylist inversion of the consumer-side audit-model arch tests, shipped v0.7.0); does not itself maintain audit logs.
- ADR-0002 (Cascade Deletion) — no application surface.
- ADR-0009 (Unified ResourceData Pattern) — package distributes `EnforceResourceDataValidatorOptInRule` (§EAGER_LOAD validator opt-in, shipped in v0.3.0), `ForbidResourceWrappedInJsonResponseRule` (resources own their own response serialization — bans wrapping a `JsonResource` in `response()->json()` / `new JsonResponse()` inside controllers, shipped v0.5.0), and `ForbidInlineArrayJsonResponseInControllersRule` (the inverse — bans building the base `JsonResponse` / `response()->json()` from an ARRAY payload inside controllers; response shapes belong to a Resource / dedicated JsonResponse subclass, shipped v0.8.0); does not itself ship API resources.
- ADR-0011 (Action Class Architecture) — package distributes `EnforceActionTransactionsRule` + `ForbidDatabaseManagerInActionsRule`, and `ForbidEloquentMutationInControllersRule` (ADR-0011 + ADR-0019, shipped v0.4.0); itself has no Actions.
- ADR-0012 (FormRequest → DTO) — package distributes `EnforceFormRequestToDtoRule` (§FormRequest → DTO Flow, shipped v0.4.0; `toDtos()` plural support v0.6.1); itself has no HTTP surface.
- ADR-0014 (Domain-Driven Frontend) — no frontend.
- ADR-0016 (Config Attribute Injection) — no Laravel container surface.
- ADR-0017 (Page Integration Tests) — no pages.
- ADR-0019 (Explicit Model Hydration) — package distributes `ForbidEloquentMutationInControllersRule` (ADR-0011 + ADR-0019, shipped v0.4.0) covering the controller mutation surface; itself has no models. (The earlier Phase-2 `EnforceExplicitHydrationRule` candidate has been subsumed by the controller-mutation rule for the controller surface; a broader application-wide hydration rule remains a future candidate.)
- ADR-0020 (Input/Result DTO Split) — package distributes `EnforceActionResultDtoRule` (bans an `array` native return type on `App\Actions\*` `execute()` — a compound result is a Result DTO, not a bag of string keys; ADR-0020 + ADR-0011, shipped v0.8.0); itself has no DTOs.
- ADR-0024 (Automated External Provisioning) — no provisioning surface.
- ADR-0029 (Audit Row Durability Contract) — package distributes `EnforceAuditTransactionScopeRule` (§Decision rule 3 — flags non-transactional state mutations inside `transaction(...)` closures in `App\Actions\*`, shipped v0.4.0); itself maintains no audit rows.

### War-room Architectural Principle rules (no published ADR)

- **Explicit over implicit** — package distributes `ForbidAbortHelperRule` (bans `abort()` / `abort_if()` / `abort_unless()`; shipped), `EnforceCurrentUserAttributeRule` (flags `Request::user()` / `Auth::user()` / `auth()->user()` in `App\Http\Controllers`, steering to the `#[CurrentUser]` container attribute per Architectural Principle #9; shipped v0.4.0), `ForbidHttpExceptionInActionsRule` (type-aware sibling of `ForbidAbortHelperRule` — bans throwing the `Symfony\…\HttpException` family from `App\Actions\*`; HTTP status concerns belong to the HTTP layer per Principles #1 + #3; `ValidationException` deliberately out of scope; shipped v0.5.0), `ForbidResourceWrappedInJsonResponseRule` (bans wrapping a `JsonResource` in `response()->json()` / `new JsonResponse()` inside controllers per Principle #1 + ADR-0009; shipped v0.5.0), and `ForbidRawExceptionMessageInResponseRule` (bans a raw `Throwable::getMessage()` — or the `Throwable` itself — reaching a client-facing response sink per Principle #1 + information-disclosure hardening for the ISO 27001 / AVG / NEN 7510 consumers; default sink `Laravel\Mcp\Response::error`, configurable via `rawExceptionMessageSinks`; server-side logging never flags; `// @leak-safe:` exemption; shipped v0.8.0). These enforce war-room §Architectural Principles (some also touching numbered ADRs) — each rule's docblock "Doctrine source" line names its authority.
- **Rotation-invariant credential handling (#10) + Explicit over implicit (#1)** — package distributes `ForbidCredentialCastBypassRule` (flags a `hashed` / `encrypted` / `encrypted:*` cast column named as a key in a query-builder write payload, where the cast never fires and the raw credential reaches SQL; the model path is structurally silent because a `Model` receiver never matches the builder/relation type gate. `DB::table()` resolution is opt-in via `credentialCastTableModels` — a model is never inferred from a table name. ISO 27001 A.5.33 / AVG relevance on the compliance consumers. Seed lokalekeuze PR #65, war-room enforcement queue #217; on `main`, `[Unreleased]`).
- **Explicit HTTP timeouts (#8)** — package distributes `ForbidUntimedHttpClientRule` (flags an `Http` facade / injected `Illuminate\Http\Client\Factory` chain reaching a send verb without an explicit request timeout, per Architectural Principle #8; the AST-aware, omission-closing successor to the per-territory `ExternalHttpTimeoutTest` named-list Pest tests — conservative single-expression detection, declines split/helper-built chains + Guzzle/SDK surfaces; COMPLEMENTS the named-lists rather than replacing them; on `main`, `[Unreleased]`). Seed: war-room enforcement queue #58.

### War-room internal ADRs

- ADR-0005 (Spy System) / ADR-0007 (Soldiers) / ADR-0010 (Squads) — these govern the war-room agent fleet that operates *on* this territory; not consumed by the package itself.

## What this territory does NOT do

- Does not enforce its rules on itself's source code beyond syntactic correctness — the rules target Laravel application code (`App\Actions`, `App\*`), not a static-analysis package.
- Does not ship operational PHP code or services. It is a static-analysis library only.
- Does not maintain a documentation site. Doctrine lives in `adrs.script.nl`; usage docs live in `README.md`.
