<?php

declare(strict_types = 1);

namespace App\CredentialDerivedCacheKey\Imprecision;

use App\CredentialDerivedCacheKey\Models\Vault;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository;
use stdClass;

/**
 * The analysis is instance- and flow-insensitive — every value a slot ever
 * holds is one value, bar `Reassignments` below. That only ever adds a report —
 * a method named `overReports…` reports although the key it hands the
 * cache is clean at runtime — and never loses one: a method named
 * `leaks…` reports exactly once, however the slot was shared or written.
 * A method named `keeps…` stays quiet because the key is clean. A method
 * named `misses…` is a false negative the rule docblock lists, pinned so the
 * day it starts reporting is seen.
 */
final class Keys
{
    public function __construct(
        private Repository $cache,
    ) {}

    public function keepsAKeyReassignedToTheId(Vault $vault): mixed
    {
        $key = 'vault:' . $vault->api_key;
        $key = 'vault:' . $vault->id;

        return $this->cache->get($key);
    }

    public function overReportsTheSameSlotOnAnotherInstance(Vault $vault): mixed
    {
        new Label($vault->api_key);
        $plain = new Label('vault:' . $vault->id);

        return $this->cache->get($plain->text);
    }

    public function leaksThroughAPropertyRedeclaredInASubclass(Vault $vault, Child $child, Base $base): mixed
    {
        $child->text = $vault->api_key;

        return $this->cache->get($base->text);
    }

    public function leaksThroughAReferenceWrittenAfterItIsTaken(Vault $vault): mixed
    {
        $key = 'vault:';
        $alias = &$key;
        $alias = $vault->api_key;

        return $this->cache->get($key);
    }

    public function leaksThroughAReferenceTakenToTheCredential(Vault $vault): mixed
    {
        $key = 'vault:' . $vault->api_key;
        $alias = &$key;

        return $this->cache->get($alias);
    }

    public function leaksThroughAForeachByReference(Vault $vault): mixed
    {
        $parts = ['vault', ''];

        foreach ($parts as &$part) {
            $part = $vault->api_key;
        }

        return $this->cache->get($parts[1]);
    }

    public function leaksThroughAPropertyTheTypeDoesNotDeclare(Vault $vault): mixed
    {
        $bag = new stdClass;
        $bag->key = $vault->api_key;

        return $this->cache->get($bag->key);
    }

    public function keepsACleanLoopKeyBesideACredentialValue(Vault $vault): void
    {
        foreach (['public-name' => $vault->api_key] as $key => $value) {
            $this->cache->forget($key);
        }
    }

    public function keepsACleanLoopKeyOfAHoistedMap(Vault $vault): void
    {
        $map = ['public-name' => $vault->api_key];

        foreach ($map as $key => $value) {
            $this->cache->forget($key);
        }
    }

    public function leaksThroughALoopKeyOfAHoistedMap(Vault $vault): void
    {
        $map = [];
        $map[$vault->api_key] = 1;

        foreach ($map as $key => $value) {
            $this->cache->forget($key);
        }
    }

    public function leaksThroughALoopValue(Vault $vault): void
    {
        foreach (['public-name' => $vault->api_key] as $value) {
            $this->cache->forget($value);
        }
    }

    public function leaksThroughAnUnanalysedImplementationOfAUnion(Vault $vault, CleanKeys|DeferredKeys $keys): mixed
    {
        return $this->cache->get($keys->make($vault->api_key));
    }

    public function leaksThroughAnAnalysedImplementationBesideAnUnanalysedOne(Vault $vault, DeferredVaultKeys|LeakyKeys $keys): mixed
    {
        return $this->cache->get($keys->for($vault));
    }

    public function leaksThroughALiteralDynamicAttributeName(Vault $vault): mixed
    {
        return $this->cache->get('vault:' . $vault->{'api_key'});
    }

