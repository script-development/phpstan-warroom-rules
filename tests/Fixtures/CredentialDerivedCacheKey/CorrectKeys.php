<?php

declare(strict_types = 1);

/*
 * Both cases READ the credential to call the provider and key the cache by
 * the vault's id; neither may report.
 */

namespace App\CredentialDerivedCacheKey\KeyHelperById {
    use App\CredentialDerivedCacheKey\HashedKeyObject\WeatherProvider;
    use App\CredentialDerivedCacheKey\Models\Vault;
    use Illuminate\Contracts\Cache\Repository as Cache;

    final readonly class DeleteVaultAction
    {
        public function __construct(
            private Cache $cache,
        ) {}

        public function execute(Vault $vault): void
        {
            $vaultId = $vault->id;
            $vault->delete();
            $this->cache->forget(VaultWeatherCacheKey::for($vaultId));
        }
    }

    final readonly class FetchWeatherAction
    {
        public function __construct(
            private Cache $cache,
            private WeatherProvider $provider,
        ) {}

        /**
         * @return array<string, mixed>
         */
        public function execute(Vault $vault): array
        {
            $cacheKey = VaultWeatherCacheKey::for($vault->id);

            $cached = $this->cache->get($cacheKey);

            if (\is_array($cached)) {
                return $cached;
            }

            $reading = $this->provider->current($vault->latitude, $vault->longitude, $vault->api_key);

            $this->cache->put($cacheKey, [
                'temperature_c' => $reading['temperature_c'] ?? null,
                'condition' => $reading['condition'] ?? null,
            ], 600);

            return $reading;
        }
    }

    final readonly class VaultWeatherCacheKey
    {
        public static function for(int $vaultId): string
        {
            return \sprintf('vault-weather:%d', $vaultId);
        }
    }
}

namespace App\CredentialDerivedCacheKey\SprintfById {
    use App\CredentialDerivedCacheKey\HashedKeyObject\WeatherProvider;
    use App\CredentialDerivedCacheKey\Models\Vault;
    use Illuminate\Contracts\Cache\LockProvider;
    use Illuminate\Contracts\Cache\Repository as Cache;

    final readonly class FetchWeatherAction
    {
        public function __construct(
            private Cache $cache,
            private WeatherProvider $provider,
        ) {}

        public function execute(Vault $vault): mixed
        {
            $key = \sprintf('vault-weather:%d:%s:%s', $vault->id, $vault->latitude, $vault->longitude);

            $cached = $this->cache->get($key);

            if ($cached !== null) {
                return $cached;
            }

            $store = $this->cache->getStore();

            if (!$store instanceof LockProvider) {
                return $this->fetch($vault, $key);
            }

            return $store->lock($key . ':lock', 20)
                ->block(20, fn(): mixed => $this->cache->get($key) ?? $this->fetch($vault, $key));
        }

        private function fetch(Vault $vault, string $key): mixed
        {
            $reading = $this->provider->current($vault->latitude, $vault->longitude, $vault->api_key);

            $this->cache->put($key, $reading, 600);

            return $reading;
        }
    }
}
