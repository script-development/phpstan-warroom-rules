<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\EnforceAuditModelProtectionsRule;

use function sprintf;

/**
 * @extends RuleTestCase<EnforceAuditModelProtectionsRule>
 */
final class EnforceAuditModelProtectionsRuleTest extends RuleTestCase
{
    private const string HAS_FACTORY = '%s is an audit model but uses the HasFactory trait — audit rows are written exclusively through the hash-chained audit writer inside a transaction; a factory offers a direct-insert path that bypasses the chain. Remove HasFactory. See ADR-0001 §Append-only.';

    private const string SOFT_DELETES = '%s is an audit model but uses the SoftDeletes trait — audit logs are append-only and must never be soft-deleted. Remove SoftDeletes. See ADR-0001 §Append-only.';

    private const string UPDATED_AT = '%s is an audit model but does not disable updated_at — an audit row is written once and never mutated. Declare `public const UPDATED_AT = null;`. See ADR-0001 §Append-only.';

    /**
     * Override hook: when set, `getRule()` returns this instance instead of the
     * default. Lets a single test reconfigure the discovery parameters.
     */
    private ?Rule $ruleOverride = null;

    public function testCleanAuditModelIsNotFlagged(): void
    {
        // Extends Model, `const UPDATED_AT = null`, no HasFactory/SoftDeletes —
        // the canonical compliant shape. Discovered by both signals, fires nothing.
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditModelProtections/CleanAuditLog.php'],
            [],
        );
    }

    public function testHasFactoryIsFlagged(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditModelProtections/FactoryAuditLog.php'],
            [
                [
                    sprintf(self::HAS_FACTORY, 'App\Models\Audit\FactoryAuditLog'),
                    15,
                ],
            ],
        );
    }

    public function testSoftDeletesIsFlagged(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditModelProtections/SoftDeleteAuditLog.php'],
            [
                [
                    sprintf(self::SOFT_DELETES, 'App\Models\Audit\SoftDeleteAuditLog'),
                    15,
                ],
            ],
        );
    }

    public function testMutableUpdatedAtIsFlagged(): void
    {
        // Forgot `const UPDATED_AT = null` — inherits the framework default
        // `'updated_at'`. The "forgot a protection" omission the inversion catches.
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditModelProtections/MutableAuditLog.php'],
            [
                [
                    sprintf(self::UPDATED_AT, 'App\Models\Audit\MutableAuditLog'),
                    15,
                ],
            ],
        );
    }

    public function testAllThreeProtectionsFireIndependently(): void
    {
        // HasFactory + SoftDeletes + no UPDATED_AT on one class — three separate
        // errors at the class line, in rule-emission order.
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditModelProtections/TripleViolationAuditLog.php'],
            [
                [
                    sprintf(self::HAS_FACTORY, 'App\Models\Audit\TripleViolationAuditLog'),
                    16,
                ],
                [
                    sprintf(self::SOFT_DELETES, 'App\Models\Audit\TripleViolationAuditLog'),
                    16,
                ],
                [
                    sprintf(self::UPDATED_AT, 'App\Models\Audit\TripleViolationAuditLog'),
                    16,
                ],
            ],
        );
    }

    public function testNamespaceSignalDiscoversNonAuditLogSuffix(): void
    {
        // `AuthEventLog` does not end in `AuditLog`; it is discovered purely by
        // the `App\Models\Audit` namespace signal (the entreezuil/ublgenie
        // channel-log shape). Proves the namespace leg catches what the suffix
        // leg misses.
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditModelProtections/AuthEventLog.php'],
            [
                [
                    sprintf(self::HAS_FACTORY, 'App\Models\Audit\AuthEventLog'),
                    18,
                ],
            ],
        );
    }

    public function testSuffixSignalDiscoversModelOutsideAuditNamespace(): void
    {
        // `App\Models\ScatteredAuditLog` sits outside `App\Models\Audit` (the
        // kendo scattered shape); discovered purely by the `AuditLog` suffix.
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditModelProtections/ScatteredAuditLog.php'],
            [
                [
                    sprintf(self::HAS_FACTORY, 'App\Models\ScatteredAuditLog'),
                    18,
                ],
            ],
        );
    }

    public function testNonModelNamedLikeAuditLogIsNotFlagged(): void
    {
        // `App\Support\FakeAuditLog` matches the `AuditLog` suffix but is NOT an
        // Eloquent Model — the type gate excludes it. Without the gate it would
        // wrongly fire updatedAtNotDisabled (it declares no UPDATED_AT).
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditModelProtections/FakeAuditLog.php'],
            [],
        );
    }

    public function testAbstractBaseIsExemptAndConcreteLeafInheritsViolation(): void
    {
        // The abstract base carries HasFactory but is exempt (never a record);
        // the concrete leaf declares no traits of its own yet is flagged via the
        // transitive trait walk. It inherits `const UPDATED_AT = null` from the
        // base, so ONLY the HasFactory error fires, at the leaf.
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/AuditModelProtections/AbstractAuditBase.php',
                __DIR__ . '/../Fixtures/AuditModelProtections/ConcreteInheritedAuditLog.php',
            ],
            [
                [
                    sprintf(self::HAS_FACTORY, 'App\Models\Audit\ConcreteInheritedAuditLog'),
                    15,
                ],
            ],
        );
    }

    public function testSiblingNamespaceSharingTextPrefixIsNotFlagged(): void
    {
        // `App\Models\AuditReport\ReportSummary` shares the text prefix
        // `App\Models\Audit` but is not under the `App\Models\Audit\` namespace.
        // The trailing separator keeps it out of scope despite its HasFactory —
        // the namespace match is on a namespace boundary, not a text prefix.
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditModelProtections/ReportSummary.php'],
            [],
        );
    }

    public function testTraitOfATraitHasFactoryIsCaughtTransitively(): void
    {
        // HasFactory is reached only through a composed trait
        // (`ComposedFactoryTrait use HasFactory`), never directly nor via a
        // parent class. The recursive trait walk must catch it.
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/AuditModelProtections/ComposedFactoryTrait.php',
                __DIR__ . '/../Fixtures/AuditModelProtections/ComposedTraitAuditLog.php',
            ],
            [
                [
                    sprintf(self::HAS_FACTORY, 'App\Models\Audit\ComposedTraitAuditLog'),
                    16,
                ],
            ],
        );
    }

    public function testRegularModelWithHasFactoryIsNotFlagged(): void
    {
        // A normal model using HasFactory + SoftDeletes + mutable updated_at
        // matches neither the suffix nor the namespace — the structural-identity
        // gate keeps the rule off the ordinary model surface.
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditModelProtections/RegularPost.php'],
            [],
        );
    }

    public function testCustomSuffixDefaultIsClean(): void
    {
        // `App\Models\LifecycleTrail` matches no default signal.
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditModelProtections/LifecycleTrail.php'],
            [],
        );
    }

    public function testCustomSuffixParameterBringsModelIntoScope(): void
    {
        // Configure `auditModelNameSuffixes: ['Trail']` — the model now matches
        // and its HasFactory violation fires. Proves the suffix parameter is
        // honoured end-to-end (default namespace prefixes retained).
        $this->ruleOverride = new EnforceAuditModelProtectionsRule(auditModelNameSuffixes: ['Trail']);

        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditModelProtections/LifecycleTrail.php'],
            [
                [
                    sprintf(self::HAS_FACTORY, 'App\Models\LifecycleTrail'),
                    17,
                ],
            ],
        );
    }

    public function testCustomNamespaceDefaultIsClean(): void
    {
        // `App\Ledger\PaymentRecord` matches no default signal.
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditModelProtections/PaymentRecord.php'],
            [],
        );
    }

    public function testCustomNamespaceParameterBringsModelIntoScope(): void
    {
        // Configure `auditModelNamespacePrefixes: ['App\Ledger']` — the model now
        // matches and its HasFactory violation fires. Proves the namespace-prefix
        // parameter is honoured end-to-end (default suffixes retained).
        $this->ruleOverride = new EnforceAuditModelProtectionsRule(auditModelNamespacePrefixes: ['App\Ledger']);

        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditModelProtections/PaymentRecord.php'],
            [
                [
                    sprintf(self::HAS_FACTORY, 'App\Ledger\PaymentRecord'),
                    18,
                ],
            ],
        );
    }

    public function testRuleResolvesFromExtensionNeonAndFires(): void
    {
        // End-to-end pin on the extension.neon registration path consumers use:
        // resolve the rule from the PHPStan container so the shipped
        // `auditModelNamespacePrefixes` / `auditModelNameSuffixes` defaults and
        // the `%...%` argument wiring are exercised — NOT the PHP constructor
        // default. A NEON quoting regression in a shipped default (e.g. the
        // double-backslash single-quoted form) silently no-ops discovery while
        // every direct-instantiation test stays green; this is the only gate
        // that catches it.
        $this->ruleOverride = self::getContainer()->getByType(EnforceAuditModelProtectionsRule::class);

        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditModelProtections/FactoryAuditLog.php'],
            [
                [
                    sprintf(self::HAS_FACTORY, 'App\Models\Audit\FactoryAuditLog'),
                    15,
                ],
            ],
        );
    }

    /**
     * Load the shipped extension.neon so testRuleResolvesFromExtensionNeonAndFires
     * can pull the rule out of the container with its NEON-configured discovery
     * parameters applied.
     *
     * @return array<int, string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../../extension.neon',
        ];
    }

    protected function getRule(): Rule
    {
        return $this->ruleOverride ?? new EnforceAuditModelProtectionsRule;
    }
}
