<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use Illuminate\Support\Facades\Log;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use Psr\Log\LoggerInterface;

use function implode;
use function in_array;
use function mb_strtolower;
use function sprintf;

/**
 * Forbids a catch of a broad exception type — `\Throwable`, `\Exception` or
 * `\Error`, alone or in a union — whose body lets the failure go nowhere. A
 * broad catch is the one that also catches the failures nobody planned for: a
 * type error, a dropped connection, a bug. When its body neither rethrows nor
 * reports, and never reads the caught exception, that failure becomes a silent
 * wrong answer and leaves no trace for anyone to investigate.
 *
 * Doctrine source: war-room §Architectural Principles — Explicit over implicit
 * (#1). Adjacent to ADR-0048 (the silent-failure contract), which governs
 * whether the USER hears about a failure; this rule governs whether the failure
 * is RECORDED at all.
 *
 * A broad catch is silent when its body, nested closures included, contains:
 *
 *   - no `throw` expression,
 *   - no reporting call — a call that RESOLVES to the global `report()` /
 *     `report_if()` / `report_unless()` / `logger()` / `info()` helper (a
 *     same-namespace function of that name, or an alias of another function,
 *     is not one; an alias of the helper is), any static call on the `Log` facade, or any
 *     call on a receiver typed as a PSR `LoggerInterface` (which covers
 *     Laravel's `Logger` and `LogManager`), and
 *   - no read of the caught variable. A body that hands the exception on — into
 *     a failure result, an error state, a rethrow — has carried the failure
 *     somewhere; one that never reads it has not, whatever else it calls.
 *
 * A catch naming only narrow types (`ModelNotFoundException $e`) is control
 * flow and never fires. The line between them is the type, not the body.
 *
 * A deliberate fail-open — telemetry, the review bus, an error sink that must
 * never throw back into the caller — is suppressed at its catch line with the
 * standard PHPStan inline ignore on identifier
 * `forbidSwallowedBroadException.swallowed`, reason stated. A comment inside the
 * catch does not exempt it: PHPStan is binary, and a reason belongs on the
 * ignore, where a reason can be required.
 *
 * Detection logic credit: the broad-type set and the "failure goes nowhere"
 * framing follow `SwallowedExceptionRule` (SL107) of `heyosseus/sloppy` (MIT).
 *
 * @implements Rule<Catch_>
 */
final class ForbidSwallowedBroadExceptionRule implements Rule
{
    /** Fully-qualified broad types, lower-cased: PHP resolves class names case-insensitively. */
    private const array BROAD_TYPES = [
        'throwable',
        'exception',
        'error',
    ];

    /** Global helpers that record a failure. Lower-case, matched folded. */
    private const array REPORTING_FUNCTIONS = [
        'report',
        'report_if',
        'report_unless',
        'logger',
        'info',
    ];

    private const string LOG_FACADE = Log::class;

    private const string PSR_LOGGER = LoggerInterface::class;

    private const string IDENTIFIER = 'forbidSwallowedBroadException.swallowed';

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    public function getNodeType(): string
    {
        return Catch_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $broad = [];

        foreach ($node->types as $type) {
            if (in_array(mb_strtolower($type->toString()), self::BROAD_TYPES, true)) {
                $broad[] = $type->toString();
            }
        }

        if ($broad === []) {
            return [];
        }

        $finder = new NodeFinder;

        if ($finder->findFirstInstanceOf($node->stmts, Throw_::class) !== null) {
            return [];
        }

        if ($finder->findFirst($node->stmts, fn(Node $inner): bool => $this->isReportingCall($inner, $scope)) !== null) {
            return [];
        }

        if ($this->readsCaughtVariable($node, $finder)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Catch of %s swallows the failure: it neither rethrows, reports nor logs it, and never reads the caught exception.',
                implode('|', $broad),
            ))
                ->identifier(self::IDENTIFIER)
                ->tip('Report it (report($e), Log::…), rethrow it wrapped in a domain exception, or catch the narrow type you expect. A deliberate fail-open (telemetry, the review bus, an error sink) takes an inline PHPStan ignore on ' . self::IDENTIFIER . ' with the reason.')
                ->build(),
        ];
    }

    private function isReportingCall(Node $node, Scope $scope): bool
    {
        if ($node instanceof FuncCall) {
            return $node->name instanceof Name
                && $this->reflectionProvider->hasFunction($node->name, $scope)
                && in_array(mb_strtolower($this->reflectionProvider->getFunction($node->name, $scope)->getName()), self::REPORTING_FUNCTIONS, true);
        }

        if ($node instanceof StaticCall) {
            return $node->class instanceof Name
                && mb_strtolower($scope->resolveName($node->class)) === mb_strtolower(self::LOG_FACADE);
        }

        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            return (new ObjectType(self::PSR_LOGGER))->isSuperTypeOf($scope->getType($node->var))->yes();
        }

        return false;
    }

    private function readsCaughtVariable(Catch_ $catch, NodeFinder $finder): bool
    {
        $name = $catch->var === null ? null : $catch->var->name;
        $writes = [];

        foreach ($finder->findInstanceOf($catch->stmts, Assign::class) as $assign) {
            $writes[] = $assign->var;
        }

        foreach ($finder->findInstanceOf($catch->stmts, Variable::class) as $variable) {
            if ($variable->name === $name && !in_array($variable, $writes, true)) {
                return true;
            }
        }

        return false;
    }
}
