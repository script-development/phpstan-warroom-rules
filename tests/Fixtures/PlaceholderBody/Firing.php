<?php

declare(strict_types = 1);

namespace App\Services\Placeholders;

use BadMethodCallException;
use Closure;
use LogicException;
use RuntimeException;

final class Firing
{
    public function elidedBetweenStatements(int $total): int
    {
        $total++;
        // ... existing code ...

        return $total;
    }

    public function elisionIsTheWholeBody(): void
    {
        // rest of the method unchanged
    }

    public function elidedInsideABranch(bool $flag): void
    {
        if ($flag) {
            // ...remaining validation stays the same...
            $flag = false;
        }
    }

    public function eightWordElision(): void
    {
        // ... keep the remaining guards as before ...
    }

    public function throwsNotImplemented(): void
    {
        throw new RuntimeException('Not implemented');
    }

    public function throwsATodo(): void
    {
        throw new LogicException('TODO: wire up refunds');
    }

    public function throwsANamedNotImplementedMessage(): void
    {
        throw new RuntimeException(code: 0, message: 'Method export() is not implemented yet.');
    }

    public function throwsANotImplementedClassWithoutAMessage(): void
    {
        throw new NotImplementedException;
    }

    public function closureBodyIsAPlaceholder(): Closure
    {
        return function(): void {
            // your logic goes here
        };
    }

    public function multiLineBlockElision(): void
    {
        /*
         * ... existing code ...
         * kept as it was
         */
    }

    public function twoElisionsInOneBody(int $total): int
    {
        // ... existing code ...
        $total++;
        // ... rest of the method ...

        return $total;
    }

    public function elidedAndUnwritten(): void
    {
        // ... existing code ...
        throw new RuntimeException('Not implemented');
        // trailing note
    }
}

function placeholderFunction(): string
{
    throw new BadMethodCallException('stub');
}

final class NotImplementedException extends RuntimeException {}