    public function leaksThroughAnAttributeNameHeldInAConstantString(Vault $vault): mixed
    {
        $field = 'api_key';

        return $this->cache->get('vault:' . $vault->{$field});
    }

    public function missesAnAttributeNamedAtRuntime(Vault $vault, string $field): mixed
    {
        return $this->cache->get('vault:' . $vault->{$field} . $vault->getAttribute($field));
    }

    public function missesACredentialHandedToANamedFunctionsSink(Vault $vault): void
    {
        forget_vault_key($this->cache, $vault->api_key);
    }

    public function missesACredentialReadInsideANamedFunction(Vault $vault): mixed
    {
        return $this->cache->get(vault_key($vault));
    }

    public function missesAMatchWrittenByReferenceInsideACall(Vault $vault): mixed
    {
        preg_match('/(.+)/', $vault->api_key, $matches);

        return $this->cache->get($matches[1]);
    }

    public function missesArrayAccessOnACacheHandle(Vault $vault, CacheRepository $store): mixed
    {
        return $store['vault:' . $vault->api_key];
    }

    public function missesACastAddedAtRuntime(Vault $vault): mixed
    {
        $vault->mergeCasts(['latitude' => 'encrypted']);

        return $this->cache->get('vault:' . $vault->latitude);
    }

    public function missesACredentialReadInsideAnInterfaceImplementation(Vault $vault, VaultKeys $keys): mixed
    {
        return $this->cache->get($keys->for($vault));
    }

    public function missesACredentialReturnedByAClosure(Vault $vault): mixed
    {
        $secret = $vault->api_key;

        return $this->cache->get((static fn(): string => $secret)());
    }

    public function missesAnAttributeReturnedByAModelMethodWithNoBody(Vault $vault): mixed
    {
        return $this->cache->get('vault:' . $vault->only('api_key')['api_key']);
    }
}

final readonly class CleanKeys
{
    public function make(string $seed): string
    {
        return 'constant';
    }
}

abstract class DeferredKeys
{
    abstract public function make(string $seed): string;
}

final readonly class LeakyKeys
{
    public function for(Vault $vault): string
    {
        return 'vault:' . $vault->api_key;
    }
}

abstract class DeferredVaultKeys
{
    abstract public function for(Vault $vault): string;
}

interface VaultKeys
{
    public function for(Vault $vault): string;
}

final readonly class SecretKeys implements VaultKeys
{
    public function for(Vault $vault): string
    {
        return 'vault:' . $vault->api_key;
    }
}

final readonly class Label
{
    public function __construct(
        public string $text,
    ) {}
}

class Base
{
    public string $text = '';
}

final class Child extends Base
{
    public string $text = '';
}

function forget_vault_key(Repository $cache, string $key): void
{
    $cache->forget($key);
}

function vault_key(Vault $vault): string
{
    return 'vault:' . $vault->api_key;
}

/**
 * A `$name = …;` statement replaces every earlier value for the reads after
 * it in its own statement list, and nothing else does.
 */
