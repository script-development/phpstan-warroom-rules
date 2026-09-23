<?php

declare(strict_types = 1);

namespace App\Actions\CredentialCastBypass;

use App\Models\CredentialCastBypass\Dispatch\ArrayMergeParentLast;
use App\Models\CredentialCastBypass\Dispatch\ComposingOverride;
use App\Models\CredentialCastBypass\Dispatch\ComposingOverrideShadowingParent;
use App\Models\CredentialCastBypass\Dispatch\ConditionalReturnsDisagreeing;
use App\Models\CredentialCastBypass\Dispatch\DiscardedParentCastsCall;
use App\Models\CredentialCastBypass\Dispatch\EncryptingClassCastAsString;
use App\Models\CredentialCastBypass\Dispatch\EncryptingCollectionClassCast;
use App\Models\CredentialCastBypass\Dispatch\GrandMethodBase;
use App\Models\CredentialCastBypass\Dispatch\InheritedMethod;
use App\Models\CredentialCastBypass\Dispatch\InheritedProperty;
use App\Models\CredentialCastBypass\Dispatch\LeafComposingOverMidReplacing;
use App\Models\CredentialCastBypass\Dispatch\LeafMethod;
use App\Models\CredentialCastBypass\Dispatch\MergesCastsInConstructor;
use App\Models\CredentialCastBypass\Dispatch\MidReplacing;
use App\Models\CredentialCastBypass\Dispatch\ParentCastsCapturedButNotPlaced;
use App\Models\CredentialCastBypass\Dispatch\ParentCastsCapturedInVariable;
use App\Models\CredentialCastBypass\Dispatch\ParentCastsVariableMergedLast;
use App\Models\CredentialCastBypass\Dispatch\PassThroughOverride;
use App\Models\CredentialCastBypass\Dispatch\PropertyBase;
use App\Models\CredentialCastBypass\Dispatch\PropertyEncryptedThenEncryptingClassCastMethod;
use App\Models\CredentialCastBypass\Dispatch\PropertyThenClassCastMethod;
use App\Models\CredentialCastBypass\Dispatch\PropertyThenMethod;
use App\Models\CredentialCastBypass\Dispatch\RedeclaringProperty;
use App\Models\CredentialCastBypass\Dispatch\ReplacingOverride;
use App\Models\CredentialCastBypass\Dispatch\ReplacingOverrideSameColumn;
use App\Models\CredentialCastBypass\Dispatch\ReplacingOverrideWithForeignStaticCall;
use App\Models\CredentialCastBypass\Dispatch\SpreadingOverride;
use App\Models\CredentialCastBypass\Dispatch\SpreadOfLiteralAfterParent;
use App\Models\CredentialCastBypass\Dispatch\SpreadParentAfterClassCast;
use App\Models\CredentialCastBypass\Dispatch\SpreadParentAfterStringCast;
use App\Models\CredentialCastBypass\Dispatch\SpreadParentBeforeClassCast;
use App\Models\CredentialCastBypass\Dispatch\TernaryReturnDisagreeing;
use App\Models\CredentialCastBypass\Dispatch\TraitMethodAndClassProperty;
use App\Models\CredentialCastBypass\Dispatch\TraitMethodExcludedByInsteadOf;
use App\Models\CredentialCastBypass\Dispatch\TraitMethodInherited;
use App\Models\CredentialCastBypass\Dispatch\TraitMethodOverridden;
use App\Models\CredentialCastBypass\Dispatch\TwoHopInherited;
use App\Models\CredentialCastBypass\Dispatch\UnionOperatorChildFirst;
use App\Models\CredentialCastBypass\Dispatch\UnionOperatorParentFirst;

/**
 * One builder write per declaration shape in `CastDispatchShapes.php`. Each
 * payload names the columns that shape's ancestry declares as credentials
 * SOMEWHERE, so both directions are live at every site: the write is flagged
 * where PHP would really apply the cast, and silent where it would not.
 *
 * Expectations are not written here or in the test — they are computed from
 * PHP's own resolution. See
 * `ForbidCredentialCastBypassRuleTest::testTheRuleAgreesWithPhpsOwnCastResolutionForEveryDeclarationShape`.
 */
final class CastDispatchWrites
{
    public function leafMethod(): void
    {
        LeafMethod::query()->update(['password' => 'raw']);
    }

    public function inheritedMethod(): void
    {
        InheritedMethod::query()->update(['password' => 'raw']);
    }

    public function replacingOverride(): void
    {
        ReplacingOverride::query()->update(['password' => 'raw']);
    }

    public function replacingOverrideSameColumn(): void
    {
        ReplacingOverrideSameColumn::query()->update(['password' => 'raw']);
    }

    public function passThroughOverride(): void
    {
        PassThroughOverride::query()->update(['password' => 'raw']);
    }

    public function composingOverride(): void
    {
        ComposingOverride::query()->update(['password' => 'raw', 'api_token' => 'raw']);
    }

