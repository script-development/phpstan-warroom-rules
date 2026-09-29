<?php

declare(strict_types = 1);

namespace App\CredentialDerivedCacheKey\Imprecision;

use App\CredentialDerivedCacheKey\Models\Vault;
use Illuminate\Contracts\Cache\Repository;
use stdClass;

/**
 * The analysis is flow- and instance-insensitive: every value a variable
 * or a property ever holds is one value. That only ever adds a report —
 * a method named `overReports…` reports although the key it hands the
 * cache is clean at runtime — and never loses one: a method named
 * `leaks…` reports exactly once, however the slot was shared or written.
 */
final class Keys
{
    public function __construct(
        private Repository $cache,
    ) {}

    public function overReportsAKeyReassignedToTheId(Vault $vault): mixed
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
