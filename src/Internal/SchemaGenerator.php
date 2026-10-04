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
     * @param  class-string  $class  the native Laravel class to project
     * @return array<string, mixed> the JSON-decodable definition object (not encoded — the caller encodes)
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

    /**
     * @param  array<string, mixed>  $schema  parsed manifest.schema.json
     * @param  array<string, mixed>  $fragment  from render()
     * @return array<string, mixed> the updated schema, ready to re-encode
     */
    public static function merge(array $schema, string $block, array $fragment): array
    {
        $definitions = is_array($schema['definitions'] ?? null) ? $schema['definitions'] : [];

        if (array_key_exists($block, $definitions)) {
            $curated = is_array($definitions[$block] ?? null) ? $definitions[$block] : [];

            $existing = is_array($curated['properties'] ?? null) ? $curated['properties'] : [];

            $incoming = is_array($fragment['properties'] ?? null) ? $fragment['properties'] : [];

            foreach ($incoming as $key => $keySchema) {
                if (! array_key_exists($key, $existing)) {
                    $existing[$key] = $keySchema; // appended in native declaration order
                }
            }

            $curated['properties'] = $existing;
            $definitions[$block] = $curated;
            $schema['definitions'] = $definitions;

            return $schema;
        }

        $definitions[$block] = $fragment;
        $schema['definitions'] = $definitions;

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $properties[$block] = ['$ref' => "#/definitions/$block"];
        $schema['properties'] = $properties;

        return $schema;
    }

    /**
     * Encodes a schema array in the repo's 2-space JSON style (§5.1).
     *
     * JSON_PRETTY_PRINT indents in exact multiples of 4, so halving reproduces
     * 2-space; JSON_UNESCAPED_UNICODE keeps the file's curated prose (`—`, `≈`)
     * readable; the trailing newline matches the shipped file.
     *
     * @param  array<string, mixed>  $schema
     */
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

    /** @return array<string, mixed> */
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

        $suffix = is_array($schema) && str_contains(json_encode($schema, JSON_THROW_ON_ERROR), '"array"')
            ? ', one call with the whole value'
            : ' when the key is present';

        return self::withDescription($schema, self::stub($method, [$parameter->getName()], $suffix, $unknown));
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

        if ($type instanceof ReflectionUnionType) {
            return self::unionSchema($type, $parameter->getName());
        }

        if ($type instanceof ReflectionNamedType) {
            $expanded = self::expand($type->getName());

            if ($expanded !== null) {
                $types = $expanded;

                if ($type->allowsNull()) {
                    $types[] = 'null';
                }

                return [['type' => self::typeValue($types)], []];
            }
        }

        return [true, [$parameter->getName()]];
    }

    /** @return array{0: mixed, 1: list<string>} */
    private static function unionSchema(ReflectionUnionType $type, string $name): array
    {
        $types = [];
        $nullable = false;

        foreach ($type->getTypes() as $member) {
            if (! $member instanceof ReflectionNamedType) {
                return [true, [$name]];
            }

            if ($member->getName() === 'null') {
                $nullable = true;

                continue;
            }

            $expanded = self::expand($member->getName());

            if ($expanded === null) {
                return [true, [$name]];
            }

            $types = [...$types, ...$expanded];
        }

        $precedence = array_flip(['string', 'integer', 'number', 'boolean', 'array', 'object', 'null']);

        uasort($types, static fn (string $left, string $right): int => $precedence[$left] <=> $precedence[$right]);

        if ($nullable) {
            $types[] = 'null';
        }

        return [['type' => self::typeValue(array_values($types))], []];
    }

    /** @return list<string>|null */
    private static function expand(string $name): ?array
    {
        return match ($name) {
            'string' => ['string'],
            'int' => ['integer'],
            'float' => ['number'],
            'bool' => ['boolean'],
            'array' => ['array', 'object'],
            default => null,
        };
    }

    /**
     * @param  list<string>  $types
     * @return string|list<string>
     */
    private static function typeValue(array $types): string|array
    {
        $unique = array_values(array_unique($types));

        if (count($unique) === 1) {
            return $unique[0];
        }

        return $unique;
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