    public function spreadingOverride(): void
    {
        SpreadingOverride::query()->update(['password' => 'raw', 'api_token' => 'raw']);
    }

    public function composingOverrideShadowingParent(): void
    {
        ComposingOverrideShadowingParent::query()->update(['password' => 'raw']);
    }

    public function twoHopInherited(): void
    {
        TwoHopInherited::query()->update(['password' => 'raw']);
    }

    public function replacingOverrideWithForeignStaticCall(): void
    {
        ReplacingOverrideWithForeignStaticCall::query()->update(['password' => 'raw']);
    }

    public function traitMethodExcludedByInsteadOf(): void
    {
        TraitMethodExcludedByInsteadOf::query()->update(['password' => 'raw']);
    }

    public function discardedParentCastsCall(): void
    {
        DiscardedParentCastsCall::query()->update(['password' => 'raw']);
    }

    public function parentCastsCapturedInVariable(): void
    {
        ParentCastsCapturedInVariable::query()->update(['password' => 'raw', 'api_token' => 'raw']);
    }

    public function conditionalReturnsDisagreeing(): void
    {
        ConditionalReturnsDisagreeing::query()->update(['password' => 'raw']);
    }

    public function propertyBase(): void
    {
        PropertyBase::query()->update(['password' => 'raw']);
    }

    public function inheritedProperty(): void
    {
        InheritedProperty::query()->update(['password' => 'raw']);
    }

    public function redeclaringProperty(): void
    {
        RedeclaringProperty::query()->update(['password' => 'raw']);
    }

    public function propertyThenMethod(): void
    {
        PropertyThenMethod::query()->update(['password' => 'raw']);
    }

    public function propertyThenClassCastMethod(): void
    {
        PropertyThenClassCastMethod::query()->update(['password' => 'raw']);
    }

    public function traitMethodAndClassProperty(): void
    {
        TraitMethodAndClassProperty::query()->update(['password' => 'raw']);
    }

    public function traitMethodOverridden(): void
    {
        TraitMethodOverridden::query()->update(['trait_method_secret' => 'raw']);
    }

    public function traitMethodInherited(): void
    {
        TraitMethodInherited::query()->update(['trait_method_secret' => 'raw']);
    }

    public function grandMethodBase(): void
    {
        GrandMethodBase::query()->update(['grand_secret' => 'raw']);
    }

    public function midReplacing(): void
    {
        MidReplacing::query()->update(['grand_secret' => 'raw']);
    }

    public function leafComposingOverMidReplacing(): void
    {
        LeafComposingOverMidReplacing::query()->update([
            'grand_secret' => 'raw',
            'mid_plain' => 'raw',
            'leaf_secret' => 'raw',
        ]);
    }

    public function propertyEncryptedThenEncryptingClassCastMethod(): void
    {
        PropertyEncryptedThenEncryptingClassCastMethod::query()->update(['secret' => 'raw']);
    }

    public function encryptingCollectionClassCast(): void
    {
        EncryptingCollectionClassCast::query()->update(['secret' => 'raw']);
    }

    public function encryptingClassCastAsString(): void
    {
        EncryptingClassCastAsString::query()->update(['secret' => 'raw', 'history' => 'raw']);
    }

    public function spreadParentAfterClassCast(): void
    {
        SpreadParentAfterClassCast::query()->update(['password' => 'raw']);
    }

    public function spreadParentAfterStringCast(): void
    {
        SpreadParentAfterStringCast::query()->update(['password' => 'raw']);
    }

    public function spreadParentBeforeClassCast(): void
    {
        SpreadParentBeforeClassCast::query()->update(['password' => 'raw']);
    }

    public function arrayMergeParentLast(): void
    {
        ArrayMergeParentLast::query()->update(['password' => 'raw']);
    }

    public function unionOperatorParentFirst(): void
    {
        UnionOperatorParentFirst::query()->update(['password' => 'raw']);
    }

    public function unionOperatorChildFirst(): void
    {
        UnionOperatorChildFirst::query()->update(['password' => 'raw']);
    }

    public function spreadOfLiteralAfterParent(): void
    {
        SpreadOfLiteralAfterParent::query()->update(['password' => 'raw', 'api_token' => 'raw']);
    }

    public function parentCastsVariableMergedLast(): void
    {
        ParentCastsVariableMergedLast::query()->update(['password' => 'raw']);
    }

    public function parentCastsCapturedButNotPlaced(): void
    {
        ParentCastsCapturedButNotPlaced::query()->update(['password' => 'raw', 'api_token' => 'raw']);
    }

    public function ternaryReturnDisagreeing(): void
    {
        TernaryReturnDisagreeing::query()->update(['password' => 'raw']);
    }

    public function mergesCastsInConstructor(): void
    {
        MergesCastsInConstructor::query()->update(['password' => 'raw']);
    }
}
