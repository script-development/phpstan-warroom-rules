<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Support;

use PhpParser\Node;
use PHPStan\Parser\CleaningParser;
use PHPStan\Parser\Parser;
use PHPStan\Php\PhpVersion;

use function str_ends_with;

/**
 * Routes ONE file through PHPStan's real `CleaningParser` and everything else
 * through the wrapped parser, so a rule that reads a model's PHP can be
 * exercised under the AST a consumer actually gets for a file outside the
 * current invocation's analysed set.
 *
 * Why the real upstream parser rather than a hand-rolled stripper: the body
 * emptying is an UPSTREAM fact (`CleaningVisitor` sets
 * `ClassMethod::$stmts = []`), and imitating it would let the imitation drift
 * from the thing being defended against. `BodyStrippingParserTest`-style
 * assertions in the rule's own suite therefore measure PHPStan, not a stub.
 *
 * The cleaning parser is built over a FRESH parser, never over the container's
 * cached one: `CleaningVisitor` mutates nodes in place, so cleaning a cached
 * AST would empty the bodies the analyser itself is holding for that file and
 * leak the strip into unrelated tests sharing the static container.
 */
final readonly class BodyStrippingParser implements Parser
{
    private CleaningParser $cleaning;

    public function __construct(
        private Parser $inner,
        private string $strippedFileSuffix,
        Parser $freshParser,
        PhpVersion $phpVersion,
    ) {
        $this->cleaning = new CleaningParser($freshParser, $phpVersion);
    }

    /**
     * @return array<Node\Stmt>
     */
    public function parseFile(string $file): array
    {
        if (str_ends_with($file, $this->strippedFileSuffix)) {
            return $this->cleaning->parseFile($file);
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
