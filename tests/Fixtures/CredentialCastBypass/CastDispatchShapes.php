<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass\Dispatch;

use Illuminate\Database\Eloquent\Model;

/**
 * The cast-DECLARATION shape table. One class per shape a consumer model can
 * take, deliberately in ONE file so the shapes read side by side — they are a
 * table, and splitting them across nineteen files is what let the earlier
 * fixtures cover ten shapes while looking exhaustive.
 *
 * Laravel resolves the effective map exactly once, in
 * `HasAttributes::initializeHasAttributes()`:
 *
 *     $this->casts = array_merge($this->casts, $this->casts());
 *
 * so `$casts` is a PROPERTY (one surviving declaration, most derived wins,
 * replacing not merging) and `casts()` is a METHOD reached by a SINGLE virtual
 * dispatch (only the nearest body runs, and an ancestor contributes only through
 * an explicit `parent::casts()`).
 *
 * `ForbidCredentialCastBypassRuleTest::testTheRuleAgreesWithPhpsOwnCastResolution…`
 * computes the expectation for every class here from PHP itself rather than from
 * a hand-written list, and asserts the two readings disagree on enough rows that
 * the table is actually exercising the difference.
 *
 * **Every class here is LOADED, not merely parsed** — that is what makes the
 * expectation PHP's own answer instead of someone's reading of Laravel, and it
 * constrains what can live here: a shape must be composable on the package's
 * MINIMUM PHP, not just the newest. One shape is absent for exactly that reason.
 * A trait declaring a non-empty `$casts` DEFAULT is a fatal composition error on
 * PHP 8.4 — `Model` already declares `protected $casts = []` through
 * `HasAttributes`, and 8.4 requires an inherited and a trait-imported property
 * to agree on their default ("the definition differs and is considered
 * incompatible"); PHP 8.5 accepts it. Measured on CI, where 8.5 passed and both
 * 8.4 legs died. The trait-`$casts` shape is therefore pinned by the
 * analysis-only fixtures instead (`HasEncryptedNotesProperty` on
 * `TraitCastModel`), which PHPStan parses and never composes — the reason the
 * incompatibility went unnoticed there.
 */
trait DeclaresSecretViaMethod
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['trait_method_secret' => 'hashed'];
    }
}

trait DeclaresPlainPasswordViaMethod
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['password' => 'string'];
    }
}

trait DeclaresPlainPasswordViaTraitToBeExcluded
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['password' => 'string'];
    }
}

trait DeclaresHashedPasswordViaTraitToBeExcluded
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}

class MethodBase extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}

/**
 * The control: the shape every other row is measured against.
 */
class LeafMethod extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}

/**
 * Declares nothing, so dispatch passes through to the parent's body.
 */
class InheritedMethod extends MethodBase {}

/**
 * Overrides `casts()` WITHOUT calling the parent, and never mentions the
 * parent's credential column. Dispatch runs this body only, so `password`
 * carries no cast at all — flagging it is a false positive on a model whose
 * every write is equally raw.
 */
class ReplacingOverride extends MethodBase
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['nickname' => 'string'];
    }
}

/** Same replacement, but naming the parent's column — a key collision masks a
 * merge-everything reading's error here, which is why the row above exists. */
class ReplacingOverrideSameColumn extends MethodBase
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['password' => 'string'];
    }
}

/**
 * The idiomatic pass-through: carries no literal and needs none.
 */
class PassThroughOverride extends MethodBase
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return parent::casts();
    }
}

class ComposingOverride extends MethodBase
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), ['api_token' => 'encrypted']);
    }
}

class SpreadingOverride extends MethodBase
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [...parent::casts(), 'api_token' => 'encrypted'];
    }
}

class PropertyBase extends Model
{
    /** @var array<string, string> */
    protected $casts = ['password' => 'hashed'];
}

/**
 * A property IS inherited, unlike a replaced `casts()` body.
 */
class InheritedProperty extends PropertyBase {}

/**
 * Redeclares the property. PHP keeps ONE declaration — this one — so the
 * parent's `password` entry does not exist at runtime.
 */
class RedeclaringProperty extends PropertyBase
{
    /** @var array<string, string> */
    protected $casts = ['nickname' => 'string'];
}

/**
 * Both forms on one class. `array_merge($this->casts, $this->casts())` puts the
 * METHOD second, so the method wins whichever order the file declares them in.
 *
 * Only ONE order appears here, and not by choice: the canonical Pint config
 * carries `ordered_class_elements`, which rewrites a method-before-property
 * class into this shape — a second fixture in the other order was silently
 * reformatted into a byte-identical twin of this one, with every gate green.
 * `TraitMethodAndClassProperty` below carries the same disagreement across a
 * trait boundary, where no formatter can collapse it, and the implementation
 * reads the two forms into separate buckets rather than folding them in
 * statement order, so source order is structurally unreachable.
 */
class PropertyThenMethod extends Model
{
    /** @var array<string, string> */
    protected $casts = ['password' => 'hashed'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['password' => 'string'];
    }
}

