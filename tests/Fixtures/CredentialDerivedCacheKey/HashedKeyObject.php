<?php

declare(strict_types = 1);

namespace App\CredentialDerivedCacheKey\HashedKeyObject;

use App\CredentialDerivedCacheKey\Models\Vault;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory;
use LogicException;

use function hash;

/**
 * The queue #24 seed: the digest is built inside a key object, one class away
 * from every cache call that uses it.
 */
final readonly class FetchWeatherAction
{
    public function __construct(
        private Repository $cache,
        private WeatherProvider $provider,
    ) {}

    public function execute(Vault $vault): mixed
    {
        $weatherLookupKey = new WeatherLookupKey($vault->api_key, $vault->latitude, $vault->longitude);

        $store = $this->cache->getStore();

        if (!$store instanceof LockProvider) {
            throw new LogicException('The cache store cannot lock.');
        }

        $lock = $store->lock($weatherLookupKey->lockKey(), 10);

        try {
            return $this->cache->get($weatherLookupKey->cacheKey()) ?? $this->fetch($vault, $weatherLookupKey);
        } finally {
            $lock->release();
        }
    }

    private function fetch(Vault $vault, WeatherLookupKey $key): mixed
    {
        $reading = $this->provider->current($vault->latitude, $vault->longitude, $vault->api_key);

        $this->cache->put($key->cacheKey(), $reading, 600);

        return $reading;
    }
}

final readonly class WeatherLookupKey
{
    private string $digest;

    public function __construct(string $apiKey, string $latitude, string $longitude)
    {
        $this->digest = hash('sha256', $apiKey . "\0" . $latitude . "\0" . $longitude);
    }

    public function cacheKey(): string
    {
        return 'vault-weather:' . $this->digest;
    }

    public function lockKey(): string
    {
        return 'vault-weather-lock:' . $this->digest;
    }
}

final readonly class WeatherProvider
{
    public function __construct(
        private Factory $http,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function current(string $latitude, string $longitude, string $apiKey): array
    {
        return $this->http->timeout(5)->get('https://weather.example/current', [
            'lat' => $latitude,
            'lon' => $longitude,
            'key' => $apiKey,
        ])->json();
    }
}
