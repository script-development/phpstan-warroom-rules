<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function end;
use function explode;
use function sprintf;
use function str_ends_with;
use function str_starts_with;

/**
 * Enforces ADR-0001 §Append-only on audit-log models: a model recognised as an
 * audit record by SHAPE (name suffix and/or namespace) must carry the
 * append-only protections its arch-test predecessors assert — no `HasFactory`
 * (a factory is a direct-insert path that bypasses the hash-chained writer), no
 * `SoftDeletes` (an audit row is never removed, soft or hard), and a disabled
 * `updated_at` (an audit row is written once and never mutated).
 *
 * Doctrine source: ADR-0001 §Append-only (Audit Logging System).
 *
 * This is a denylist-INVERSION rule (war-room enforcement queue #46). The
 * arch-test predecessors it supersedes enumerate audit models by a
 * hand-maintained class list (kendo `tests/Arch/AuditTest.php` — 13 FQCNs) or a
 * namespace directory sweep (entreezuil `tests/Architecture/AuditTest.php`,
 * ublgenie `tests/Arch/AuditTest.php` — `App\Models\Audit\*`) and then check
 * `HasFactory` / `SoftDeletes` / `UPDATED_AT`. A hand-maintained list silently
 * exempts every future audit model added outside the list — the exact
 * omission-escape failure the inversion closes. Here the scan discovers audit
 * models by structural identity and flags any that lacks a protection; nothing
 * escapes by being forgotten.
 *
 * Discovery (structural identity — a developer cannot drop coverage without
 * renaming the model, unlike keying on a protection the developer might forget):
 *   1. Class is a (transitive) subtype of `Illuminate\Database\Eloquent\Model`.
 *   2. Class is concrete — an abstract intermediate (`BaseAuditLog`) is exempt;
 *      its concrete leaves carry any inherited violation transitively.
 *   3. Class short-name ends with any configured suffix
 *      (`auditModelNameSuffixes`, default `['AuditLog']`) OR the class FQCN
 *      sits under any configured namespace prefix
 *      (`auditModelNamespacePrefixes`, default `['App\Models\Audit']`). The two
 *      signals are a UNION so both fleet identification strategies are covered:
 *      kendo scatters `*AuditLog` models across `App\Models` + `App\Models\
 *      Central` (suffix signal); entreezuil / ublgenie collect them under
 *      `App\Models\Audit\*` including non-`AuditLog`-suffixed channel logs like
 *      `AuthEventLog` / `SmsEventLog` (namespace signal).
 *
 * Protections checked (each fires independently — a model missing several
 * protections yields several errors at the class line):
 *   - `HasFactory` present            -> enforceAuditModelProtections.hasFactoryForbidden
 *   - `SoftDeletes` present           -> enforceAuditModelProtections.softDeletesForbidden
 *   - `UPDATED_AT` not disabled (null) -> enforceAuditModelProtections.updatedAtNotDisabled
 *
 * Trait detection is transitive: a trait used by the model, by any ancestor
 * class, or by a trait-of-a-trait all count, so an inherited `HasFactory` on an
 * abstract base is caught at the concrete leaf. `UPDATED_AT` is read via
 * reflection constant lookup — the framework `Model` base defines
 * `const UPDATED_AT = 'updated_at'`, so a model that never overrides it to
 * `null` is flagged (the append-only tell every seed model declares).
 *
 * Configuration expresses PATTERNS, never enumerated class names — no consumer
 * class name is ever hardcoded in this rule body, preserving the package's
 * "never by name inside the rule" convention. A consumer whose audit models use
 * a different suffix (e.g. a channel-log family) or namespace overrides the
 * parameters. A model that disables timestamps wholesale
 * (`public $timestamps = false;`) never writes `updated_at` at all and is
 * recognised natively as satisfying the updated_at protection — no
 * `ignoreErrors` suppression needed; a remaining genuine non-audit false
 * positive is suppressed per-file via `ignoreErrors` keyed on the specific
 * identifier.
 *
 * @implements Rule<InClassNode>
 */
final class EnforceAuditModelProtectionsRule implements Rule
{
    /**
     * @param list<string> $auditModelNamespacePrefixes FQCN namespace prefixes
     *                                                  whose models are audit
     *                                                  records (default
     *                                                  `App\Models\Audit`)
     * @param list<string> $auditModelNameSuffixes      class short-name
     *                                                  suffixes marking an audit
     *                                                  record (default
     *                                                  `AuditLog`)
     */
    public function __construct(
        private array $auditModelNamespacePrefixes = ['App\Models\Audit'],
        private array $auditModelNameSuffixes = ['AuditLog'],
    ) {}

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classNode = $node->getOriginalNode();

        if (!$classNode instanceof Class_) {
            return [];
        }

        if ($classNode->isAbstract()) {
            return [];
        }

