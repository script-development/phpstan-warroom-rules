<?php

declare(strict_types = 1);

namespace App\Actions\Reporting\Naming;

use function strtotime as decode;

/**
 * A same-namespace helper that merely SHARES a name with a listed global
 * function. PHP resolves an unqualified call here to this declaration, so the
 * call below decodes nothing the rule cares about.
 */
function strtotime(string $value): int
{
    return (int) $value;
}

/**
 * The two halves of function-name resolution. Neither is visible to a rule that
 * compares the WRITTEN token against its list: the local helper matches that
 * list without being the global function, and the alias is the global function
 * without matching the list.
 */
final class ResolvedFunctionNames
{
    public function callsASameNamespaceHelper(string $raw): int
    {
        return strtotime($raw);
    }

    public function callsTheGlobalFunctionThroughAnAlias(string $raw): false|int
    {
        return decode($raw);
    }
}
