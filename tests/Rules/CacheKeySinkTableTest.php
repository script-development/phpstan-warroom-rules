<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ScriptDevelopment\PhpstanWarroomRules\Collectors\CacheKeyTaintCollector;

use function array_keys;
use function in_array;
use function is_array;
use function mb_strtolower;
use function sort;

/**
 * `CacheKeyTaintCollector` binds a sink's key argument by the name of the
 * parameter it lands in, so every name in its tables is a claim about the real
 * Laravel signature, checked here; and every public method that takes a key
 * first must be in a table, so a Laravel release adding one reds this test
 * instead of passing it silently.
 */
final class CacheKeySinkTableTest extends TestCase
{
    private const array CACHE_HANDLES = [Repository::class, Store::class, LockProvider::class];

    /** A first parameter by one of these names takes a cache key. */
    private const array KEY_PARAMETERS = ['key', 'keys', 'name', 'names', 'values'];

    /** Methods whose first parameter carries a key-like name but never becomes a stored key. */
    private const array NOT_SINKS = [
        RateLimiter::class => [
            'cleanratelimiterkey', // returns the key it is handed, cleaned; stores nothing
            'for', // names a limiter definition, not a counter
            'limiter', // looks that definition up
        ],
        Repository::class => [
            'hasmacro', // Macroable: names a macro
            'macro',
        ],
    ];

    public function testEveryCacheSinkNamesTheFirstParameterOfTheMethodItBinds(): void
    {
        $this->assertBindsFirstParameter($this->table('CACHE_SINKS'), self::CACHE_HANDLES);
    }

    public function testEveryRateLimiterSinkNamesTheFirstParameterOfTheMethodItBinds(): void
    {
        $this->assertBindsFirstParameter($this->table('RATE_LIMITER_SINKS'), [RateLimiter::class]);
    }

    public function testEveryPublicMethodTakingAKeyFirstIsASink(): void
    {
        $tables = [
            Repository::class => $this->table('CACHE_SINKS'),
            RateLimiter::class => $this->table('RATE_LIMITER_SINKS'),
        ];

        foreach ($tables as $class => $table) {
            $keyed = [];

            foreach (new ReflectionClass($class)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $first = $method->getParameters()[0] ?? null;
                $name = mb_strtolower($method->getName());

                if ($first !== null && in_array($first->getName(), self::KEY_PARAMETERS, true) && !in_array($name, self::NOT_SINKS[$class] ?? [], true)) {
                    $keyed[] = $name;
                }
            }

            $missing = [];

            foreach ($keyed as $name) {
                if (!isset($table[$name])) {
                    $missing[] = $name;
                }
            }

            self::assertNotSame([], $keyed, $class . ' yields no keyed method: the scan is broken, not the table complete.');
            self::assertSame([], $missing, $class . ' has public methods taking a key first that no sink table lists.');
        }
    }

    /**
     * @param array<string, array{string, string}> $table
     * @param list<class-string>                   $classes
     */
    private function assertBindsFirstParameter(array $table, array $classes): void
    {
        self::assertNotSame([], $table);

        $bound = [];

        foreach ($table as $method => [$parameter]) {
            foreach ($classes as $class) {
                $reflection = new ReflectionClass($class);

                if (!$reflection->hasMethod($method)) {
                    continue;
                }

                self::assertSame($parameter, $reflection->getMethod($method)->getParameters()[0]->getName(), $class . '::' . $method);
                $bound[] = $method;
            }
        }

        $unbound = [];

        foreach (array_keys($table) as $method) {
            if (!in_array($method, $bound, true)) {
                $unbound[] = $method;
            }
        }

        sort($unbound);

        self::assertSame([], $unbound, 'Sink methods no cache class declares.');
    }

    /**
     * @return array<string, array{string, string}>
     */
    private function table(string $name): array
    {
        $table = new ReflectionClassConstant(CacheKeyTaintCollector::class, $name)->getValue();

        self::assertTrue(is_array($table));

        /** @var array<string, array{string, string}> $table */
        return $table;
    }
}
