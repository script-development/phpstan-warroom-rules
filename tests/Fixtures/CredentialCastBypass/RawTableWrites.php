<?php

declare(strict_types = 1);

namespace App\Actions\CredentialCastBypass;

use Illuminate\Support\Facades\DB;

/**
 * `DB::table('…')` writes. These fire ONLY when the consumer has mapped the
 * table to a model via `credentialCastTableModels`; with the default empty map
 * every method here is silent.
 */
final class RawTableWrites
{
    public function mappedTableCredentialColumn(): void
    {
        DB::table('users')->update(['password' => 'plaintext']);
    }

    public function mappedTableWithIntermediateChainHops(): void
    {
        DB::table('users')->where('id', 1)->limit(1)->update(['api_token' => 'raw']);
    }

    public function mappedTableNonCastColumn(): void
    {
        DB::table('users')->update(['login_count' => 1]);
    }

    public function connectionScopedTableWrite(): void
    {
        DB::connection('mysql')->table('users')->update(['password' => 'plaintext']);
    }

    /**
     * Hoisting the builder into a variable defeats the chain walk — the
     * variable's TYPE is a bare `Query\Builder` carrying no table name, so
     * there is nothing left to resolve. Documented, tested silence.
     */
    public function hoistedTableBuilder(): void
    {
        $query = DB::table('users');

        $query->update(['password' => 'plaintext']);
    }

    public function unmappedTable(): void
    {
        DB::table('articles')->update(['password' => 'plaintext']);
    }

    public function nonLiteralTableName(string $table): void
    {
        DB::table($table)->update(['password' => 'plaintext']);
    }
}
