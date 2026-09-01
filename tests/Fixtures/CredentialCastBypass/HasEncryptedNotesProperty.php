<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

/**
 * The second declaration shape inside a trait — a `$casts` PROPERTY rather than
 * a `casts()` method.
 *
 * ⚠ This shape only exists on PHP 8.5+. On 8.4 composing it into an Eloquent
 * model is a FATAL error — `Model` declares `protected $casts = []` through
 * `HasAttributes`, and 8.4 requires an inherited and a trait-imported property
 * to agree on their default ("the definition differs and is considered
 * incompatible"). Measured 2026-09-01 against both interpreters.
 *
 * It survives here because PHPStan PARSES a fixture and never composes it, so
 * the rule can be tested against a model no 8.4 consumer could construct. Do
 * not "fix" it by loading it in a test: `CastDispatchShapes.php` loads its
 * classes to compute expectations from PHP, and that is exactly why this shape
 * is absent from that table.
 */
trait HasEncryptedNotesProperty
{
    /** @var array<string, string> */
    protected $casts = [
        'trait_notes' => 'encrypted',
    ];
}
