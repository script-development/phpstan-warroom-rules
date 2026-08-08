<?php

declare(strict_types = 1);

namespace App\Imports;

use Application\Support\ImposterReader;

final class ForeignNamespaceHelper
{
    public function __construct(
        private ImposterReader $reader,
    ) {}

    public function name(mixed $leaf): string
    {
        // `Application\` is not `App\` — the prefix match is on a namespace
        // boundary, so this stays silent under the shipped default.
        return $this->reader->text($leaf) ?? '';
    }
}
