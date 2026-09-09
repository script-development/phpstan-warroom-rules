<?php

declare(strict_types = 1);

/**
 * Type-resolution pin: a class that merely happens to be NAMED `Carbon` and to
 * declare a static `parse()` is not a date/time class, and a rule matching on
 * the class NAME rather than on the resolved TYPE would fire on it.
 */

namespace App\Foo {
    final class Carbon
    {
        public static function parse(string $raw): string
        {
            return $raw;
        }
    }
}

namespace App\Actions\Reporting {
    use App\Foo\Carbon;

    final class UsesLocalCarbon
    {
        public function execute(string $raw): string
        {
            return Carbon::parse($raw);
        }
    }
}
