<?php

declare(strict_types = 1);

namespace App\Actions\CredentialCastBypass;

use App\Models\CredentialCastBypass\ApiKey;
use App\Models\CredentialCastBypass\StrippedCastModel;

/**
 * Two builder writes in ONE file, so a stripped-body run and a readable run can
 * be compared on the same sites (WR-1462).
 *
 * `StrippedCastModel` is the file the test routes through PHPStan's cleaning
 * parser; `ApiKey` is never stripped and must keep firing normally in the same
 * run, which is what discriminates a fix from "report every model as
 * incomplete".
 */
final class StrippedCastWrites
{
    public function writeToTheStrippedModel(): void
    {
        StrippedCastModel::query()->update(['passphrase' => 'raw']);
    }

    public function writeToAModelWhoseSourceIsNeverStripped(): void
    {
        ApiKey::query()->update(['secret' => 'raw']);
    }
}
