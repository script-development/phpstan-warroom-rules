<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use Illuminate\Database\DatabaseManager;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function str_starts_with;

/**
 * Forbids injecting DatabaseManager in Action classes.
 * Actions should inject ConnectionInterface instead for better testability and multi-tenancy support.
 *
 * Doctrine source: ADR-0021 §Why ConnectionInterface.
 *
 * @implements Rule<Class_>
 */
final class ForbidDatabaseManagerInActionsRule implements Rule
{
    private const string FORBIDDEN_CLASS = DatabaseManager::class;

    public function getNodeType(): string
    {
        return Class_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace();

        if ($namespace === null || !str_starts_with($namespace, 'App\Actions')) {
            return [];
        }

        $constructor = $node->getMethod('__construct');

        if ($constructor === null) {
            return [];
        }

        $errors = [];

        foreach ($constructor->getParams() as $param) {
            if (!$param->type instanceof Name) {
                continue;
            }

            if ($param->type->toString() === self::FORBIDDEN_CLASS) {
                $errors[] = RuleErrorBuilder::message(
                    'Actions must inject ConnectionInterface instead of DatabaseManager.',
                )
                    ->identifier('forbidDatabaseManager.inAction')
                    ->line($param->getStartLine())
                    ->build();
            }
        }

        return $errors;
    }
}
