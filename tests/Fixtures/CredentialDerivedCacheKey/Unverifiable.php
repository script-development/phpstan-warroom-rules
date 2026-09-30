<?php

declare(strict_types = 1);

namespace App\CredentialDerivedCacheKey\Unverifiable;

use App\CredentialDerivedCacheKey\Models\Opaque;
use Illuminate\Contracts\Cache\Repository;

use function mb_strlen;

/**
 * A model whose cast map cannot be read: a read of it that reaches a cache
 * key reports, because whether the attribute is encrypted is unknown; a read
 * that reaches no cache key stays quiet.
 */
final readonly class OpaqueKeys
{
    public function __construct(
        private Repository $cache,
    ) {}

    public function reportsAnUnverifiableAttributeInACacheKey(Opaque $opaque): mixed
    {
        return $this->cache->get('opaque:' . $opaque->token);
    }

    public function reportsThroughAHelperParameter(Opaque $opaque): void
    {
        $this->forget($opaque->getAttribute('token'));
    }

    public function keepsAnUnverifiableReadThatReachesNoCacheKey(Opaque $opaque): int
    {
        $this->cache->get('opaque:constant');

        return mb_strlen($opaque->token);
    }

    private function forget(string $key): void
    {
        $this->cache->forget($key);
    }
}
