<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
use Illuminate\Database\Schema\ForeignKeyDefinition;
use Illuminate\Database\Schema\IndexDefinition;
use Illuminate\Support\Fluent;
use LogicException;
use ReflectionClass;
use ZeroToProd\LaravelDeclaration\Internal\ActionGuard;
use ZeroToProd\LaravelDeclaration\Internal\BlueprintMethodKind;

final readonly class BlueprintAction
{
    public function __construct(
        public string $method,
        /** @var array<mixed> */
        public array $arguments = [],
        /** @var array<string, mixed> */
        public array $modifiers = [],
    ) {}

    public function apply(Blueprint $Blueprint): mixed
    {
        $kind = BlueprintMethodKind::of($this->method);
        $target = $Blueprint->{$this->method}(...$this->arguments);

        foreach ($this->modifiers as $modifier => $arguments) {
            if (! $target instanceof Fluent
                || (! method_exists($target, $modifier) && ! isset(self::annotatedMethods($this->authority($kind, $target))[$modifier]))
            ) {
                throw new LogicException(
                    "Modifier [$modifier] is not valid on ".$this->modifierTarget($kind, $target, $Blueprint)
                    ." within [$this->method]; declare it after `constrained`/`references` so the foreign-key target exists."
                );
            }

            $next = $target->{$modifier}(...$this->spread($arguments));

            if ($next instanceof Fluent) {
                $target = $next;
            }
        }

        return $target;
    }

    /**
     * @param  Fluent<int|string, mixed>  $target
     * @return class-string
     */
    private function authority(BlueprintMethodKind $kind, Fluent $target): string
    {
        if ($kind === BlueprintMethodKind::Index && $target::class === Fluent::class) {
            return IndexDefinition::class;
        }

        return $target::class;
    }

    private function modifierTarget(BlueprintMethodKind $kind, mixed $target, Blueprint $Blueprint): string
    {
        return $target instanceof Fluent ? $this->authority($kind, $target) : $Blueprint::class;
    }

    /** @return list<ActionGuard> — all guards must pass before the action dispatches (AND semantics). */
    public function guards(): array
    {
        return BlueprintMethodKind::of($this->method)->guards($this);
    }

    public static function fromDefinition(string $method, mixed $definition): self
    {
        self::validate($method);

        $kind = BlueprintMethodKind::of($method);

        if ($definition === null || $definition === true) {
            return new self($method);
        }

        if (! is_array($definition)) {
            return new self($method, [$definition]);
        }

        if (array_is_list($definition)) {
            return new self($method, $kind === BlueprintMethodKind::Index ? [$definition] : $definition);
        }

        $parameters = BlueprintMethodKind::parameters($method);
        $arguments = [];
        $modifiers = [];

        foreach ($definition as $key => $value) {
            if (isset($parameters[$key])) {
                $arguments[$key] = $value;

                continue;
            }

            if (! in_array($kind, [BlueprintMethodKind::Column, BlueprintMethodKind::Index, BlueprintMethodKind::ForeignKey], true)) {
                throw new LogicException("Blueprint::$method() does not accept modifiers.");
            }

            if (! self::isValidModifier($kind, (string) $key)) {
                throw new LogicException("[$key] is neither a parameter of Blueprint::$method() nor a valid modifier.");
            }

            $modifiers[$key] = $value;
        }

        return new self($method, $arguments, $modifiers);
    }

    public static function validate(string $method): void
    {
        if (BlueprintMethodKind::of($method) === BlueprintMethodKind::Unknown) {
            throw new LogicException("Unknown Blueprint method [$method].");
        }

        if (in_array($method, BlueprintMethodKind::LIFECYCLE, true)) {
            throw new LogicException(
                "Blueprint method [$method] is not declarable inside a table body; use the schema `drop`, `dropIfExists`, `rename` or `create` operation."
            );
        }
    }

    /** @return array<mixed> */
    private function spread(mixed $arguments): array
    {
        if ($arguments === true || $arguments === null) {
            return [];
        }

        if (is_array($arguments)) {
            return $arguments;
        }

        return [$arguments];
    }

    private static function isValidModifier(BlueprintMethodKind $kind, string $modifier): bool
    {
        $targets = [];

        if ($kind === BlueprintMethodKind::Column) {
            $targets = [ColumnDefinition::class, ForeignIdColumnDefinition::class, ForeignKeyDefinition::class];
        }

        if ($kind === BlueprintMethodKind::Index) {
            $targets = [IndexDefinition::class];
        }

        if ($kind === BlueprintMethodKind::ForeignKey) {
            $targets = [ForeignKeyDefinition::class];
        }

        return array_any($targets, fn (string $target): bool => method_exists($target, $modifier) || isset(self::annotatedMethods($target)[$modifier])
        );
    }

    /**
     * @param  class-string  $class
     * @return array<string, true> `@method`-annotated names up the class chain (memoized)
     */
    private static function annotatedMethods(string $class): array
    {
        static $cache = [];

        if (isset($cache[$class])) {
            return $cache[$class];
        }

        $methods = [];
        $chain = [$class];

        for ($current = $class; $parent = get_parent_class($current); $current = $parent) {
            $chain[] = $parent;
        }

        foreach ($chain as $current) {
            $docComment = new ReflectionClass($current)->getDocComment() ?: '';

            preg_match_all('/@method\s+(?:static\s+)?[\w\\\\$|]+\s+(\w+)\s*\(/', $docComment, $matches);

            foreach ($matches[1] as $name) {
                $methods[$name] = true;
            }
        }

        return $cache[$class] = $methods;
    }
}
