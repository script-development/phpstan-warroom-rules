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

trait DeclaresSecretViaProperty
{
    /** @var array<string, string> */
    protected $casts = ['trait_property_secret' => 'hashed'];
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

class TraitPropertyInherited extends Model
{
    use DeclaresSecretViaProperty;
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
