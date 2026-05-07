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
| `LogRule` | ADR-0001 §Append-only | `logRule.logModification` |
| `EnforceAuditSnapshotOnRetryRule` | ADR-0001 §Snapshot-on-Retry Safety | `enforceAuditSnapshotOnRetry.firstStatementMustResetState` |
| `ConnectionTransactionReturnTypeExtension` | (type extension, no rule) | — |

Phase 2 expands the rule set: `EnforceAuditSnapshotOnRetryRule` (ADR-0001 §Snapshot-on-Retry Safety) is the first Phase 2 addition, promoted from cross-territory Pest arch tests (emmie PR #187, entreezuil PR #139, ublgenie PR #166, kendo PR #1029). `EnforceExplicitHydrationRule` (ADR-0019) is the next Phase 2 candidate.

## Conventions

- **Namespace:** `ScriptDevelopment\PhpstanWarroomRules\` (PSR-4, `src/`).
- **Action namespace assumption:** Rules that scope to Actions match `App\Actions\*` — Laravel convention used by every consuming territory. If we onboard a territory with a different actions namespace, lift this into a parameter rather than fork.
- **Doctrine source in docblock:** Every rule's class-level docblock names its doctrine source (ADR or war-room principle). When a rule is added, the docblock is the contract.
- **No territory-specific exceptions hardcoded.** Per-territory false positives are suppressed via consumer `phpstan.neon` `ignoreErrors`, never by name in the rule code. (See LogRule history: emmie's hardcoded `Terminology::class` exception was dropped during package promotion.)

## Commands

| Command | Purpose |
|---|---|
| `composer test` | Run PHPUnit tests against rule fixtures |
| `composer phpstan` | Self-analysis on the package's own source |
| `composer format` | Pint write |
| `composer format:check` | Pint check |

## Versioning

SemVer per ADR-0021:

- **Major** — new errors in code that previously passed.
- **Minor** — new rules added, or new options without changing defaults.
- **Patch** — bug fixes, false-positive narrowing, performance.

Consuming territories pin `^1.0`. Any rule that would surface new errors in already-clean code waits for a major bump.

## Release process

- `main` is always release-ready.
- Pull requests must update `CHANGELOG.md` under an `[Unreleased]` section.
- A release PR moves `[Unreleased]` to a versioned heading and tags the merge commit (`v1.x.y`).
- Packagist's webhook auto-sync picks up the tag and publishes the release; `release.yml` re-runs CI gates on the tagged commit and creates a GitHub release referencing the matching CHANGELOG entry.

## What this territory does NOT do

- Does not enforce its rules on itself's source code beyond syntactic correctness — the rules target Laravel application code (`App\Actions`, `App\*`), not a static-analysis package.
- Does not ship operational PHP code or services. It is a static-analysis library only.
- Does not maintain a documentation site. Doctrine lives in `adrs.script.nl`; usage docs live in `README.md`.
