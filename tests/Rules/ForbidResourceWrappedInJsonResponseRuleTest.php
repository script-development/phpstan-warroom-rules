<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidResourceWrappedInJsonResponseRule;

/**
 * @extends RuleTestCase<ForbidResourceWrappedInJsonResponseRule>
 */
final class ForbidResourceWrappedInJsonResponseRuleTest extends RuleTestCase
{
    private const string MESSAGE = 'Controllers must not wrap a JsonResource in response()->json(...) / new JsonResponse(...) — a resource is already a Responsable. '
        . 'Return the resource directly: return XxxResource::fromModel($model);';

    public function testFlagsResourceInResponseJson(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ResourceWrappedInJsonResponse/WrapsResourceInResponseJson.php'],
            [
                [self::MESSAGE, 14],
            ],
        );
    }

    public function testFlagsResourceInNewJsonResponse(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ResourceWrappedInJsonResponse/WrapsResourceInNewJsonResponse.php'],
            [
                [self::MESSAGE, 14],
            ],
        );
    }

    public function testIgnoresResourceReturnedDirectly(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ResourceWrappedInJsonResponse/CompliantReturnsResourceDirectly.php'],
            [],
        );
    }

    public function testIgnoresArrayDtoScalarAndNullPayloads(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ResourceWrappedInJsonResponse/CompliantWrapsArrayAndDtoAndNull.php'],
            [],
        );
    }

    public function testIgnoresResourceNestedInNamedEnvelope(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ResourceWrappedInJsonResponse/CompliantNamedEnvelope.php'],
            [],
        );
    }

    public function testIgnoresResourceWrappedOutsideControllers(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ResourceWrappedInJsonResponse/ResourceWrappedOutsideControllers.php'],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new ForbidResourceWrappedInJsonResponseRule;
    }
}
