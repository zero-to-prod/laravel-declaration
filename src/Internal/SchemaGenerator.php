<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/** @internal */
final class SchemaGenerator
{
    /**
     * @param  class-string  $class
     * @param  string  $block
     * @return array<string, mixed>)
     *
     * @throws ReflectionException
     */
    public static function render(string $class, string $block = ''): array
    {
        $Reflection = new ReflectionClass($class);

        $properties = [];

        foreach (self::declarable($Reflection)[0] as $method) {
            $properties[$method->getName()] = self::key($method);
        }

        return [
            'description' => $Reflection->getName().' methods: every key is a method name, its value the argument(s).',
            'type' => ['object', 'null'],
            'additionalProperties' => false,
            'properties' => $properties,
        ];
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     *
     * @throws ReflectionException
     */
    public static function skipped(string $class): array
    {
        return array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            self::declarable(new ReflectionClass($class))[1],
        );
    }

    /** @param  array<string, mixed>  $schema */
    public static function encode(array $schema): string
    {
        $json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return (preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json) ?? $json)."\n";
    }

    /**
     * @param  ReflectionClass<object>  $Reflection
     * @return array{0: list<ReflectionMethod>, 1: list<ReflectionMethod>}
     */
    private static function declarable(ReflectionClass $Reflection): array
    {
        $methods = [];
        $skipped = [];

        foreach ($Reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $Reflection->getName()) {
                continue;
            }

            if (str_contains((string) $method->getDocComment(), '@internal')) {
                continue;
            }

            if (str_starts_with($method->getName(), '__')) {
                continue;
            }

            $parameters = $method->getParameters();

            if ($parameters === [] || array_any($parameters, static fn (ReflectionParameter $parameter): bool => $parameter->isPassedByReference())) {
                $skipped[] = $method;

                continue;
            }

            $methods[] = $method;
        }

        return [$methods, $skipped];
    }

    /** @return array<string, mixed> */
    private static function key(ReflectionMethod $method): array
    {
        $parameters = $method->getParameters();

        if (count($parameters) === 1 && $parameters[0]->isVariadic()) {
            return self::append($method, $parameters[0]);
        }

        if (count($parameters) === 1) {
            return self::setter($method, $parameters[0]);
        }

        if (count($parameters) === 2 && self::scalarKey($parameters[0]) && self::stringArrayUnion($parameters[1]->getType())) {
            return self::appendTo($method, $parameters[0], $parameters[1]);
        }

        if (count($parameters) === 2 && self::scalarKey($parameters[0])) {
            return self::binding($method, $parameters[0], $parameters[1]);
        }

        return self::undecided($method);
    }

    private static function scalarKey(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        if ($type === null) {
            return true;
        }

        $members = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

        return array_all($members, static fn (ReflectionType $type): bool => $type instanceof ReflectionNamedType
            && ! $type->allowsNull()
            && in_array($type->getName(), ['string', 'int'], true));
    }

    private static function stringArrayUnion(?ReflectionType $type): bool
    {
        if (! $type instanceof ReflectionUnionType) {
            return false;
        }

        $names = array_map(static fn (ReflectionType $type): string => $type instanceof ReflectionNamedType ? $type->getName() : '', $type->getTypes());

        sort($names);

        return $names === ['array', 'string'];
    }

    /** return array<string, mixed>*/
    private static function binding(ReflectionMethod $method, ReflectionParameter $key, ReflectionParameter $value): array
    {
        [$schema, $unknown] = self::paramSchema($value);

        return self::withDescription(
            ['type' => 'object', 'additionalProperties' => $schema],
            self::stub($method, [$key->getName(), $value->getName()], ', one call per entry', $unknown),
        );
    }

    /** @return array<string, mixed> */
    private static function appendTo(ReflectionMethod $method, ReflectionParameter $key, ReflectionParameter $value): array
    {
        return self::withDescription(
            [
                'type' => 'object',
                'additionalProperties' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'array'],
                    ],
                ],
            ],
            self::stub($method, [$key->getName(), $value->getName()], ', one call per item', []),
        );
    }

    /** @return array<string, mixed> */
    private static function append(ReflectionMethod $method, ReflectionParameter $parameter): array
    {
        [$schema, $unknown] = self::paramSchema($parameter);

        return self::withDescription(
            ['type' => 'array', 'items' => $schema],
            self::stub($method, [$parameter->getName()], ', one call per item', $unknown),
        );
    }

    /** @return array<string, mixed> */
    private static function setter(ReflectionMethod $method, ReflectionParameter $parameter): array
    {
        [$schema, $unknown] = self::paramSchema($parameter);

        return self::withDescription($schema, self::stub($method, [$parameter->getName()], ' when the key is present', $unknown));
    }

    /** @return array<string, mixed> */
    private static function undecided(ReflectionMethod $method): array
    {
        $params = array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $method->getParameters());

        return self::withDescription(true, self::stub($method, $params, ' when the key is present', $params));
    }

    /** @return array{0: mixed, 1: list<string>} */
    private static function paramSchema(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType) {
            return [true, [$parameter->getName()]]; // untyped, union, intersection — unit 03 completes unions
        }

        $json = ['string' => 'string', 'int' => 'integer', 'float' => 'number', 'bool' => 'boolean'][$type->getName()] ?? null;

        if ($json === null) {
            return [true, [$parameter->getName()]];
        }

        return [$type->allowsNull() ? ['type' => [$json, 'null']] : ['type' => $json], []];
    }

    /**
     * @param  list<string>  $params
     * @param  list<string>  $undecided
     */
    private static function stub(ReflectionMethod $method, array $params, string $suffix, array $undecided): string
    {
        $todo = $undecided === [] ? '' : ' TODO('.$method->getName().': '.implode(', ', array_map(static fn (string $param): string => '$'.$param, $undecided)).')';

        return '-> '.$method->getName().'('.implode(', ', array_map(static fn (string $param): string => '$'.$param, $params)).')'.$suffix.$todo;
    }

    /** @return array<string, mixed> */
    private static function withDescription(mixed $schema, string $description): array
    {
        return is_array($schema) ? ['description' => $description, ...$schema] : ['description' => $description];
    }
}
