<?php

declare(strict_types = 1);

namespace App\CredentialDerivedCacheKey\FlowShapes {
    use App\CredentialDerivedCacheKey\Models\Ledger;
    use App\CredentialDerivedCacheKey\Models\Vault;
    use Illuminate\Cache\RateLimiter;
    use Illuminate\Contracts\Cache\Repository;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\RateLimiter as Limiter;
    use Illuminate\Support\Stringable;

    use function hash;
    use function implode;

    /**
     * One method per propagation or sink arm. A method named `leaks…` reports
     * exactly once; a method named `keeps…` stays quiet.
     */
    final class Shapes
    {
        private static string $prefix = '';

        private string $scratch = '';

        public function __construct(
            private Repository $cache,
            private RateLimiter $limiter,
            private Keyring $keyring,
        ) {}

        public function leaksThroughAnArrayBuiltUpAndImploded(Vault $vault): mixed
        {
            $parts = ['vault'];
            $parts[] = $vault->api_key;

            return $this->cache->get(implode(':', $parts));
        }

        public function leaksThroughACompoundAssignment(Vault $vault): mixed
        {
            $key = 'vault:';
            $key .= $vault->api_key;

            return $this->cache->get($key);
        }

        public function leaksThroughDestructuring(Vault $vault): mixed
        {
            [$id, $secret, $latitude] = [$vault->id, $vault->api_key, $vault->latitude];

            return $this->cache->get($secret);
        }

        public function leaksThroughAForeachValue(Vault $vault): void
        {
            foreach ([$vault->api_key] as $index => $part) {
                $this->cache->forget($part);
            }
        }

        public function leaksThroughAForeachKey(Vault $vault): void
        {
            foreach ([$vault->api_key => 1] as $part => $unused) {
                $this->cache->forget($part);
            }
        }

        public function leaksThroughAStaticProperty(Vault $vault): mixed
        {
            self::$prefix = $vault->api_key;

            return $this->cache->get(self::$prefix);
        }

        public function leaksThroughAPropertyOfThis(Vault $vault): mixed
        {
            $this->scratch = $vault->api_key;

            return $this->cache->get($this->scratch);
        }

        public function leaksThroughAPropertyOfAnotherObject(Vault $vault, Holder $holder): mixed
        {
            $holder->value = $vault->api_key;

            return $this->cache->get($holder->value);
        }

        public function leaksThroughANullableModel(?Vault $vault): mixed
        {
            return $this->cache->get('vault:' . $vault?->api_key);
        }

        public function leaksThroughEitherModelOfAUnion(Ledger|Vault $record): mixed
        {
            return $this->cache->get('record:' . $record->getAttribute('iban'));
        }

        public function leaksThroughANamedArgument(Vault $vault): mixed
        {
            return $this->cache->get($this->keyring->join(tail: $vault->api_key, head: 'vault'));
        }

        public function leaksThroughAVariadicOverflow(Vault $vault): mixed
        {
            return $this->cache->get($this->keyring->all('vault', $vault->api_key, 'b'));
        }

        public function leaksThroughAnUnpackedArgument(Vault $vault): mixed
        {
            return $this->cache->get($this->keyring->join(...['vault', $vault->api_key]));
        }

        public function leaksThroughARateLimiterKey(Vault $vault): bool
        {
            return $this->limiter->tooManyAttempts('vault:' . $vault->api_key, 5);
        }

        public function leaksThroughTheRateLimiterFacade(Vault $vault): void
        {
            Limiter::hit('vault:' . $vault->api_key);
        }

        public function leaksThroughTheCacheFacade(Vault $vault): mixed
        {
            return Cache::get('vault:' . $vault->api_key);
        }

        public function leaksThroughAHandleSubclass(Vault $vault, TenantCache $cache): mixed
        {
            return $cache->get('vault:' . hash('sha256', $vault->api_key));
        }

