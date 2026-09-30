<?php

declare(strict_types = 1);

/*
 * Shapes the name-matching scanner this rule replaces gets wrong, each settled
 * here by PHPStan's type resolution.
 */

namespace App\CredentialDerivedCacheKey\SameNameOnAnotherModel {
    use App\CredentialDerivedCacheKey\Models\Widget;
    use Illuminate\Contracts\Cache\Repository;

    /**
     * `api_key` is encrypted on Vault only; on Widget it is a plain string.
     */
    final readonly class ReadWidgetAction
    {
        public function __construct(
            private Repository $cache,
        ) {}

        public function execute(Widget $widget): mixed
        {
            return $this->cache->get('widget:' . $widget->api_key);
        }
    }
}

namespace App\CredentialDerivedCacheKey\DocblockTypedHandle {
    use App\CredentialDerivedCacheKey\Models\Vault;
    use Illuminate\Contracts\Cache\Repository;

    final class ReadVaultAction
    {
        /** @var Repository */
        private $cache;

        public function __construct(Repository $cache)
        {
            $this->cache = $cache;
        }

        public function execute(Vault $vault): mixed
        {
            return $this->cache->get('vault:' . $vault->api_key);
        }
    }
}

namespace App\CredentialDerivedCacheKey\ArrayAccessRead {
    use App\CredentialDerivedCacheKey\Models\Vault;
    use Illuminate\Contracts\Cache\Repository;

    final readonly class ReadVaultAction
    {
        public function __construct(
            private Repository $cache,
        ) {}

        public function execute(Vault $vault): mixed
        {
            return $this->cache->get('vault:' . $vault['api_key']);
        }
    }
}

namespace App\CredentialDerivedCacheKey\KeyBuiltInTrait {
    use App\CredentialDerivedCacheKey\Models\Vault;
    use Illuminate\Contracts\Cache\Repository;

    use function hash;

    trait BuildsVaultKeys
    {
        private function vaultKey(Vault $vault): string
        {
            return 'vault:' . hash('sha256', $vault->api_key);
        }
    }

    final readonly class ReadVaultAction
    {
        use BuildsVaultKeys;

        public function __construct(
            private Repository $cache,
        ) {}

        public function execute(Vault $vault): mixed
        {
            return $this->cache->get($this->vaultKey($vault));
        }
    }
}

namespace App\CredentialDerivedCacheKey\SharedKeyHelper {
    use App\CredentialDerivedCacheKey\Models\Vault;
    use Illuminate\Contracts\Cache\Repository;

    final readonly class VaultKeys
    {
        public static function for(int|string $part): string
        {
            return 'vault:' . $part;
        }
    }

    /**
     * Keys by the id: must stay quiet although the helper is misused below.
     */
    final readonly class ReadVaultByIdAction
    {
        public function __construct(
            private Repository $cache,
        ) {}

        public function execute(Vault $vault): mixed
        {
            return $this->cache->get(VaultKeys::for($vault->id));
        }
    }

    final readonly class ReadVaultBySecretAction
    {
        public function __construct(
            private Repository $cache,
        ) {}

        public function execute(Vault $vault): mixed
        {
            return $this->cache->get(VaultKeys::for($vault->api_key));
        }
    }
}
