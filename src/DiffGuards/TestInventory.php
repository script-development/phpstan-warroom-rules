<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\DiffGuards;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

use function count;
use function in_array;

/**
 * The tests in one PHP file, PHPUnit and Pest, and what each one checks.
 *
 * A PHPUnit test is a public method named `test*`, or carrying `#[Test]` or `@test`,
 * named `Class::method`. A Pest test is an `it()` or `test()` call with a string
 * description, named by its description, prefixed with every enclosing `describe()`.
 * Assertions are counted by call: `assert*`, `expectException*`, Mockery's
 * `shouldReceive`/`shouldHaveReceived`/`shouldNotHaveReceived`, and every `to*` matcher on
 * an `expect()` chain. A call to a helper method or function declared in the same file
 * counts as the assertions the helper makes.
 */
final class TestInventory
{
    private const array SKIP_METHODS = ['marktestskipped', 'marktestincomplete'];

    private const array PEST_SKIP_METHODS = ['skip', 'todo'];

    private const array MOCK_EXPECTATION_METHODS = ['shouldreceive', 'shouldhavereceived', 'shouldnothavereceived'];

    /** @var array<string, int> lower-cased helper name => assertions it makes */
    private array $helpers = [];

    /**
     * @return array<string, TestBody> test name => body
     */
    public static function of(string $file, string $source): array
    {
        try {
            $statements = (new ParserFactory)->createForHostVersion()->parse($source) ?? [];
        } catch (Error $error) {
            throw CannotRun::invalid("{$file} does not parse as PHP: {$error->getMessage()}");
        }

        $traverser = new NodeTraverser(new ParentConnectingVisitor);
        $traverser->traverse($statements);

        return (new self)->collect($statements);
    }

    /**
     * @param array<Stmt> $statements
     *
     * @return array<string, TestBody>
     */
    private function collect(array $statements): array
    {
        $finder = new NodeFinder;
        $tests = [];

        foreach ($finder->findInstanceOf($statements, ClassLike::class) as $class) {
            $this->helpers = [];

            foreach ($class->getMethods() as $method) {
                if (!self::isTestMethod($method)) {
                    $this->helpers[$method->name->toLowerString()] = $this->assertionsIn($method->stmts ?? []);
                }
            }

            foreach ($class->getMethods() as $method) {
                if (self::isTestMethod($method)) {
                    $name = ($class->name?->toString() ?? 'class') . '::' . $method->name->toString();
                    $tests[$name] = $this->body($method, $method->stmts ?? [], false);
                }
            }
        }

        $this->helpers = [];

        foreach ($finder->findInstanceOf($statements, Stmt\Function_::class) as $function) {
            $this->helpers[$function->name->toLowerString()] = $this->assertionsIn($function->stmts);
        }

        foreach ($finder->findInstanceOf($statements, FuncCall::class) as $call) {
            $name = self::pestName($call);

            if ($name === null) {
                continue;
            }

            $closure = self::argument($call, 1);
            $body = match (true) {
                $closure instanceof Closure => $closure->stmts,
                $closure instanceof ArrowFunction => [new Stmt\Expression($closure->expr)],
                default => [],
            };

            $tests[$name] = $this->body($call, $body, array_intersect(self::chainedMethods($call), self::PEST_SKIP_METHODS) !== []);
        }

        return $tests;
    }

    /**
     * @param array<Stmt> $statements
     */
    private function body(Node $at, array $statements, bool $skippedByChain): TestBody
    {
        $calls = self::calls($statements);

        return new TestBody(
            $at->getStartLine(),
            $this->assertionsIn($statements),
            $skippedByChain || array_any($calls, static fn(Node $call): bool => in_array(self::callName($call), self::SKIP_METHODS, true)),
            count(array_filter($calls, self::isTrivial(...))),
            md5((new Standard)->prettyPrint($statements)),
        );
    }

