<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use Illuminate\Database\Schema\Blueprint;
use ReflectionAttribute;
use ReflectionEnum;
use ReflectionMethod;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\ColumnGuards;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\CommandGuards;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\ConvenienceGuards;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\ForeignKeyGuards;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\IndexGuards;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\NoGuards;
use ZeroToProd\LaravelDeclaration\BlueprintAction;

/** @internal */
enum BlueprintMethodKind: string
{
    #[ColumnGuards]
    case Column = 'column';

    #[IndexGuards]
    case Index = 'index';

    #[ForeignKeyGuards]
    case ForeignKey = 'foreignKey';

    #[CommandGuards]
    case Command = 'command';

    #[ConvenienceGuards]
    case Unit = 'unit';

    #[ConvenienceGuards]
    case Collection = 'collection';

    #[NoGuards]
    case Unknown = 'unknown';

    public const array LIFECYCLE = [
        'build', 'toSql', 'create', 'drop', 'dropIfExists', 'rename', 'after',
        'addFluentCommands', 'addAlterCommands', 'macro', 'mixin', 'flushMacros',
    ];

    /** @var array<string, self> */
    private const array RETURN_KINDS = [
        'ColumnDefinition' => self::Column,
        'ForeignIdColumnDefinition' => self::Column,
        'IndexDefinition' => self::Index,
        'ForeignKeyDefinition' => self::ForeignKey,
        'Fluent' => self::Command,
        'void' => self::Unit,
        'Collection' => self::Collection,
        '$this' => self::Command,
    ];

    public static function of(string $method): self
    {
        static $cache = [];

        if (isset($cache[$method])) {
            return $cache[$method];
        }

        if (! method_exists(Blueprint::class, $method)
            || ! new ReflectionMethod(Blueprint::class, $method)->isPublic()) {
            return $cache[$method] = self::Unknown;
        }

        return $cache[$method] = self::classify($method);
    }

    /** Derives the guards of the given action for this kind.
     *
     * @return list<ActionGuard> — all guards must pass before the action dispatches (AND semantics).
     */
    public function guards(BlueprintAction $action): array
    {
        static $cache = [];

        return ($cache[$this->value] ??= new ReflectionEnum(self::class)
            ->getCase($this->name)
            ->getAttributes(Guards::class, ReflectionAttribute::IS_INSTANCEOF)[0]
            ->newInstance())->guards($action);
    }

    private static function classify(string $method): self
    {
        $docComment = new ReflectionMethod(Blueprint::class, $method)->getDocComment() ?: '';

        preg_match('/@return\s+([\w\\\\\[\]$]+)/', $docComment, $matches);

        $returnType = $matches[1] ?? '';
        $position = strrpos($returnType, '\\');
        $short = $position === false ? $returnType : substr($returnType, $position + 1);

        return self::RETURN_KINDS[$short] ?? self::Unknown;
    }

    /** @return array<string, int> map of `Blueprint::$method()` parameter name → position */
    public static function parameters(string $method): array
    {
        static $cache = [];

        if (isset($cache[$method])) {
            return $cache[$method];
        }

        $parameters = [];

        foreach (new ReflectionMethod(Blueprint::class, $method)->getParameters() as $position => $parameter) {
            $parameters[$parameter->getName()] = $position;
        }

        return $cache[$method] = $parameters;
    }
}
