<?php

declare(strict_types = 1);

namespace App\Actions\CredentialCastBypass;

use App\Models\CredentialCastBypass\Article;
use App\Models\CredentialCastBypass\NearMissCastModel;
use App\Models\CredentialCastBypass\OverridingVault;
use App\Models\CredentialCastBypass\User;
use Illuminate\Support\Facades\DB;

/**
 * Every method here must stay SILENT. These are the shapes a credential-flavoured
 * false positive would land on, and each one is either the remediation itself or
 * a case the rule cannot resolve.
 */
final class CleanWrites
{
    /**
     * The model path — `setAttribute()` runs the cast. This is the fix.
     */
    public function modelPropertyAssignmentAndSave(User $user): void
    {
        $user->password = 'plaintext';
        $user->save();
    }

    /**
     * `Model::update()` fills through `setAttribute()`, so casts fire.
     */
    public function modelUpdate(User $user): void
    {
        $user->update(['password' => 'plaintext']);
    }

    /**
     * `Model::create()` instantiates and saves — casts fire.
     */
    public function modelCreate(): void
    {
        User::create(['password' => 'plaintext']);
    }

    /**
     * Builder `create()` also builds and saves a model — casts fire.
     */
    public function builderCreate(): void
    {
        User::query()->create(['password' => 'plaintext']);
    }

    /**
     * Builder `updateOrCreate()` routes through the model too.
     */
    public function builderUpdateOrCreate(): void
    {
        User::query()->updateOrCreate(['email' => 'a@b.c'], ['password' => 'plaintext']);
    }

    /**
     * A builder write to columns carrying no credential cast.
     */
    public function builderWriteToNonCastColumn(): void
    {
        User::query()->update(['login_count' => 3]);
    }

    /**
     * A model with no credential casts at all.
     */
    public function builderWriteOnUncastModel(): void
    {
        Article::query()->update(['published_at' => 'now']);
    }

    /**
     * `DB::table()` carries no model in its type, and no table is mapped here.
     */
    public function unmappedRawTableWrite(): void
    {
        DB::table('users')->update(['password' => 'plaintext']);
    }

    /**
     * The child REDECLARES the abstract parent's `passphrase` cast as `string`.
     * Child wins, so this is no longer a credential column. Pins the merge
     * DIRECTION, not merely the fact that a merge happens.
     */
    public function childOverridesInheritedCredentialCast(): void
    {
        OverridingVault::query()->update(['passphrase' => 'plain']);
    }

    /**
     * Cast values that only look like credential casts (`encryptedish`).
     */
    public function nearMissCastNames(): void
    {
        NearMissCastModel::query()->update(['blob' => 'x', 'digest' => 'y']);
    }

    /**
     * A payload of unknown shape — keys are not statically known.
     */
    public function dynamicPayload(string $column, string $value): void
    {
        User::query()->update([$column => $value]);
    }
}
