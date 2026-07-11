<?php

declare(strict_types = 1);

// Stub classes referenced by ActionResultDto fixtures. `EnforceActionResultDtoRule`
// inspects only the DECLARED native return-type AST node of an `execute()`
// method — it never resolves the referenced class — so these stubs exist purely
// so the fixture return types name something real and the fixtures read like
// production Actions. A Result-DTO class and a `Collection` return type both
// resolve to `PhpParser\Node\Name` nodes, which the rule passes.

namespace App\DataTransferObjects {
    final readonly class SomeResultData
    {
        public function __construct(
            public string $secret,
            public string $qrCode,
        ) {}
    }
}

namespace Illuminate\Support {
    /**
     * Minimal `Collection` stub — the fixtures do not require the real
     * illuminate/support install; only the FQCN of the return type matters, and
     * the rule never touches it beyond seeing a non-`array` `Name` node.
     *
     * @template TKey of array-key
     * @template TValue
     */
    class Collection {}
}
