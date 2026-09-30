<?php

declare(strict_types = 1);

namespace App\CredentialDerivedCacheKey\SinkBinding;

use App\CredentialDerivedCacheKey\Models\Vault;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;

use function array_keys;
use function crc32;
use function implode;

/**
 * One method per argument-binding or map-reading arm. A method named
 * `leaks…` reports exactly once; a method named `keeps…` stays quiet.
 */
final readonly class Sinks
{
    public function __construct(
        private Repository $cache,
        private CacheRepository $repository,
        private Store $store,
        private RateLimiter $limiter,
    ) {}

    public function leaksThroughANamedKeyAfterTheDefault(Vault $vault): mixed
    {
        return $this->cache->get(default: null, key: 'vault:' . $vault->api_key);
    }

    public function keepsACredentialDefaultNamedBeforeTheKey(Vault $vault): mixed
    {
        return $this->cache->get(default: $vault->api_key, key: 'vault:' . $vault->id);
    }

    public function leaksThroughANamedKeyOnTheCacheHelper(Vault $vault): mixed
    {
        return cache(default: null, key: 'vault:' . $vault->api_key);
    }

    public function leaksThroughANamedKeyOnTheRateLimiter(Vault $vault): int
    {
        return $this->limiter->hit(decaySeconds: 60, key: 'vault:' . $vault->api_key);
    }

    public function leaksThroughASpreadCoveringTheKey(Vault $vault): mixed
    {
        $arguments = ['vault:' . $vault->api_key];

        return $this->cache->get(...$arguments);
    }

    public function keepsACredentialSpreadPastTheKey(Vault $vault): mixed
    {
        return $this->cache->get('vault:' . $vault->id, ...[$vault->api_key]);
    }

    public function leaksThroughAnAssociativeGetMultipleValue(Vault $vault): iterable
    {
        return $this->cache->getMultiple(['alias' => $vault->api_key]);
    }

    public function leaksThroughAnAssociativeDeleteMultipleValue(Vault $vault): bool
    {
        return $this->cache->deleteMultiple(['alias' => 'vault:' . $vault->api_key]);
    }

    public function keepsACredentialGetMultipleIgnoresAsAKey(Vault $vault): iterable
    {
        return $this->cache->getMultiple([$vault->api_key => 'vault:' . $vault->id]);
    }

    public function leaksThroughASetMultipleKey(Vault $vault): bool
    {
        return $this->cache->setMultiple(['vault:' . $vault->api_key => 1], 60);
    }

    public function leaksThroughATypedGetter(Vault $vault): string
    {
        return $this->repository->string('vault:' . $vault->api_key);
    }

    public function leaksThroughALockTakenWithoutOverlapping(Vault $vault): mixed
    {
        return $this->repository->withoutOverlapping('vault:' . $vault->api_key, static fn(): int => 1);
    }

    public function leaksThroughAFunnelName(Vault $vault): mixed
    {
        return $this->repository->funnel('vault:' . $vault->api_key);
    }

    public function leaksThroughAnIntegerKeyManyReadsAsItsValue(Vault $vault): array
    {
        return $this->repository->many([7 => $vault->api_key]);
    }

    public function leaksThroughANumericStringKeyManyReadsAsItsValue(Vault $vault): array
    {
        return $this->repository->many(['7' => $vault->api_key]);
    }

    public function leaksThroughAStoreManyValue(Vault $vault): array
    {
        return $this->store->many(['alias' => $vault->api_key]);
    }

    public function keepsAHoistedPutManyValue(Vault $vault): bool
    {
        $values = ['vault:' . $vault->id => $vault->api_key];

        return $this->cache->putMany($values, 60);
    }

    public function keepsAHoistedManyDefault(Vault $vault): array
    {
        $defaults = ['vault:' . $vault->id => $vault->api_key];

        return $this->repository->many($defaults);
    }

    public function leaksThroughAHoistedPutManyKey(Vault $vault): bool
    {
        $values = ['vault:' . $vault->api_key => 1];

        return $this->cache->putMany($values, 60);
    }

    public function leaksThroughAMapKeyAssignedLater(Vault $vault): bool
    {
        $values = [];
        $values['vault:' . $vault->api_key] = 1;

        return $this->cache->putMany($values, 60);
    }

    public function leaksThroughTheKeysOfABuiltUpArray(Vault $vault): mixed
    {
        $seen = [];
        $seen[$vault->api_key] = true;

        return $this->cache->get(implode(':', array_keys($seen)));
    }

    public function leaksThroughAHoistedMapReassignedFromAnotherVariable(Vault $vault): bool
    {
        $source = ['vault:' . $vault->api_key => 1];
        $values = $source;

        return $this->cache->putMany($values, 60);
    }

    public function leaksThroughAHoistedMapHandedToAHelper(Vault $vault): void
    {
        $this->store(['vault:' . $vault->api_key => 1]);
    }

    public function keepsAnIdKeyedMapHandedToAHelper(Vault $vault): void
    {
        $this->store(['vault:' . $vault->id => 1]);
    }

    public function leaksWhenNoArgumentBindsTheKey(Vault $vault): mixed
    {
        return $this->cache->get(default: 'vault:' . $vault->api_key);
    }

    public function leaksThroughAGetMultipleListHeldInAVariable(Vault $vault): iterable
    {
        $keys = ['vault:' . $vault->api_key];

        return $this->cache->getMultiple($keys);
    }

    public function keepsAStorePutManyValue(Vault $vault): bool
    {
        return $this->store->putMany(['vault:' . $vault->id => $vault->api_key], 60);
    }

    public function keepsAnInterpolatedManyKey(Vault $vault): array
    {
        return $this->repository->many(["vault:{$vault->id}" => $vault->api_key]);
    }

    public function leaksThroughAnInterpolatedKeyPhpStoresAsAnInteger(Vault $vault): array
    {
        return $this->repository->many(["{$vault->id}" => $vault->api_key]);
    }

    public function leaksThroughAnIntegerKeyDerivedFromTheCredential(Vault $vault): bool
    {
        return $this->cache->putMany([crc32($vault->api_key) => 1], 60);
    }

    public function keepsAManyKeyEndingInALiteral(Vault $vault): array
    {
        return $this->repository->many([$vault->id . ':vault' => $vault->api_key]);
    }

    public function leaksThroughAKeyNestedTwoLevelsDeep(Vault $vault): mixed
    {
        $seen = [];
        $seen['vault'][$vault->api_key] = true;

        return $this->cache->get(implode(':', array_keys($seen['vault'])));
    }

    public function keepsASpreadDefaultOutOfTheKey(Vault $vault): mixed
    {
        return $this->cache->get(...['vault:' . $vault->id, $vault->api_key]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function store(array $values): void
    {
        $this->cache->putMany($values, 60);
    }
}

/**
 * A local holding a map keeps three halves apart through every write and read:
 * its whole value, the keys a sink reads out of it, and its values.
 */
final readonly class HoistedMaps
{
    public function __construct(
        private Repository $cache,
    ) {}

    public function keepsACredentialHoistedGetMultipleIgnoresAsAKey(Vault $vault): iterable
    {
        $keys = [$vault->api_key => 'vault:' . $vault->id];

        return $this->cache->getMultiple($keys);
    }

    public function leaksThroughAHoistedGetMultipleValue(Vault $vault): iterable
    {
        $keys = ['alias' => 'vault:' . $vault->api_key, 'other' => 'vault:' . $vault->id];

        return $this->cache->getMultiple($keys);
    }

    public function keepsACredentialGetMultipleKeyAssignedLater(Vault $vault): iterable
    {
        $keys = [];
        $keys[$vault->api_key] = 'vault:' . $vault->id;

        return $this->cache->getMultiple($keys);
    }

    public function leaksThroughAGetMultipleValueAssignedLater(Vault $vault): iterable
    {
        $keys = [];
        $keys['alias'] = 'vault:' . $vault->api_key;

        return $this->cache->getMultiple($keys);
    }

    public function keepsACopiedPutManyValue(Vault $vault): bool
    {
        $source = ['public' => $vault->api_key];
        $copy = $source;

        return $this->cache->putMany($copy, 60);
    }

    public function keepsAPutManyValueMergedIn(Vault $vault): bool
    {
        $extra = ['public' => $vault->api_key];
        $values = ['vault:' . $vault->id => 1];
        $values += $extra;

        return $this->cache->putMany($values, 60);
    }

    public function leaksThroughAPutManyKeyMergedIn(Vault $vault): bool
    {
        $extra = ['vault:' . $vault->api_key => 1];
        $values = [];
        $values += $extra;

        return $this->cache->putMany($values, 60);
    }

    public function keepsAPutManyValueThroughAReference(Vault $vault): bool
    {
        $source = ['public' => $vault->api_key];
        $alias = &$source;

        return $this->cache->putMany($alias, 60);
    }

    public function leaksThroughAPutManyKeyThroughAReference(Vault $vault): bool
    {
        $source = ['vault:' . $vault->api_key => 1];
        $alias = &$source;

        return $this->cache->putMany($alias, 60);
    }

    public function keepsASpreadPutManyValue(Vault $vault): bool
    {
        $source = ['public' => $vault->api_key];

        return $this->cache->putMany([...$source, 'vault:' . $vault->id => 1], 60);
    }

    public function leaksThroughASpreadPutManyKey(Vault $vault): bool
    {
        $source = ['vault:' . $vault->api_key => 1];

        return $this->cache->putMany([...$source, 'vault:' . $vault->id => 1], 60);
    }

    public function keepsASpreadGetMultipleKey(Vault $vault): iterable
    {
        $source = [$vault->api_key => 'vault:' . $vault->id];

        return $this->cache->getMultiple([...$source]);
    }

    public function keepsALoopValueOfAMapsKeys(Vault $vault): void
    {
        $map = [$vault->api_key => 'vault:' . $vault->id];

        foreach ($map as $value) {
            $this->cache->forget($value);
        }
    }

    public function leaksThroughALoopValueOfAHoistedMap(Vault $vault): void
    {
        $map = ['public' => 'vault:' . $vault->api_key];

        foreach ($map as $value) {
            $this->cache->forget($value);
        }
    }

    public function keepsALoopIndexOfAList(Vault $vault): void
    {
        $list = [$vault->api_key];

        foreach ($list as $index => $value) {
            $this->cache->forget('vault:' . $index);
        }
    }

    public function keepsADestructuredValueBesideACredentialKey(Vault $vault): mixed
    {
        $lookup = [0 => 'vault:' . $vault->id, $vault->api_key => false];
        [$key] = $lookup;

        return $this->cache->get($key);
    }

    public function leaksThroughADestructuredValue(Vault $vault): mixed
    {
        $pair = ['vault', 'vault:' . $vault->api_key];
        [$prefix, $key] = $pair;

        return $this->cache->get($key);
    }

    public function keepsAPutManyValueOfAMapATernaryPicks(Vault $vault, bool $pick): bool
    {
        $source = ['public' => $vault->api_key];

        return $this->cache->putMany($pick ? [] : $source, 60);
    }

    public function leaksThroughAPutManyKeyOfAMapATernaryPicks(Vault $vault, bool $pick): bool
    {
        $source = ['vault:' . $vault->api_key => 1];

        return $this->cache->putMany($pick ? $source : [], 60);
    }

    /**
     * @param array<string, mixed>|null $override
     */
    public function keepsAPutManyValueOfAMapACoalescePicks(Vault $vault, ?array $override): bool
    {
        $source = ['public' => $vault->api_key];

        return $this->cache->putMany($override ?? $source, 60);
    }

    public function keepsAPutManyValueOfAnArrayUnion(Vault $vault): bool
    {
        $source = ['public' => $vault->api_key];

        return $this->cache->putMany($source + ['vault:' . $vault->id => 1], 60);
    }

    public function leaksThroughAPutManyKeyOfAnArrayUnion(Vault $vault): bool
    {
        $source = ['vault:' . $vault->api_key => 1];

        return $this->cache->putMany($source + ['vault:' . $vault->id => 1], 60);
    }
}
