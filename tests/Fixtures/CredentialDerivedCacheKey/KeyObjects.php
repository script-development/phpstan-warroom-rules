<?php

declare(strict_types = 1);

namespace App\CredentialDerivedCacheKey\ModelIntoKeyFactory {
    use App\CredentialDerivedCacheKey\Models\Vault;
    use Illuminate\Cache\Repository;

    use function md5;

    final readonly class ReadVaultAction
    {
        public function __construct(
            private Repository $cache,
            private VaultCacheKeys $keys,
        ) {}

        public function execute(Vault $vault): mixed
        {
            $key = $this->keys->forVault($vault);

            return $this->cache->get($key);
        }
    }

    final readonly class VaultCacheKeys
    {
        public function forVault(Vault $vault): string
        {
            return 'vault:' . md5($vault->getAttribute('api_key'));
        }
    }
}

namespace App\CredentialDerivedCacheKey\ModelIntoKeyObject {
    use App\CredentialDerivedCacheKey\Models\Vault;
    use Illuminate\Contracts\Cache\Repository;

    use function hash;

    final readonly class ReadVaultAction
    {
        public function __construct(
            private Repository $cache,
        ) {}

        public function execute(Vault $vault): mixed
        {
            $vaultKey = new VaultKey($vault);

            return $this->cache->get($vaultKey->cacheKey());
        }
    }

    final readonly class VaultKey
    {
        private string $digest;

        public function __construct(Vault $vault)
        {
            $this->digest = hash('sha256', $vault->api_key);
        }

        public function cacheKey(): string
        {
            return 'vault:' . $this->digest;
        }
    }
}

namespace App\CredentialDerivedCacheKey\PromotedKeyObject {
    use App\CredentialDerivedCacheKey\Models\Vault;
    use Illuminate\Contracts\Cache\Repository;

    /**
     * The key object is built in another class and injected here.
     */
    final readonly class ReadVaultAction
    {
        public function __construct(
            private Repository $cache,
            private VaultKeyParts $parts,
        ) {}

        public function execute(): mixed
        {
            return $this->cache->get($this->parts->key());
        }
    }

    final readonly class VaultKeyParts
    {
        public function __construct(
            private string $secret,
        ) {}

        public function key(): string
        {
            return 'vault:' . $this->secret;
        }
    }

    final readonly class VaultKeyPartsFactory
    {
        public function forVault(Vault $vault): VaultKeyParts
        {
            return new VaultKeyParts($vault->api_key);
        }
    }
}