        public function leaksThroughAnInterfaceCall(Vault $vault, KeyBuilder $builder): mixed
        {
            return $this->cache->get($builder->build($vault->api_key));
        }

        public function leaksThroughAHelperParameterIntoItsOwnSink(Vault $vault): void
        {
            $this->forgetKey($vault->api_key);
        }

        public function leaksThroughAPutManyMapKey(Vault $vault): void
        {
            $this->cache->putMany(['plain' => 2, 'vault:' . $vault->api_key => 1], 60);
        }

        public function leaksThroughAKeyList(Vault $vault): mixed
        {
            return $this->cache->many(['vault:' . $vault->api_key]);
        }

        public function leaksThroughAMatch(Vault $vault, bool $flag): mixed
        {
            return $this->cache->get(match ($flag) {
                true => $vault->api_key,
                false => 'none',
            });
        }

        public function leaksTwoAttributesIntoOneKey(Vault $vault, Ledger $ledger): mixed
        {
            return $this->cache->get($this->keyring->join($vault->api_key, $ledger->iban));
        }

        public function leaksThroughTheFirstArgumentOfAnUnknownFunction(Vault $vault): mixed
        {
            return $this->cache->get(mb_str_pad($vault->api_key, 64, '0'));
        }

        public function leaksThroughEitherImplementationOfAUnion(Vault $vault, FirstKey|SecondKey $key): mixed
        {
            return $this->cache->get($key->for($vault));
        }

        public function leaksThroughTheFirstOfSeveralTags(Ledger $ledger): void
        {
            $this->cache->tags('ledger:' . $ledger->iban, 'ledgers')->flush();
        }

        public function leaksThroughAVariadicOverflowPastTheLastDeclaredPosition(Vault $vault): mixed
        {
            return $this->cache->get($this->keyring->all('vault', 'b', $vault->api_key));
        }

        public function leaksThroughAnObjectBuiltFromTheCredential(Vault $vault): mixed
        {
            return $this->cache->get((string) new KeyText($vault->api_key, 'suffix'));
        }

        public function leaksThroughAFluentChainStartingAtAnAnalysedCall(Vault $vault): mixed
        {
            return $this->cache->get($this->keyring->text()->append($vault->api_key)->toString());
        }

        public function leaksThroughAnEncryptedCollection(Ledger $ledger): mixed
        {
            return $this->cache->get('ledger:' . $ledger->getAttribute('history'));
        }

        public function keepsAHashedAttribute(Ledger $ledger): mixed
        {
            return $this->cache->get('ledger:' . $ledger->getAttribute('pin'));
        }

        public function keepsAnExtraArgumentNoParameterReceives(Vault $vault): mixed
        {
            return $this->cache->get($this->keyring->join('vault', 'x', $vault->api_key));
        }

        public function keepsANamedArgumentTheHelperDrops(Vault $vault): mixed
        {
            return $this->cache->get($this->keyring->pick(ignored: $vault->api_key, used: 'vault'));
        }

        public function keepsAnUnpackedArgumentTheHelperDrops(Vault $vault): mixed
        {
            return $this->cache->get($this->keyring->pick('vault', ...[$vault->api_key]));
        }

        public function keepsAStaticHelperThatDropsTheCredential(Vault $vault): mixed
        {
            return $this->cache->get(Keyring::first('vault', $vault->api_key));
        }

        public function keepsACredentialValueOutOfTheCacheHelperMap(Vault $vault): void
        {
            cache(['vault:' . $vault->id => $vault->api_key], 60);
        }

        public function keepsACredentialDefaultOutOfAManyMap(Vault $vault): mixed
        {
            return $this->cache->many(['vault:' . $vault->id => $vault->api_key]);
        }

        public function keepsACredentialValueOutOfAPutMapKey(Vault $vault): void
        {
            $this->cache->put(['vault:' . $vault->id => $vault->api_key], 60);
        }