    private static function isTestMethod(ClassMethod $method): bool
    {
        if ($method->stmts === null || !$method->isPublic()) {
            return false;
        }

        if (str_starts_with($method->name->toLowerString(), 'test') || str_contains((string) $method->getDocComment()?->getText(), '@test')) {
            return true;
        }

        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->getLast() === 'Test') {
                    return true;
                }
            }
        }

        return false;
    }

    private static function pestName(FuncCall $call): ?string
    {
        $function = self::functionName($call);
        $description = self::argument($call, 0);

        if (!in_array($function, ['it', 'test'], true) || !$description instanceof String_) {
            return null;
        }

        $name = ($function === 'it' ? 'it ' : '') . $description->value;

        for ($node = $call->getAttribute('parent'); $node instanceof Node; $node = $node->getAttribute('parent')) {
            $described = $node instanceof FuncCall && self::functionName($node) === 'describe' ? self::argument($node, 0) : null;

            if ($described instanceof String_) {
                $name = $described->value . ' > ' . $name;
            }
        }

        return $name;
    }

    /**
     * The lower-cased names of the methods chained onto a call, `it(...)->skip()->with(...)`.
     *
     * @return list<string>
     */
    private static function chainedMethods(Expr $call): array
    {
        $names = [];

        for ($node = $call->getAttribute('parent'); $node instanceof MethodCall; $node = $node->getAttribute('parent')) {
            $names[] = self::callName($node);
        }

        return $names;
    }

    /**
     * @param array<Stmt> $statements
     */
    private function assertionsIn(array $statements): int
    {
        $count = 0;

        foreach (self::calls($statements) as $call) {
            $name = self::callName($call);

            if (self::isLocalCall($call) && isset($this->helpers[$name])) {
                $count += $this->helpers[$name];

                continue;
            }

            if (str_starts_with($name, 'assert') || str_starts_with($name, 'expectexception') || in_array($name, self::MOCK_EXPECTATION_METHODS, true) || self::expectSubject($call) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * An assertion that cannot fail: `assertTrue(true)`, `assertSame($a, $a)`,
     * `expect(true)->toBeTrue()`, `addToAssertionCount()`.
     */
    private static function isTrivial(Node $call): bool
    {
        $name = self::callName($call);
        $first = self::argument($call, 0);
        $subject = self::expectSubject($call);

        if ($subject !== null) {
            return ($subject instanceof ConstFetch || $subject instanceof Scalar) && match ($name) {
                'tobetrue' => self::isConstant($subject, 'true'),
                'tobefalse' => self::isConstant($subject, 'false'),
                'tobenull' => self::isConstant($subject, 'null'),
                'tobe', 'toequal' => self::samePrinted($subject, $first),
                default => false,
            };
        }

        return match ($name) {
            'addtoassertioncount' => true,
            'asserttrue' => self::isConstant($first, 'true'),
            'assertfalse' => self::isConstant($first, 'false'),
            'assertnull' => self::isConstant($first, 'null'),
            'assertsame', 'assertequals' => self::samePrinted($first, self::argument($call, 1)),
            default => false,
        };
    }

    /**
     * What an `expect()` chain asserts about, when the call is a `to*` matcher on one.
     */
    private static function expectSubject(Node $call): ?Expr
    {
        if (!$call instanceof MethodCall || preg_match('/^to[A-Z]/', $call->name instanceof Identifier ? $call->name->toString() : '') !== 1) {
            return null;
        }

        $root = $call->var;

        while ($root instanceof MethodCall) {
            $root = $root->var;
        }

        return $root instanceof FuncCall && self::functionName($root) === 'expect' ? self::argument($root, 0) : null;
    }

    private static function isLocalCall(Node $call): bool
    {
        return $call instanceof FuncCall
            || $call instanceof MethodCall && $call->var instanceof Variable && $call->var->name === 'this'
            || $call instanceof StaticCall && $call->class instanceof Name && in_array($call->class->toLowerString(), ['self', 'static'], true);
    }

    /**
     * @param array<Node> $statements
     *
     * @return list<Node>
     */
    private static function calls(array $statements): array
    {
        return array_values((new NodeFinder)->find($statements, static fn(Node $node): bool => $node instanceof MethodCall || $node instanceof StaticCall || $node instanceof FuncCall));
    }

    private static function callName(Node $call): string
    {
        $name = match (true) {
            $call instanceof MethodCall, $call instanceof StaticCall => $call->name,
            $call instanceof FuncCall => $call->name,
            default => null,
        };

        return match (true) {
            $name instanceof Identifier => $name->toLowerString(),
            $name instanceof Name => mb_strtolower($name->getLast()),
            default => '',
        };
    }

    private static function functionName(FuncCall $call): string
    {
        return $call->name instanceof Name ? mb_strtolower($call->name->getLast()) : '';
    }

    private static function argument(Node $call, int $position): ?Expr
    {
        $arguments = $call instanceof MethodCall || $call instanceof StaticCall || $call instanceof FuncCall ? $call->args : [];
        $argument = $arguments[$position] ?? null;

        return $argument instanceof Arg ? $argument->value : null;
    }

    private static function isConstant(?Expr $expression, string $value): bool
    {
        return $expression instanceof ConstFetch && $expression->name->toLowerString() === $value;
    }

    private static function samePrinted(?Expr $left, ?Expr $right): bool
    {
        return $left !== null && $right !== null && (new Standard)->prettyPrintExpr($left) === (new Standard)->prettyPrintExpr($right);
    }
}
