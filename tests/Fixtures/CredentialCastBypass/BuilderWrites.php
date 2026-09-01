<?php

declare(strict_types = 1);

namespace App\Actions\CredentialCastBypass;

use App\Models\CredentialCastBypass\ApiKey;
use App\Models\CredentialCastBypass\Article;
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

    /**
     * `insertOrIgnore` and `insertGetId` carry the same payload shape as
     * `insert`, and were on the verb list with no site of their own — so a
     * regression that dropped either would have been invisible.
     */
    public function insertOrIgnorePayload(): void
    {
        User::query()->insertOrIgnore(['password' => 'raw']);
    }

    public function insertGetIdPayload(): void
    {
        User::query()->insertGetId(['password' => 'raw']);
    }

    /**
     * The increment family ships its EXTRA payload straight to
     * `Query\Builder::update()` — `incrementEach()` is literally
     * `update(array_merge($columns, $extra))`. The counter column is innocent;
     * the extra array is an ordinary uncast write.
     */
    public function incrementWithCredentialInExtra(): void
    {
        User::query()->increment('login_count', 1, ['password' => 'raw']);
    }

    public function decrementWithCredentialInExtra(): void
    {
        User::query()->decrement('login_count', 1, ['password' => 'raw']);
    }

    public function incrementEachWithCredentialInExtra(): void
    {
        User::query()->incrementEach(['login_count' => 1], ['password' => 'raw']);
    }

    public function decrementEachWithCredentialInExtra(): void
    {
        User::query()->decrementEach(['login_count' => 1], ['password' => 'raw']);
    }

    /**
     * A NAMED argument only sits at its parameter's position when no earlier
     * optional parameter was skipped. Here `$amount` is omitted, so the payload
     * is argument 1 rather than 2 and a position-only reading finds nothing.
     *
     * The `update(values: …)` site below is the OTHER half of the pair: with one
     * argument the named form lands at index 0 anyway, so it was already covered
     * incidentally. It is pinned to keep it that way, not as a fix.
     */
    public function incrementWithNamedExtraArgument(): void
    {
        User::query()->increment('login_count', extra: ['password' => 'raw']);
    }

    public function updateWithNamedValuesArgument(): void
    {
        User::query()->update(values: ['password' => 'raw']);
    }

    /**
     * A named argument AFTER the payload must not blind the positional read of
     * the payload itself.
     */
    public function upsertWithNamedUniqueByArgument(): void
    {
        User::query()->upsert([['email' => 'a@b.c', 'password' => 'raw']], uniqueBy: ['email']);
    }

    /**
     * `Query\Builder::updateFrom()` and `insertOrIgnoreReturning()` take a
     * payload like `update()` and reach SQL the same way. Absent from
     * `Eloquent\Builder`, but its `__call` forwards them.
     */
    public function updateFromPayload(): void
    {
        User::query()->updateFrom(['password' => 'raw']);
    }

    public function insertOrIgnoreReturningPayload(): void
    {
        User::query()->insertOrIgnoreReturning(['password' => 'raw']);
    }

    /**
     * `incrementOrCreate()` routes `$attributes` through `firstOrCreate()` — a
     * model save, so casts fire there and it is deliberately not read — then
     * hands `$extra` to `Model::increment()`, which does not.
     */
    public function incrementOrCreateExtraPayload(): void
    {
        User::query()->incrementOrCreate(['email' => 'a@b.c'], 'login_count', 1, 1, ['password' => 'raw']);
    }

    /**
     * A MODEL receiver, and still a bypass — the one family where it is.
     * `Model::__call()` re-exposes the protected increment methods, and
     * `Model::incrementOrDecrement()` casts the in-memory attribute via
     * `forceFill($extra)` while passing the SAME `$extra` uncast to the query
     * builder. The object is right; the row is plaintext.
     */
    public function modelIncrementExtraPayload(User $user): void
    {
        $user->increment('login_count', 1, ['password' => 'raw']);
    }

    public function modelDecrementEachExtraPayload(User $user): void
    {
        $user->decrementEach(['login_count' => 1], ['password' => 'raw']);
    }

    public function modelIncrementQuietlyExtraPayload(User $user): void
    {
        $user->incrementQuietly('login_count', 1, ['password' => 'raw']);
    }

    /**
     * A UNION receiver: `Builder<Article>|Builder<User>`, two different cast
     * maps behind one variable. Every branch is a real write, so every branch is
     * checked — and the castless model is deliberately FIRST, because reading
     * only the first branch answers "nothing here" while `User::password` goes
     * to SQL in plaintext on the other one.
     */
    public function unionOfBuildersForDifferentModels(bool $flag): void
    {
        $query = $flag ? Article::query() : User::query();

        $query->update(['password' => 'raw']);
    }
}
