<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidHttpExceptionInActionsRule;

/**
 * @extends RuleTestCase<ForbidHttpExceptionInActionsRule>
 */
final class ForbidHttpExceptionInActionsRuleTest extends RuleTestCase
{
    private const string MESSAGE = 'Actions must not throw an HTTP-layer exception (Symfony\Component\HttpKernel\Exception\HttpException family). '
        . 'HTTP status concerns belong to the HTTP layer — put a uniqueness rule in the FormRequest, or throw a custom domain exception the renderer maps to a status.';

    public function testFlagsDirectHttpExceptionThrow(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/HttpExceptionInAction/ThrowsHttpException.php'],
            [
                [self::MESSAGE, 14],
            ],
        );
    }

    public function testFlagsHttpExceptionSubclassThrow(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/HttpExceptionInAction/ThrowsHttpExceptionSubclass.php'],
            [
                [self::MESSAGE, 17],
            ],
        );
    }

    public function testFlagsTypedHttpExceptionValueThrow(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/HttpExceptionInAction/ThrowsTypedHttpExceptionValue.php'],
            [
                [self::MESSAGE, 19],
            ],
        );
    }

    public function testIgnoresValidationException(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/HttpExceptionInAction/CompliantThrowsValidationException.php'],
            [],
        );
    }

    public function testIgnoresCustomDomainException(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/HttpExceptionInAction/CompliantThrowsDomainException.php'],
            [],
        );
    }

    public function testIgnoresHttpExceptionOutsideActions(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/HttpExceptionInAction/HttpExceptionOutsideActions.php'],
            [],
        );
    }

    /**
     * WR-1291. A namespace that only shares the prefix's opening characters is
     * not inside it.
     */
    public function testIgnoresANamespaceThatOnlySharesThePrefixCharacters(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/HttpExceptionInAction/_stubs.php',
                __DIR__ . '/../Fixtures/NamespaceBoundary/ActionsLegacy.php',
            ],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new ForbidHttpExceptionInActionsRule;
    }
}
