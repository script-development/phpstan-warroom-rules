<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidMixedConfigInjectionRule;

use function sprintf;

/**
 * @extends RuleTestCase<ForbidMixedConfigInjectionRule>
 */
final class ForbidMixedConfigInjectionRuleTest extends RuleTestCase
{
    public function testFlagsAMixedOrUntypedConfigParameterWhereverItIsDeclared(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/MixedConfigInjection/Firing.php'],
            [
                [self::mixed('apiKey'), 11],
                [self::untyped('timeout'), 13],
                [self::mixed('name'), 20],
                [self::untyped('debug'), 20],
                [self::mixed('from'), 23],
                [self::mixed('queue'), 29],
                [self::untyped('store'), 30],
                [self::mixed('key'), 42],
                [self::mixed('secret'), 44],
                [self::mixed('cipher'), 56],
                [self::mixed('url'), 67],
                [self::mixed('timezone'), 69],
            ],
        );
    }

    public function testStaysSilentOnARealTypeAndOnMixedWithoutTheContainerAttribute(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/MixedConfigInjection/Silent.php'],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new ForbidMixedConfigInjectionRule;
    }

    private static function mixed(string $parameter): string
    {
        return sprintf('Parameter $%s is injected with #[Config] but typed mixed. Declare the config value\'s real type, a union such as array|string|null where it has several shapes (ADR-0016).', $parameter);
    }

    private static function untyped(string $parameter): string
    {
        return sprintf('Parameter $%s is injected with #[Config] but declares no type. Declare the config value\'s real type, a union such as array|string|null where it has several shapes (ADR-0016).', $parameter);
    }
}
