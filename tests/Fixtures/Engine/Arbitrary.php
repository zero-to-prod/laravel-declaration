<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine;

use Closure;

/**
 * A non-Laravel class covering every row precondition of the forms: 0/1/2/3 parameters, variadic,
 * array-typed, Closure- and callable-typed, untyped, nullable, union, static, by-ref, @internal, trait, alias, parent.
 */
final class Arbitrary extends ArbitraryParent
{
    use Recording {
        fromTrait as aliased;
    }

    /** @var list<array{0: string, 1: array<int|string, mixed>}> */
    public static array $static = [];

    /** The last Child a chain was threaded through. */
    public ?Child $child = null;

    public function flush(): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    /** @param  mixed  $label */
    public function label($label): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function name(string $name): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function retries(?int $retries): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function ratio(float $ratio): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function enabled(bool $enabled): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function scale(string|int $scale): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    /** @param  array<mixed>  $options */
    public function options(array $options): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function handler(Closure $handler): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function compiler(callable $compiler): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function tags(string ...$tags): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    /**
     * @param  mixed  $key
     * @param  mixed  $value
     */
    public function set($key, $value = null): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    /** @param  array<mixed>  $members */
    public function group(string $name, array $members): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function listen(string $event, ?Closure $callback = null): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    /** @param  mixed  $hook */
    public function booted($hook): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    /**
     * @param  mixed  $methods
     * @param  mixed  $uri
     * @param  mixed  $action
     */
    public function route($methods, $uri, $action = null): Child
    {
        $this->record(__FUNCTION__, func_get_args());

        return $this->child = new Child;
    }

    /** @param  mixed  $concrete */
    public function when($concrete): Child
    {
        $this->record(__FUNCTION__, func_get_args());

        return new Child;
    }

    public function self(string $value): static
    {
        $this->record(__FUNCTION__, func_get_args());

        return $this;
    }

    public function scalar(string $value): int
    {
        $this->record(__FUNCTION__, func_get_args());

        return 42;
    }

    public function fluent(string $value): Dynamic
    {
        $this->record(__FUNCTION__, func_get_args());

        return new Dynamic;
    }

    public static function preset(string $view): void
    {
        self::$static[] = [__FUNCTION__, func_get_args()];
    }

    public static function reset(): void
    {
        self::$static[] = [__FUNCTION__, func_get_args()];
    }
}
