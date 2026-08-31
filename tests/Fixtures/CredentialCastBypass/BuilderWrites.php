<?php

declare(strict_types = 1);

namespace App\Actions\CredentialCastBypass;

use App\Models\CredentialCastBypass\ApiKey;
use App\Models\CredentialCastBypass\ComposedCastModel;
use App\Models\CredentialCastBypass\NestedLiteralCastModel;
use App\Models\CredentialCastBypass\SpreadCastModel;
use App\Models\CredentialCastBypass\TraitCastModel;
use App\Models\CredentialCastBypass\User;
use App\Models\CredentialCastBypass\Vault;

/**
 * Every method here is a VIOLATION: a query-builder write naming a
 * credential-cast column. The cast never fires and the raw value reaches SQL.
 */
final class BuilderWrites
{
    public function hashedColumn(): void
    {
        User::query()->where('id', 1)->update(['password' => 'plaintext']);
    }

    public function encryptedColumn(): void
    {
        User::query()->where('id', 1)->update(['api_token' => 'raw-token']);
    }

    public function encryptedVariantColumn(): void
    {
        User::query()->where('id', 1)->update(['recovery_codes' => ['a', 'b']]);
    }

    public function castsPropertyForm(): void
    {
        ApiKey::query()->update(['secret' => 'raw']);
    }

    public function inheritedFromAbstractBase(): void
    {
        Vault::query()->update(['passphrase' => 'raw']);
    }

    public function payloadHoistedIntoVariable(): void
    {
        $payload = ['password' => 'plaintext'];

        User::query()->update($payload);
    }

    public function insertRowList(): void
    {
        User::query()->insert([
            ['password' => 'one'],
            ['password' => 'two'],
        ]);
    }

    public function upsertRows(): void
    {
        User::query()->upsert([['email' => 'a@b.c', 'password' => 'raw']], ['email']);
    }

    public function updateOrInsertSecondArgument(): void
    {
        User::query()->updateOrInsert(['email' => 'a@b.c'], ['password' => 'raw']);
    }

    public function relationDerivedWrite(User $user): void
    {
        $user->apiKeys()->update(['secret' => 'raw']);
    }

    public function castDeclaredInATraitViaMethod(): void
    {
        TraitCastModel::query()->update(['trait_secret' => 'raw']);
    }

    public function castDeclaredInATraitViaProperty(): void
    {
        TraitCastModel::query()->update(['trait_notes' => 'raw']);
    }

    public function twoCredentialColumnsInOnePayload(): void
    {
        User::query()->update(['password' => 'p', 'api_token' => 't']);
    }

    public function castComposedWithArrayMerge(): void
    {
        ComposedCastModel::query()->update(['composed_secret' => 'raw']);
    }

    public function castInheritedThroughAComposingLeaf(): void
    {
        ComposedCastModel::query()->update(['passphrase' => 'raw']);
    }

    public function castComposedWithArraySpread(): void
    {
        SpreadCastModel::query()->update(['spread_secret' => 'raw']);
    }

    public function castDeclaredAlongsideANestedLiteral(): void
    {
        NestedLiteralCastModel::query()->update(['real_secret' => 'raw']);
    }
}
