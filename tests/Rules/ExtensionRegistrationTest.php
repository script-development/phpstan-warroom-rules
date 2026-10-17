<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\NeonAdapter;
use PHPStan\Rules\Rule;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ScriptDevelopment\PhpstanWarroomRules\Support\AppendOnlyLogMessage;
use ScriptDevelopment\PhpstanWarroomRules\Support\NamespacePrefix;
use SplFileInfo;
use Throwable;

use function array_key_exists;
use function is_array;
use function is_string;

/**
 * Consumers include `extension.neon` and nothing else, so a class left out of
 * its `services:` list runs nowhere, and a rule test that constructs its rule
 * directly cannot see that.
 *
 * A class under `src/` is a service when it is instantiable and implements one
 * of the interfaces in SERVICE_TAGS; it must then be registered under that
 * interface's tag. Every other class must be listed in HELPERS. A new kind of
 * extension (a static-method or function return-type extension, say) lands in
 * neither set and fails until SERVICE_TAGS names it.
 */
final class ExtensionRegistrationTest extends TestCase
{
    private const string PACKAGE_NAMESPACE = 'ScriptDevelopment\PhpstanWarroomRules\\';

    private const string EXTENSION = __DIR__ . '/../../extension.neon';

    private const string SOURCE = __DIR__ . '/../../src';

    /** @var array<class-string, string> */
    private const array SERVICE_TAGS = [
        Rule::class => 'phpstan.rules.rule',
        Collector::class => 'phpstan.collector',
        DynamicMethodReturnTypeExtension::class => 'phpstan.broker.dynamicMethodReturnTypeExtension',
    ];

    /** @var list<class-string> */
    private const array HELPERS = [
        AppendOnlyLogMessage::class,
        NamespacePrefix::class,
    ];

    public function testEveryServiceClassIsRegisteredAndEveryRegistrationIsAServiceClass(): void
    {
        $services = array_keys($this->serviceClasses());
        $registered = array_keys($this->registeredServices());

        $missing = array_values(array_diff($services, $registered));
        $extra = array_values(array_diff($registered, $services));

        self::assertSame([], $missing, 'Not registered in extension.neon, so it runs nowhere: ' . implode(', ', $missing));
        self::assertSame([], $extra, 'Registered in extension.neon but not a service class under src/: ' . implode(', ', $extra));
    }

    public function testEveryOtherClassUnderSrcIsANamedHelper(): void
    {
        $helpers = array_values(array_diff($this->sourceClasses(), array_keys($this->serviceClasses())));
        sort($helpers);
        $named = self::HELPERS;
        sort($named);

        self::assertSame(
            $named,
            $helpers,
            'A class under src/ is neither a service (see SERVICE_TAGS) nor listed in HELPERS, or HELPERS names a class that is gone or became a service.',
        );
    }

    public function testEveryRegisteredServiceCarriesTheTagItsKindRunsUnder(): void
    {
        $registered = $this->registeredServices();
        $wrong = [];

        foreach ($this->serviceClasses() as $class => $tags) {
            if (!array_key_exists($class, $registered)) {
                continue;
            }

            $absent = array_values(array_diff($tags, $registered[$class]));

            if ($absent !== []) {
                $wrong[] = $class . ' lacks ' . implode(', ', $absent);
            }
        }

        self::assertSame([], $wrong, 'Registered without the tag PHPStan runs it under: ' . implode('; ', $wrong));
    }

    /**
     * @return list<class-string>
     */
    private function sourceClasses(): array
    {
        $classes = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SOURCE, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = mb_substr($file->getPathname(), mb_strlen(self::SOURCE) + 1, -4);
            $class = self::PACKAGE_NAMESPACE . str_replace('/', '\\', $relative);

            self::assertTrue(class_exists($class), "{$file->getPathname()} does not declare {$class}, so the scan cannot classify it.");

            $classes[] = $class;
        }

        self::assertNotEmpty($classes, 'The scan of src/ found no PHP files, so it measures nothing.');

        sort($classes);

        return $classes;
    }

    /**
     * @return array<class-string, list<string>> service class => the tags its kind runs under
     */
    private function serviceClasses(): array
    {
        $services = [];

        foreach ($this->sourceClasses() as $class) {
            $reflection = new ReflectionClass($class);

            if (!$reflection->isInstantiable()) {
                continue;
            }

            foreach (self::SERVICE_TAGS as $interface => $tag) {
                if ($reflection->implementsInterface($interface)) {
                    $services[$class][] = $tag;
                }
            }
        }

        self::assertNotEmpty($services, 'No class under src/ implements a service interface, so the scan measures nothing.');

        ksort($services);

        return $services;
    }

    /**
     * @return array<string, list<string>> registered class => its tags
     */
    private function registeredServices(): array
    {
        try {
            $config = (new NeonAdapter([]))->load(self::EXTENSION);
        } catch (Throwable $exception) {
            self::fail('extension.neon failed to parse: ' . $exception->getMessage());
        }

        $definitions = $config['services'] ?? null;

        self::assertIsArray($definitions, 'extension.neon parsed, but carries no services: section.');
        self::assertNotEmpty($definitions, 'extension.neon parsed to no services.');

        $registered = [];

        foreach ($definitions as $key => $definition) {
            $class = is_string($definition) ? $definition : ($definition['class'] ?? null);

            self::assertIsString($class, "Service entry {$key} in extension.neon names no class.");

            $tags = is_array($definition) ? ($definition['tags'] ?? []) : [];

            self::assertIsArray($tags);

            $registered[$class] = array_values(array_filter($tags, 'is_string'));
        }

        ksort($registered);

        return $registered;
    }
}