        public function keepsACredentialValueOutOfAListKey(Vault $vault): void
        {
            $this->cache->put([$vault->id], 60);
        }

        public function keepsACredentialInsideARememberCallback(Vault $vault): mixed
        {
            return $this->cache->remember('vault:' . $vault->id, 60, static function() use ($vault): string {
                return $vault->api_key;
            });
        }

        public function keepsAPlainAttribute(Vault $vault): mixed
        {
            return $this->cache->get('vault:' . $vault->getAttribute('latitude'));
        }

        public function keepsADynamicAttributeRead(Vault $vault, string $attribute): mixed
        {
            return $this->cache->get($vault->getAttribute($attribute) . $vault[$attribute]);
        }

        public function keepsAnUnkeyedCall(Vault $vault): void
        {
            $this->cache->flush();
            $this->cache->put(...);
            $this->limiter->clear('vault:' . $vault->id);
        }

        public function keepsANonCacheReceiver(Vault $vault, Holder $holder): mixed
        {
            return $holder->get('vault:' . $vault->api_key);
        }

        public function keepsANonKeyCacheMethod(Vault $vault): mixed
        {
            return $this->cache->setDefaultCacheTime($vault->api_key);
        }

        public function keepsANamespacedFunctionNamedCache(Vault $vault): string
        {
            return namespaced\cache($vault->api_key);
        }

        public function keepsWhatOnlyADynamicNameReaches(Vault $vault, string $method, string $class, object $object): mixed
        {
            $this->cache->{$method}('vault:' . $vault->api_key);
            $class::get('vault:' . $vault->api_key);
            $object->{$method} = $vault->api_key;
            $class::${$method} = $vault->api_key;
            self::${$method} = $vault->api_key;

            return $this->cache->get($object->{$method} . $class::${$method} . $vault->getAttribute() . $vault->{$method});
        }

        private function forgetKey(string $key): void
        {
            $this->cache->forget($key);
        }
    }

    final class Holder
    {
        public string $value = '';

        public function get(string $key): mixed
        {
            return $key;
        }
    }

    final readonly class Keyring
    {
        public function join(string $head, string $tail = ''): string
        {
            return $head . ':' . $tail;
        }

        public function all(string $head, string ...$rest): string
        {
            return $head . implode(':', $rest);
        }

        public function text(): Stringable
        {
            return new Stringable('vault:');
        }

        public function pick(string $used, string $ignored = ''): string
        {
            return $used;
        }

        public static function first(string $kept, string $dropped): string
        {
            return $kept;
        }
    }

    final readonly class FirstKey
    {
        public function for(Vault $vault): string
        {
            return 'first:' . $vault->api_key;
        }
    }

    final readonly class SecondKey
    {
        public function for(Vault $vault): string
        {
            return 'second:' . $vault->getAttribute('settings');
        }
    }

    final readonly class KeyText implements \Stringable
    {
        public function __construct(string $text, string $suffix) {}

        public function __toString(): string
        {
            return 'text';
        }
    }

    trait ForgetsKeys
    {
        private function forgetKey(string $key): void
        {
            $this->cache->forget($key);
        }
    }

    /**
     * A trait's sink is analysed once per using class; only this one leaks.
     */
    final readonly class ForgetsBySecret
    {
        use ForgetsKeys;

        public function __construct(
            private Repository $cache,
        ) {}

        public function execute(Vault $vault): void
        {
            $this->forgetKey($vault->api_key);
        }
    }

    final readonly class ForgetsById
    {
        use ForgetsKeys;

        public function __construct(
            private Repository $cache,
        ) {}

        public function execute(Vault $vault): void
        {
            $this->forgetKey((string) $vault->id);
        }
    }

    interface KeyBuilder
    {
        public function build(string $seed): string;
    }

    abstract class TenantCache implements Repository {}
}

namespace App\CredentialDerivedCacheKey\FlowShapes\namespaced {
    function cache(string $key): string
    {
        return $key;
    }
}
