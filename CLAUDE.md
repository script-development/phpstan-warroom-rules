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
| `LogRule` | ADR-0001 §Append-only | `logRule.logModification` (covers instance `update`/`delete`/`forceDelete`/`forceDeleteQuietly`; static `Model::destroy()` / `Model::forceDestroy()` ship with v0.3.0 per `[Unreleased]`) |
| `LogBuilderTruncateRule` | ADR-0001 §Append-only | `logRule.logModification` (shared with `LogRule`; covers `Builder->truncate()` on Log-named tables — ships with v0.3.0 per `[Unreleased]`) |
| `EnforceAuditSnapshotOnRetryRule` | ADR-0001 §Snapshot-on-Retry Safety | `enforceAuditSnapshotOnRetry.firstStatementMustResetState` |
| `EnforceAuditTransactionScopeRule` | ADR-0029 | `enforceAuditTransactionScope.nonTransactionalMutationInClosure` |
| `ForbidEloquentMutationInControllersRule` | ADR-0011 + ADR-0019 | `forbidEloquentMutationInControllers.eloquentMutationInController` |
| `EnforceResourceDataValidatorOptInRule` | ADR-0009 §EAGER_LOAD validator opt-in | `enforceResourceDataValidatorOptIn.missingValidatorCall` |
| `EnforceFormRequestToDtoRule` | ADR-0012 §FormRequest → DTO Flow | `enforceFormRequestToDto.missingToDtoMethod` |
| `EnforceCurrentUserAttributeRule` | War-room §Explicit over implicit | `enforceCurrentUserAttribute.useAttributeInsteadOfRequestUser` |
| `ConnectionTransactionReturnTypeExtension` | (type extension, no rule) | — |

Phase 2 expands the rule set: `EnforceAuditSnapshotOnRetryRule` (ADR-0001 §Snapshot-on-Retry Safety) was the first Phase 2 addition, promoted from cross-territory Pest arch tests (emmie PR #187, entreezuil PR #139, ublgenie PR #166, kendo PR #1029). `EnforceResourceDataValidatorOptInRule` (ADR-0009 §EAGER_LOAD validator opt-in) is the second Phase 2 addition, promoted from kendo PR #1084 under war-room enforcement queue #55. `EnforceFormRequestToDtoRule` (ADR-0012) is the third Phase 2 addition, promoted from entreezuil's `tests/Arch/FormRequestsTest.php` under the same queue #55 (instance 2). `EnforceExplicitHydrationRule` (ADR-0019) is the next Phase 2 candidate.

## Conventions

- **Namespace:** `ScriptDevelopment\PhpstanWarroomRules\` (PSR-4, `src/`).
- **Action namespace assumption:** Rules that scope to Actions match `App\Actions\*` — Laravel convention used by every consuming territory. If we onboard a territory with a different actions namespace, lift this into a parameter rather than fork.
- **Doctrine source in docblock:** Every rule's class-level docblock names its doctrine source (ADR or war-room principle). When a rule is added, the docblock is the contract.
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
> Last synced: 2026-05-08

### Applicable

- **ADR-0015 (ADR Governance)** — this section exists because ADR-0015 mandates it for non-BIO territories.
- **ADR-0021 (Canonical PHPStan Rules Package)** — this territory is the implementation. Doctrine source: ADR-0021 §Doctrine source in docblock, §Identifier convention, §No territory-specific exceptions, §Action namespace assumption, §Versioning, §Release process. Self-quality contract documented above.

### Non-applicable (the rules ship, the package does not consume them)

- ADR-0001 (Audit Logging) — package distributes `LogRule` + `EnforceAuditSnapshotOnRetryRule`; does not itself maintain audit logs.
- ADR-0002 (Cascade Deletion) — no application surface.
- ADR-0009 (Unified ResourceData Pattern) — package distributes `EnforceResourceDataValidatorOptInRule` (Phase 2, `[Unreleased]`); does not itself ship API resources.
- ADR-0011 (Action Class Architecture) — package distributes `EnforceActionTransactionsRule` + `ForbidDatabaseManagerInActionsRule`; itself has no Actions.
- ADR-0012 (FormRequest → DTO) — no HTTP surface.
- ADR-0014 (Domain-Driven Frontend) — no frontend.
- ADR-0016 (Config Attribute Injection) — no Laravel container surface.
- ADR-0017 (Page Integration Tests) — no pages.
- ADR-0019 (Explicit Model Hydration) — no models. Phase 2 candidate `EnforceExplicitHydrationRule` will distribute this rule.
- ADR-0020 (Input/Result DTO Split) — no DTOs.
- ADR-0024 (Automated External Provisioning) — no provisioning surface.

### War-room internal ADRs

- ADR-0005 (Spy System) / ADR-0007 (Soldiers) / ADR-0010 (Squads) — these govern the war-room agent fleet that operates *on* this territory; not consumed by the package itself.

## What this territory does NOT do

- Does not enforce its rules on itself's source code beyond syntactic correctness — the rules target Laravel application code (`App\Actions`, `App\*`), not a static-analysis package.
- Does not ship operational PHP code or services. It is a static-analysis library only.
- Does not maintain a documentation site. Doctrine lives in `adrs.script.nl`; usage docs live in `README.md`.
