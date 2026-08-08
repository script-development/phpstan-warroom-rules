<?php

declare(strict_types = 1);

namespace App\Support;

// Declared HERE, not in `_stubs.php`: a function is not classmap-autoloadable,
// so PHPStan only sees it through the analysed file that declares it.
if (!\function_exists('App\Support\leafText')) {
    function leafText(mixed $leaf): ?string
    {
        return \is_string($leaf) ? $leaf : null;
    }
}

final class PlainFunctionCoalesce
{
    public function name(mixed $leaf): string
    {
        // A first-party plain function has the same null contract as a method. Fires.
        return leafText($leaf) ?? '';
    }
}
