<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Support;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

use function array_key_exists;
use function file_get_contents;
use function is_string;
use function sprintf;

/**
 * Reads every PHPStan error identifier a rule file hands to
 * `RuleErrorBuilder::identifier()`, and reports by name anything it cannot
 * read.
 *
 * The predecessor was a regex matching `->identifier('literal')` only, so a
 * rule routing its identifiers through a private helper contributed zero
 * matches and was silently exempt from the convention check while the suite
 * stayed green — WR-0853. Resolution is therefore deliberately narrow and
 * LOUD: a literal, a `self::CONST` naming a string constant of the same class,
 * or one hop through a parameter of a same-class helper. Anything else lands in
 * `unresolved` and fails the test that calls this, because the alternative is
 * the failure mode above — an identifier nothing checks, reported as zero.
 */
final class RuleIdentifierExtractor
{
    /**
     * @return array{identifiers: list<string>, unresolved: list<string>}
     */
    public static function fromFile(string $path): array
    {
        $source = file_get_contents($path);

        if ($source === false) {
            return ['identifiers' => [], 'unresolved' => [sprintf('%s could not be read', $path)]];
        }

        $ast = (new ParserFactory)->createForHostVersion()->parse($source);

        if ($ast === null) {
            return ['identifiers' => [], 'unresolved' => [sprintf('%s could not be parsed', $path)]];
        }

        $finder = new NodeFinder;

        /** @var list<ClassMethod> $methods */
        $methods = $finder->findInstanceOf($ast, ClassMethod::class);

        $constants = self::stringConstants($finder->findInstanceOf($ast, ClassConst::class));

        $identifiers = [];
        $unresolved = [];

        foreach ($methods as $method) {
            foreach (self::identifierCallsIn($finder, $method) as $call) {
                $argument = $call->args[0] ?? null;

                if (!$argument instanceof Arg) {
                    $unresolved[] = self::describe($path, $call, 'identifier() called without a first argument');

                    continue;
                }

                $resolved = self::resolve($finder, $methods, $constants, $method, $argument->value);

                if ($resolved === []) {
                    $unresolved[] = self::describe($path, $call, 'identifier() argument is not statically readable');

                    continue;
                }

                foreach ($resolved as $value) {
                    $identifiers[] = $value;
                }
            }
        }

        return ['identifiers' => $identifiers, 'unresolved' => $unresolved];
    }

    /**
     * @param list<ClassConst> $nodes
     *
     * @return array<string, string>
     */
    private static function stringConstants(array $nodes): array
    {
        $constants = [];

        foreach ($nodes as $node) {
            foreach ($node->consts as $const) {
                if ($const->value instanceof String_) {
                    $constants[$const->name->toString()] = $const->value->value;
                }
            }
        }

        return $constants;
    }

    /**
     * @return list<MethodCall>
     */
    private static function identifierCallsIn(NodeFinder $finder, ClassMethod $method): array
    {
        return $finder->find(
            $method,
            static fn(Node $node): bool => $node instanceof MethodCall
                && $node->name instanceof Identifier
                && $node->name->toString() === 'identifier',
        );
    }

    /**
     * One hop only. A helper that forwards to another helper resolves to
     * nothing and is reported, rather than dropped — see the class docblock.
     *
     * @param list<ClassMethod>     $methods
     * @param array<string, string> $constants
     *
     * @return list<string>
     */
    private static function resolve(
        NodeFinder $finder,
        array $methods,
        array $constants,
        ClassMethod $enclosing,
        Node\Expr $expression,
        bool $followParameters = true,
    ): array {
        if ($expression instanceof String_) {
            return [$expression->value];
        }

        if ($expression instanceof ClassConstFetch && $expression->name instanceof Identifier) {
            $name = $expression->name->toString();

            return array_key_exists($name, $constants) ? [$constants[$name]] : [];
        }

        if (!$followParameters || !$expression instanceof Variable || !is_string($expression->name)) {
            return [];
        }

        $position = self::parameterPosition($enclosing, $expression->name);

        if ($position === null) {
            return [];
        }

        return self::argumentsPassedTo($finder, $methods, $constants, $enclosing->name->toString(), $position);
    }

    private static function parameterPosition(ClassMethod $method, string $variable): ?int
    {
        foreach ($method->params as $index => $param) {
            if ($param->var instanceof Variable && $param->var->name === $variable) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<ClassMethod>     $methods
     * @param array<string, string> $constants
     *
     * @return list<string>
     */
    private static function argumentsPassedTo(
        NodeFinder $finder,
        array $methods,
        array $constants,
        string $calleeName,
        int $position,
    ): array {
        $values = [];

        foreach ($methods as $caller) {
            $calls = $finder->find(
                $caller,
                static fn(Node $node): bool => ($node instanceof MethodCall || $node instanceof StaticCall)
                    && $node->name instanceof Identifier
                    && $node->name->toString() === $calleeName,
            );

            foreach ($calls as $call) {
                /** @var MethodCall|StaticCall $call */
                $argument = $call->args[$position] ?? null;

                if (!$argument instanceof Arg) {
                    continue;
                }

                foreach (self::resolve($finder, $methods, $constants, $caller, $argument->value, false) as $value) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    private static function describe(string $path, MethodCall $call, string $reason): string
    {
        return sprintf('%s:%d — %s', $path, $call->name->getStartLine(), $reason);
    }
}
