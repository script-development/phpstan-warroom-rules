<?php

declare(strict_types = 1);

namespace App\Services\Placeholders;

final class NestedDeclarationComments
{
    public function docblocksOnNestedDeclarationsBelongToThem(): object
    {
        $count = 1;

        /**
         * ... existing code ...
         */
        function nestedHelper(): void {}

        return new class {
            /**
             * ... existing code ...
             */
            public function run(): void {}
        };
    }

    public function anElisionInTheBodyStillFires(): int
    {
        $count = 1;
        // ... existing code ...
        $count++;

        /**
         * ... existing code ...
         */
        function anotherNestedHelper(): void {}

        return $count;
    }
}

function declaresANestedClass(): void
{
    /**
     * ... rest of the code ...
     */
    class NestedDeclaredClass {}
}