        $classReflection = $node->getClassReflection();

        if (!$this->matchesAuditModelIdentity($classReflection)) {
            return [];
        }

        // Type gate: a class merely NAMED like an audit log (a DTO, a service,
        // an enum) is not an audit model. Only Eloquent models carry the
        // trait / timestamp surface these protections govern.
        if (!$classReflection->isSubclassOf(Model::class)) {
            return [];
        }

        $name = $classReflection->getName();
        $errors = [];

        if ($this->usesTraitTransitively($classReflection, HasFactory::class)) {
            $errors[] = $this->buildError(
                sprintf(
                    '%s is an audit model but uses the HasFactory trait — audit rows are written exclusively through the hash-chained audit writer inside a transaction; a factory offers a direct-insert path that bypasses the chain. Remove HasFactory. See ADR-0001 §Append-only.',
                    $name,
                ),
                'enforceAuditModelProtections.hasFactoryForbidden',
                $classNode->getStartLine(),
            );
        }

        if ($this->usesTraitTransitively($classReflection, SoftDeletes::class)) {
            $errors[] = $this->buildError(
                sprintf(
                    '%s is an audit model but uses the SoftDeletes trait — audit logs are append-only and must never be soft-deleted. Remove SoftDeletes. See ADR-0001 §Append-only.',
                    $name,
                ),
                'enforceAuditModelProtections.softDeletesForbidden',
                $classNode->getStartLine(),
            );
        }

        if (!$this->updatedAtIsDisabled($classReflection)) {
            $errors[] = $this->buildError(
                sprintf(
                    '%s is an audit model but does not disable updated_at — an audit row is written once and never mutated. Declare `public const UPDATED_AT = null;` (or disable timestamps wholesale with `public $timestamps = false;`). See ADR-0001 §Append-only.',
                    $name,
                ),
                'enforceAuditModelProtections.updatedAtNotDisabled',
                $classNode->getStartLine(),
            );
        }

        return $errors;
    }

    /**
     * Structural-identity gate: the class short-name ends with a configured
     * suffix, OR its FQCN sits under a configured namespace prefix. The trailing
     * separator on the namespace match keeps `App\Models\AuditReport\X` from
     * matching an `App\Models\Audit` prefix.
     */
    private function matchesAuditModelIdentity(ClassReflection $classReflection): bool
    {
        $name = $classReflection->getName();
        $parts = explode('\\', $name);
        $shortName = (string) end($parts);

        foreach ($this->auditModelNameSuffixes as $suffix) {
            if ($suffix !== '' && str_ends_with($shortName, $suffix)) {
                return true;
            }
        }

        foreach ($this->auditModelNamespacePrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($name, $prefix . '\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * True iff `$traitFqcn` is used by the class, any ancestor class, or any
     * trait transitively used by those (trait-of-a-trait). `getTraits(true)`
     * flattens traits-used-by-traits; walking `getParentClass()` catches an
     * inherited `HasFactory` on an abstract base.
     */
    private function usesTraitTransitively(ClassReflection $classReflection, string $traitFqcn): bool
    {
        for ($current = $classReflection; $current !== null; $current = $current->getParentClass()) {
            foreach ($current->getTraits(true) as $trait) {
                if ($trait->getName() === $traitFqcn) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The framework `Model` base defines `const UPDATED_AT = 'updated_at'`, so
     * every Eloquent model resolves the constant; an audit model must override
     * it to `null` to opt out of the mutable timestamp. A missing constant
     * (defensive — not reachable for a real Model subtype) is treated as
     * not-disabled.
     *
     * A model that disables timestamps wholesale (`public $timestamps = false;`
     * — the framework base declares `public $timestamps = true`) never writes
     * `updated_at` either, so it satisfies the protection natively rather than
     * needing a per-file `ignoreErrors` suppression. Both reads use the default
     * property/constant value, matching Eloquent's own decision points
     * (`usesTimestamps()` / `getUpdatedAtColumn()`).
     *
     * Reads the literal values via native reflection (consumed inline — no
     * generic-typed parameter, so the `ReflectionClass<object>` invariance trap
     * does not apply). PHPStan's `ClassConstantReflection::getValueType()` does
     * not reliably resolve an untyped `= null` literal to a `NullType` in the
     * fixture analysis environment, so the native literal value is the
     * dependable read.
     */
    private function updatedAtIsDisabled(ClassReflection $classReflection): bool
    {
        $native = $classReflection->getNativeReflection();

        if ($native->hasConstant('UPDATED_AT') && $native->getConstant('UPDATED_AT') === null) {
            return true;
        }

        $defaults = $native->getDefaultProperties();

        return ($defaults['timestamps'] ?? true) === false;
    }

    private function buildError(string $message, string $identifier, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier($identifier)
            ->line($line)
            ->build();
    }
}