class TraitMethodAndClassProperty extends Model
{
    use DeclaresPlainPasswordViaMethod;

    /** @var array<string, string> */
    protected $casts = ['password' => 'hashed'];
}

/** A class-declared `casts()` beats the trait's, and the trait's body never
 * runs — so the trait's credential column is not cast here. */
class TraitMethodOverridden extends Model
{
    use DeclaresSecretViaMethod;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['nickname' => 'string'];
    }
}

class TraitMethodInherited extends Model
{
    use DeclaresSecretViaMethod;
}

class GrandMethodBase extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['grand_secret' => 'hashed'];
    }
}

/**
 * Composes with the parent AND shadows the parent's credential column. The
 * nearer declaration wins, so `password` is a plain string here.
 *
 * Pins the merge DIRECTION of the parent-call chain rather than merely that a
 * chain exists — a mutation removing the reversal survived every other row.
 */
class ComposingOverrideShadowingParent extends MethodBase
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), ['password' => 'string']);
    }
}

/**
 * Declares nothing, so dispatch passes straight through it.
 */
class SilentMiddle extends MethodBase {}

/**
 * TWO hops from the declaration: dispatch skips the silent middle entirely and
 * runs `MethodBase::casts()`. Pins that the ancestry walk is not capped at the
 * first parent — every other row resolves within one hop.
 */
class TwoHopInherited extends SilentMiddle {}

/**
 * Replaces `casts()` and composes from a FOREIGN class's static `casts()`. Only
 * `parent::casts()` extends the dispatch walk: a call that merely shares the
 * method NAME is not a parent call, so the parent's `password` cast stays
 * unreachable. Same method name on purpose — a detector keyed on the name alone
 * passes every other row in this table.
 */
class ReplacingOverrideWithForeignStaticCall extends MethodBase
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(ForeignCastSource::casts(), ['nickname' => 'string']);
    }
}

final class ForeignCastSource
{
    /**
     * @return array<string, string>
     */
    public static function casts(): array
    {
        return ['unrelated' => 'string'];
    }
}

/**
 * Cuts the chain: the grandparent's body is unreachable from here down.
 */
class MidReplacing extends GrandMethodBase
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['mid_plain' => 'string'];
    }
}

/**
 * Composes with `parent::casts()` — but the parent it reaches is the one that
 * CUT the chain, so the grandparent's credential column stays unreachable. The
 * row that separates "walk up on a parent call" from "walk the whole ancestry".
 */
class LeafComposingOverMidReplacing extends MidReplacing
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), ['leaf_secret' => 'hashed']);
    }
}

/**
 * A trait ADAPTATION: `insteadof` excludes the hashed declaration, so the body
 * PHP dispatches is the plain one and `password` carries no credential cast.
 *
 * The excluded trait is listed FIRST on purpose — a first-match walk over the
 * imported traits picks it and reports a cast that does not exist. Reflection
 * resolves the adaptation, which is why the method half is resolved that way and
 * the property half is not: a property has no adaptation, and a conflicting one
 * is a PHP fatal rather than an ambiguity.
 */
class TraitMethodExcludedByInsteadOf extends Model
{
    use DeclaresHashedPasswordViaTraitToBeExcluded, DeclaresPlainPasswordViaTraitToBeExcluded {
        DeclaresPlainPasswordViaTraitToBeExcluded::casts insteadof DeclaresHashedPasswordViaTraitToBeExcluded;
    }
}

/**
 * Calls `parent::casts()` and THROWS THE RESULT AWAY. None of the parent's map
 * reaches the returned value, so `password` is not cast here — walking upward on
 * the strength of the call merely appearing in the body invents a cast.
 */
class DiscardedParentCastsCall extends MethodBase
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        parent::casts();

        return ['nickname' => 'string'];
    }
}

/**
 * Captures `parent::casts()` in a VARIABLE before composing it. The result IS
 * used, so the parent's map is part of the answer — the row that keeps the
 * discarded-call fix from turning into a fail-open on a credential column.
 */
class ParentCastsCapturedInVariable extends MethodBase
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        $inherited = parent::casts();

        return array_merge($inherited, ['api_token' => 'encrypted']);
    }
}

/**
 * Two returns disagreeing about the SAME column. No single call produces both,
 * so every branch is read and the CREDENTIAL cast wins: a column some branch
 * hashes is hashed on that path, and source order is not a fact about which
 * branch runs.
 */
class ConditionalReturnsDisagreeing extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        if ($this->exists) {
            return ['password' => 'string'];
        }

        return ['password' => 'hashed'];
    }
}

/**
 * `mergeCasts()` at construct time — a real Laravel API, and an accepted false
 * NEGATIVE: no declaration exists to read. Excluded from the truth table
 * because its effective map only exists after construction. See the rule's
 * out-of-scope list for the fleet measurement behind the ruling.
 */
class MergesCastsInConstructor extends Model
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->mergeCasts(['password' => 'hashed']);
    }
}
