<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidPlaceholderBodyRule;

/**
 * @extends RuleTestCase<ForbidPlaceholderBodyRule>
 */
final class ForbidPlaceholderBodyRuleTest extends RuleTestCase
{
    public function testFlagsElidedCodeAndNotImplementedThrows(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/PlaceholderBody/Firing.php'],
            [
                [self::elided('... existing code ...'), 17],
                [self::elided('rest of the method unchanged'), 24],
                [self::elided('...remaining validation stays the same...'), 30],
                [self::elided('... keep the remaining guards as before ...'), 37],
                [self::unwritten('Not implemented'), 42],
                [self::unwritten('TODO: wire up refunds'), 47],
                [self::unwritten('Method export() is not implemented yet.'), 52],
                [self::unwritten('App\Services\Placeholders\NotImplementedException'), 57],
                [self::elided('your logic goes here'), 63],
                [self::elided('... existing code ... kept as it was'), 69],
                [self::elided('... existing code ...'), 77],
                [self::elided('... rest of the method ...'), 79],
                [self::elided('... existing code ...'), 86],
                [self::unwritten('Not implemented'), 87],
                [self::unwritten('stub'), 94],
            ],
        );
    }

    public function testStaysSilentOnBodiesThatAreFinishedOrAbsent(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/PlaceholderBody/Silent.php'],
            [],
        );
    }

    public function testReportsANestedBodyOnceAgainstItself(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/PlaceholderBody/NestedClosure.php'],
            [
                [self::elided('... existing code ...'), 16],
                [self::elided('... rest of the code ...'), 27],
            ],
        );
    }

    public function testCommentsOnANestedDeclarationBelongToThatDeclaration(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/PlaceholderBody/NestedDeclarationComments.php'],
            [
                [self::elided('... existing code ...'), 29],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new ForbidPlaceholderBodyRule;
    }

    private static function elided(string $comment): string
    {
        return "Body has code elided by the comment \"{$comment}\". Write out the code it stands for, or delete the comment.";
    }

    private static function unwritten(string $message): string
    {
        return "Body only throws \"{$message}\": it is a placeholder, not an implementation. Implement it, or throw a specific exception whose message says why it is unsupported.";
    }
}
