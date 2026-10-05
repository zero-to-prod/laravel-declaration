<?php

declare(strict_types=1);

namespace ZeroToProd\Manifest;

use Closure;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

final readonly class Interpreter
{
    /** @var Closure(mixed, ?ReflectionParameter): mixed */
    private Closure $resolve;

    /** @param  null|Closure(mixed, ?ReflectionParameter): mixed  $resolve  ρ — identity when null */
    public function __construct(?Closure $resolve = null)
    {
        $this->resolve = $resolve ?? static fn(mixed $value): mixed => $value;
    }

    /** @param  list<array<string, mixed>>  $body */
    public function body(object|string|null $receiver, array $body): void
    {
        foreach ($body as $node) {
            if (array_key_exists('receiver', $node)) {
                $this->body($node['receiver'], $node['calls'] ?? []);
            } else {
                $this->invoke($receiver, $node);
            }
        }
    }

    /** @param  list<array<string, mixed>>  $body */
    public function closure(array $body): Closure
    {
        return function (object $receiver) use ($body): void {
            $this->body($receiver, $body);
        };
    }

    /** @param  array<string, mixed>  $node */
    private function invoke(mixed $receiver, array $node): mixed
    {
        $method = $node['method'] ?? null;
        $parameters = $this->parameters($receiver, $method);
        $arguments = [];

        foreach ($node['args'] ?? [] as $key => $value) {
            $arguments[$key] = $this->argument($value, self::parameter($parameters, $key));
        }

        $result = is_string($receiver) ? $receiver::{$method}(...$arguments) : $receiver->{$method}(...$arguments);

        foreach ($node['then'] ?? [] as $next) {
            $result = $this->invoke($result, $next);
        }

        return $result;
    }

    /** @return list<ReflectionParameter>|null */
    private function parameters(mixed $receiver, string $method): ?array
    {
        return (is_object($receiver) || is_string($receiver)) && method_exists($receiver, $method)
            ? new ReflectionMethod($receiver, $method)->getParameters()
            : null;
    }

    /** @param  list<ReflectionParameter>|null  $parameters */
    private static function parameter(?array $parameters, int|string $key): ?ReflectionParameter
    {
        if ($parameters === null) {
            return null;
        }

        if (is_string($key)) {
            return array_find($parameters, static fn(ReflectionParameter $parameter): bool => $parameter->getName() === $key);
        }

        $last = $parameters === [] ? null : $parameters[count($parameters) - 1];

        return $parameters[$key] ?? (($last?->isVariadic() ?? false) ? $last : null);
    }

    private function argument(mixed $value, ?ReflectionParameter $parameter): mixed
    {
        return (is_array($value) && self::typed($parameter, Closure::class, 'callable'))
            ? $this->closure($value)
            : ($this->resolve)($value, $parameter);
    }

    private static function typed(?ReflectionParameter $parameter, string ...$names): bool
    {
        $type = $parameter?->getType();
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

        return array_any($types, static fn(mixed $type): bool => $type instanceof ReflectionNamedType && in_array($type->getName(), $names, true));
    }
}
