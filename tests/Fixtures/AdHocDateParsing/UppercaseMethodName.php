<?php

declare(strict_types = 1);

namespace App\Actions\Reporting;

use Carbon\CarbonImmutable;

/**
 * PHP dispatches a static method case-insensitively, so every spelling below
 * reaches the same decode. A rule comparing the written identifier against a
 * camel-case list sees none of them.
 */
final class UppercaseMethodName
{
    public function shoutsTheMethodName(string $raw): void
    {
        CarbonImmutable::PARSE($raw);
        CarbonImmutable::CreateFromFormat('Y-m-d', $raw);
    }
}