final readonly class Reassignments
{
    public function __construct(
        private Repository $cache,
    ) {}

    public function keepsAParameterReassignedToTheId(Vault $vault): bool
    {
        return $this->byId($vault->api_key, $vault);
    }

    public function keepsAMapReassignedToIdKeys(Vault $vault): bool
    {
        $map = [$vault->api_key => 1];
        $map = ['vault:' . $vault->id => 1];

        return $this->cache->putMany($map, 60);
    }

    public function leaksThroughAReadBeforeTheReassignment(Vault $vault): mixed
    {
        $key = $vault->api_key;
        $value = $this->cache->get($key);
        $key = 'vault:' . $vault->id;

        return $value;
    }

    public function leaksThroughTheRightHandSideOfTheReassignment(Vault $vault): mixed
    {
        $key = $vault->api_key;
        $key = 'vault:' . $key;

        return $this->cache->get($key);
    }

    public function leaksThroughAReassignmentOnlyOneBranchMakes(Vault $vault, bool $byId): mixed
    {
        $key = $vault->api_key;

        if ($byId) {
            $key = 'vault:' . $vault->id;
        }

        return $this->cache->get($key);
    }

    public function overReportsAKeyReassignedOnEveryBranch(Vault $vault, bool $byId): mixed
    {
        $key = $vault->api_key;

        if ($byId) {
            $key = 'vault:' . $vault->id;
        } else {
            $key = 'vault';
        }

        return $this->cache->get($key);
    }

    /**
     * @param list<int> $rounds
     */
    public function leaksThroughAnEarlierIteration(Vault $vault, array $rounds): mixed
    {
        $key = 'vault:' . $vault->id;
        $value = null;

        foreach ($rounds as $round) {
            $value = $this->cache->get($key);
            $key = $vault->api_key;
        }

        return $value;
    }

    public function leaksThroughAnAliasWrittenAfterTheReassignment(Vault $vault): mixed
    {
        $key = '';
        $alias = &$key;
        $key = 'vault:' . $vault->id;
        $alias = $vault->api_key;

        return $this->cache->get($key);
    }

    public function leaksThroughAClosureWritingByReference(Vault $vault): mixed
    {
        $key = '';
        $load = static function() use (&$key, $vault): void {
            $key = $vault->api_key;
        };
        $key = 'vault:' . $vault->id;
        $load();

        return $this->cache->get($key);
    }

    public function leaksPastAReassignmentAGotoJumpsOver(Vault $vault, bool $direct): mixed
    {
        $key = $vault->api_key;

        if ($direct) {
            $key .= '';
        } else {
            goto read;
        }
        $key = 'vault:' . $vault->id;

        read:
        return $this->cache->get($key);
    }

    public function keepsAKeyReassignedInsideTheBranchThatReadsIt(Vault $vault, bool $byId): mixed
    {
        $value = null;

        if ($byId) {
            $key = 'vault';
        } else {
            $key = $vault->api_key;
            $key = 'vault:' . $vault->id;
            $value = $this->cache->get($key);
        }

        return $value;
    }

    public function leaksThroughACompoundAssignment(Vault $vault): mixed
    {
        $key = $vault->api_key;
        $key .= ':vault';

        return $this->cache->get($key);
    }

    public function leaksThroughAForeachByReferenceValue(Vault $vault): mixed
    {
        $parts = [$vault->api_key];
        $value = null;

        foreach ($parts as &$part) {
            $value = $this->cache->get($part);
        }

        return $value;
    }

    public function overReportsASpreadThatSpreadsAgain(Vault $vault, Picker $picker): mixed
    {
        return $this->cache->get($picker->pick(...[...['vault'], $vault->api_key]));
    }

    private function byId(string $key, Vault $vault): bool
    {
        $key = 'vault:' . $vault->id;

        return $this->cache->forget($key);
    }
}

final readonly class Picker
{
    public function pick(string $used, string $ignored = ''): string
    {
        return $used;
    }
}

final class HeldMaps
{
    /** @var array<string, string> */
    private array $defaults = [];

    public function __construct(
        private readonly Repository $cache,
    ) {}

    public function overReportsAPutManyValueOfAMapHeldInAProperty(Vault $vault): bool
    {
        $this->defaults = ['public' => $vault->api_key];

        return $this->cache->putMany($this->defaults, 60);
    }

    public function overReportsAPutManyValueOfAMapNestedInAMap(Vault $vault): bool
    {
        $outer = ['inner' => ['public' => $vault->api_key]];

        return $this->cache->putMany($outer['inner'], 60);
    }
}

final readonly class DynamicProperties
{
    public function __construct(
        private Repository $cache,
    ) {}

    public function missesACredentialWrittenToADynamicProperty(Vault $vault, string $field): mixed
    {
        $box = new stdClass;
        $box->{$field} = $vault->api_key;

        return $this->cache->get('vault:' . $box->{$field});
    }
}
