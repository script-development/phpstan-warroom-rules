<?php

declare(strict_types = 1);

namespace App\CredentialDerivedCacheKey\Models;

use Illuminate\Database\Eloquent\Casts\ArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int                        $id
 * @property string                     $api_key
 * @property string                     $latitude
 * @property string                     $longitude
 * @property ArrayObject<string, mixed> $settings
 */
final class Vault extends Model
{
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'api_key' => 'encrypted',
            'settings' => AsEncryptedArrayObject::class,
        ];
    }
}

/**
 * @property string $iban
 */
final class Ledger extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'iban' => 'encrypted:string',
        'opened_at' => 'immutable_datetime',
        'history' => AsEncryptedCollection::class,
        'pin' => 'hashed',
    ];
}

/**
 * A public embed key: named like the credential, cast like any string.
 *
 * @property int    $id
 * @property string $api_key
 */
final class Widget extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'api_key' => 'string',
    ];
}
