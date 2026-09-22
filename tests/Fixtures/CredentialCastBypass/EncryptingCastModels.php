<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass\Encrypting;

use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Model;

/**
 * Every encrypting cast form, next to the forms that do not encrypt.
 */
class EveryEncryptingForm extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'plain_secret' => 'encrypted',
        'typed_secret' => 'encrypted:string',
        'password' => 'hashed',
        'settings' => AsEncryptedArrayObject::class,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'history' => AsEncryptedCollection::class,
            'opened_at' => 'immutable_datetime',
        ];
    }
}

/**
 * A class cast in `casts()` replaces the property's `encrypted` cast.
 */
class ClassCastOverridesEncrypted extends Model
{
    /** @var array<string, string> */
    protected $casts = ['token' => 'encrypted', 'kept' => 'encrypted'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['token' => AsStringable::class];
    }
}
