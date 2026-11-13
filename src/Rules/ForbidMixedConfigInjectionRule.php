<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use Illuminate\Container\Attributes\Config;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Param;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function is_string;
use function mb_strtolower;
use function sprintf;

/**
 * Forbids a parameter injected with the container's `#[Config]` attribute
 * (`Illuminate\Container\Attributes\Config`) that declares no type or the type
 * `mixed`. Every function, method, closure and arrow function is read,
 * constructor-promoted parameters included. The attribute is matched by its
 * resolved fully-qualified name, case-folded, so an aliased import counts and a
 * first-party attribute that happens to be called `Config` does not.
 *
 * Any declared type other than `mixed` passes: scalar, nullable, union,
 * `array`, a class. A `mixed` parameter without this attribute is never read —
 * framework contracts such as `ValidationRule::validate()` require it.
 *
 * There is no escape hatch. A config value has a real shape, and a union
 * (`array|string|null`) expresses any of them; the container passes the value
 * through `ReflectionClass::newInstanceArgs()`, which coerces a scalar into a
 * scalar type rather than failing, so `mixed` buys no safety at build time.
 *
 * Doctrine source: ADR-0016 (Config Attribute Injection) § Type Safety — the
 * declared type of a `#[Config]` parameter is its type contract; `mixed` or no
 * type leaves every reader to narrow the value by hand.
 *
 * @implements Rule<FunctionLike>
 */
final class ForbidMixedConfigInjectionRule implements Rule
{
    private const string IDENTIFIER = 'forbidMixedConfigInjection.untypedConfigParameter';

    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        foreach ($node->getParams() as $param) {
            if (!$this->carriesConfigAttribute($param)) {
                continue;
            }

            $declared = $this->untypedDeclaration($param);

            if ($declared === null) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                'Parameter $%s is injected with #[Config] but %s. Declare the config value\'s real type, a union such as array|string|null where it has several shapes (ADR-0016).',
                $this->nameOf($param),
                $declared,
            ))
                ->identifier(self::IDENTIFIER)
                ->line($param->getStartLine())
                ->build();
        }

        return $errors;
    }

    private function carriesConfigAttribute(Param $param): bool
    {
        $config = mb_strtolower(Config::class);

        foreach ($param->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (mb_strtolower($attribute->name->toString()) === $config) {
                    return true;
                }
            }
        }

        return false;
    }

    private function untypedDeclaration(Param $param): ?string
    {
        if ($param->type === null) {
            return 'declares no type';
        }

        if ($param->type instanceof Identifier && $param->type->toLowerString() === 'mixed') {
            return 'typed mixed';
        }

        return null;
    }

    private function nameOf(Param $param): string
    {
        $variable = $param->var;

        return $variable instanceof Variable && is_string($variable->name) ? $variable->name : '?';
    }
}
