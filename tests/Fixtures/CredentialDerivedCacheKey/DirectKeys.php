<?php

declare(strict_types = 1);

namespace App\CredentialDerivedCacheKey\Concatenation {
    use App\CredentialDerivedCacheKey\Models\Vault;
    use Illuminate\Contracts\Cache\Repository;

    final readonly class ReadVaultAction
    {
        public function __construct(
            private Repository $cache,
        ) {}

        public function execute(Vault $vault): mixed
        {
            return $this->cache->get('vault:' . $vault->api_key);
        }
    }
}

namespace App\CredentialDerivedCacheKey\SprintfInRemember {
    use App\CredentialDerivedCacheKey\Models\Vault;
    use Illuminate\Contracts\Cache\Repository;

    final readonly class ReadVaultAction
    {
        public function __construct(
            private Repository $cache,
        ) {}

        public function execute(Vault $vault): mixed
        {
            return $this->cache->remember(\sprintf('vault:%s', $vault->api_key), 600, static fn(): string => 'reading');
        }
    }
}

namespace App\CredentialDerivedCacheKey\FacadeInterpolation {
    use App\CredentialDerivedCacheKey\Models\Vault;
    use Illuminate\Support\Facades\Cache;

    final readonly class ReadVaultAction
    {
        public function execute(Vault $vault): mixed
        {
            return Cache::store('redis')->remember("vault:{$vault->api_key}", 600, static fn(): string => 'reading');
        }
    }
}

namespace App\CredentialDerivedCacheKey\HelperMapKey {
    use App\CredentialDerivedCacheKey\Models\Vault;

    use function sha1;

    final readonly class StoreVaultAction
    {
        public function execute(Vault $vault, string $reading): void
        {
            cache(['vault:' . sha1($vault->api_key) => $reading], 600);
        }
    }
}

namespace App\CredentialDerivedCacheKey\ManyKeys {
    use App\CredentialDerivedCacheKey\Models\Vault;
    use Illuminate\Contracts\Cache\Repository;

    final readonly class ReadVaultsAction
    {
        public function __construct(
            private Repository $cache,
        ) {}

        /**
         * @return array<string, mixed>
         */
        public function execute(Vault $vault): array
        {
            return $this->cache->many([$vault->settings]);
        }
    }
}

namespace App\CredentialDerivedCacheKey\TaggedKey {
    use App\CredentialDerivedCacheKey\Models\Ledger;
    use Illuminate\Cache\CacheManager;

    final readonly class FlushVaultAction
    {
        public function __construct(
            private CacheManager $cache,
        ) {}

        public function execute(Ledger $ledger): void
        {
            $this->cache->tags(['ledger:' . $ledger->iban])->flush();
        }
    }
}

namespace App\CredentialDerivedCacheKey\UnknownHelperCall {
    use App\CredentialDerivedCacheKey\Models\Vault;
    use Illuminate\Contracts\Cache\Repository;
    use Illuminate\Support\Str;

    final readonly class ReadVaultAction
    {
        public function __construct(
            private Repository $cache,
        ) {}

        public function execute(Vault $vault): mixed
        {
            return $this->cache->get(Str::slug($vault->api_key));
        }
    }
}

namespace App\CredentialDerivedCacheKey\VendorKeyObject {
    use App\CredentialDerivedCacheKey\Models\Vault;
    use Illuminate\Contracts\Cache\Repository;
    use Illuminate\Support\Stringable;

    final readonly class ReadVaultAction
    {
        public function __construct(
            private Repository $cache,
        ) {}

        public function execute(Vault $vault): mixed
        {
            return $this->cache->get(new Stringable($vault->api_key)->prepend('vault:')->toString());
        }
    }
}
