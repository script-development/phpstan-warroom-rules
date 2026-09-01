<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Support;

use PhpParser\Error;
use PhpParser\Node;
use PHPStan\Parser\Parser;
use PHPStan\Parser\ParserErrorsException;

use function str_ends_with;

/**
 * Wraps the real analysis parser and fails for one file, so a rule's
 * "source could not be parsed" branch can be exercised without shipping a
 * syntactically broken fixture — which would break the classmap, and with it
 * the whole suite, rather than the one branch under test.
 */
final readonly class ThrowingParser implements Parser
{
    public function __construct(
        private Parser $inner,
        private string $failingFileSuffix,
    ) {}

    /**
     * @return array<Node\Stmt>
     */
    public function parseFile(string $file): array
    {
        if (str_ends_with($file, $this->failingFileSuffix)) {
            throw new ParserErrorsException([new Error('Simulated parse failure')], $file);
        }

        return $this->inner->parseFile($file);
    }

    /**
     * @return array<Node\Stmt>
     */
    public function parseString(string $sourceCode): array
    {
        return $this->inner->parseString($sourceCode);
    }
}
