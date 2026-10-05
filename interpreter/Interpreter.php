<?php

declare(strict_types=1);

namespace ZeroToProd\Manifest;

use Closure;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * Reads a manifest the way PHP reads a call site: keys are method names, values are arguments, nesting is
 * composition. The only metadata is reflection on the receiver; the only seam is ρ. Nothing is validated and
 * nothing is caught — PHP's own errors say what went wrong. Specification: docs/manifest-interpreter.md.
 */
final class Interpreter
{
    /** @var Closure(mixed, ?ReflectionParameter): mixed */
    private readonly Closure $resolve;

    /** @param  null|Closure(mixed, ?ReflectionParameter): mixed  $resolve  ρ — identity when null */
    public function __construct(?Closure $resolve = null)
    {
        $this->resolve = $resolve ?? static fn (mixed $value): mixed => $value;
    }

    /**
     * `$t->a(); $t->b();` — a body on a receiver, in manifest order. An object dispatches with `->`, a class-string
     * with `::`, `null` is a body with no receiver (only class-name keys are meaningful).
     *
     * @param  array<array-key, mixed>  $body
     */
    public function body(object|string|null $receiver, array $body): void
    {
        foreach ($body as $key => $value) {
            $key = (string) $key;

            if (str_contains($key, '\\')) {                                                   // a fully qualified class name: a static receiver
                $this->body($key, $value);                                                    // @phpstan-ignore argument.type (a non-array value is PHP's TypeError)

                continue;
            }

            foreach ($this->calls($this->parameters($receiver, $key), $value) as [$arguments, $rest]) {
                $this->chain(
                    is_string($receiver) ? $receiver::{$key}(...$arguments) : $receiver->{$key}(...$arguments),
                    $rest,
                );
            }
        }
    }

    /**
     * λ — a body as a PHP closure. Public so a host's ρ can produce one for a parameter PHP leaves untyped.
     *
     * @param  array<array-key, mixed>  $body
     */
    public function closure(array $body): Closure
    {
        return function (object $receiver) use ($body): void {
            $this->body($receiver, $body);
        };
    }

    /**
     * `$r->a()->b()` — the rest of a row is a fluent expression: each key is called on the previous key's return.
     *
     * @param  array<string, mixed>  $rest
     */
    private function chain(mixed $receiver, array $rest): void
    {
        foreach ($rest as $key => $value) {
            foreach ($this->calls($this->parameters($receiver, $key), $value) as [$arguments, $deeper]) {
                $next = $receiver->{$key}(...$arguments);

                $this->chain($next, $deeper);

                $receiver = $next;
            }
        }
    }

    /**
     * Σ — the receiver's signature for the key, or null for a method PHP cannot reflect (a `__call` surface),
     * which PHP itself receives as `(mixed ...$arguments)`.
     *
     * @return list<ReflectionParameter>|null
     */
    private function parameters(mixed $receiver, string $method): ?array
    {
        return (is_object($receiver) || is_string($receiver)) && method_exists($receiver, $method)
            ? new ReflectionMethod($receiver, $method)->getParameters()
            : null;
    }

    /**
     * The forms — one value, the calls it denotes. First match wins.
     *
     * @param  list<ReflectionParameter>|null  $parameters
     * @return list<array{0: array<array-key, mixed>, 1: array<string, mixed>}>
     */
    private function calls(?array $parameters, mixed $value): array
    {
        $p0 = $parameters[0] ?? null;
        $p1 = $parameters[1] ?? null;
        $names = array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $parameters ?? []);

        if ($value === null || $value === true) {                                              // 1  m()
            return [[[], []]];
        }

        if (! is_array($value)) {                                                               // 2  m($x)
            return [[[$this->argument($value, $p0)], []]];
        }

        if (array_is_list($value)) {
            if ($value !== [] && array_all($value, static fn (mixed $item): bool => self::isRow($item, $names))) {
                return array_map(fn (mixed $item): array => $this->row((array) $item, $parameters ?? []), $value); // 3  one call per row
            }

            if ($parameters === null || ($p0?->isVariadic() ?? false)) {                         // 4  m(...$xs)
                return [[array_map(fn (mixed $item): mixed => $this->argument($item, $p0), $value), []]];
            }

            if (self::typed($p0, 'array', 'iterable')) {                                        // 5  m([...]) — the list is the argument
                return [[[$this->argument($value, $p0)], []]];
            }

            $calls = [];                                                                        // 6  one call per item

            foreach ($value as $item) {
                $calls = [...$calls, ...(self::isMap($item) ? $this->calls($parameters, $item) : [[[$this->argument($item, $p0)], []]])];
            }

            return $calls;
        }

        if (self::isRow($value, $names)) {                                                      // 8  m(a: …, b: …)->rest
            return [$this->row($value, $parameters ?? [])];
        }

        if (count($names) >= 2 && ! self::typed($p0, 'array', 'iterable')) {                    // 7  foreach ($v as $k => $e) m($k, $e)
            $calls = [];

            foreach ($value as $key => $entry) {
                if (is_array($entry) && array_is_list($entry) && ! self::typed($p1, 'array', 'iterable')) {
                    foreach ($entry as $item) {
                        $calls[] = [[$key, $this->argument($item, $p1)], []];
                    }

                    continue;
                }

                $calls[] = [[$key, $this->argument($entry, $p1)], []];
            }

            return $calls;
        }

        return [[[$this->argument($value, $p0)], []]];                                          // 9  m([k => v]) — the map is the argument
    }

    /**
     * A row: the keys that are parameter names become named arguments; the others ride the return value.
     *
     * @param  array<array-key, mixed>  $item
     * @param  list<ReflectionParameter>  $parameters
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function row(array $item, array $parameters): array
    {
        $named = [];
        $rest = [];

        foreach ($item as $key => $value) {
            $key = (string) $key;
            $parameter = array_find($parameters, static fn (ReflectionParameter $parameter): bool => $parameter->getName() === $key);

            if ($parameter === null) {
                $rest[$key] = $value;
            } else {
                $named[$key] = $this->argument($value, $parameter);
            }
        }

        return [$named, $rest];
    }

    /** arg(x, p) — a map under a parameter that accepts a Closure is λ; everything else is ρ's. */
    private function argument(mixed $value, ?ReflectionParameter $parameter): mixed
    {
        return self::isMap($value) && self::typed($parameter, Closure::class, 'callable')
            ? $this->closure($value)
            : ($this->resolve)($value, $parameter);
    }

    /** @param  list<string>  $names */
    private static function isRow(mixed $value, array $names): bool
    {
        return self::isMap($value) && in_array((string) array_key_first($value), $names, true);
    }

    /** @phpstan-assert-if-true array<array-key, mixed> $value */
    private static function isMap(mixed $value): bool
    {
        return is_array($value) && ! array_is_list($value);
    }

    /** Whether the declared type is, or is a union containing, one of the named types. Untyped and `mixed` are neither. */
    private static function typed(?ReflectionParameter $parameter, string ...$names): bool
    {
        $type = $parameter?->getType();
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

        return array_any($types, static fn (mixed $type): bool => $type instanceof ReflectionNamedType && in_array($type->getName(), $names, true));
    }
}
