<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Internal\Engine\Signature;
use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;
use ZeroToProd\LaravelDeclaration\Tests\Feature\Engine\Support;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine\Arbitrary;

/** The oracle: Σ by reflection, the gate and the type vocabulary applied the same way. */
function reflectionSignatures(string $class): array
{
    $signatures = [];

    foreach (new ReflectionClass($class)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (str_starts_with($method->getName(), '__') || str_contains($method->getDocComment() ?: '', '@internal')) {
            continue;
        }

        if (array_any($method->getParameters(), static fn (ReflectionParameter $p): bool => $p->isPassedByReference())) {
            continue;
        }

        $params = [];

        foreach ($method->getParameters() as $parameter) {
            $params[($parameter->isVariadic() ? '...' : '').$parameter->getName()] = reflectionType($parameter->getType());
        }

        $signatures[$method->getName()] = [...($method->isStatic() ? ['static' => true] : []), 'params' => $params];
    }

    return $signatures;
}

function reflectionType(?ReflectionType $type): ?string
{
    if ($type instanceof ReflectionUnionType) {
        $members = array_values(array_filter($type->getTypes(), static fn (ReflectionType $t): bool => ! $t instanceof ReflectionNamedType || $t->getName() !== 'null'));

        return count($members) === 1 ? reflectionType($members[0]) : null;
    }

    if (! $type instanceof ReflectionNamedType) {
        return null;
    }

    return match ($type->getName()) {
        'array' => Signature::ARRAY,
        'callable', 'Closure' => Signature::CLOSURE,
        'string' => Signature::STRING,
        'int' => Signature::INT,
        'float' => Signature::FLOAT,
        'bool' => Signature::BOOL,
        default => null,
    };
}

it('matches reflection for every shipped class and the fixture: names, order, static, parameter names, types and variadics', function (string $class): void {
    $parsed = [];

    foreach (SchemaGenerator::signatures($class, Support::source()) as $signature) {
        $parsed[$signature->name] = $signature->toArray();
    }

    expect($parsed)->toBe(reflectionSignatures($class));
})->with(static function (): array {
    $shipped = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/manifest.schema.json'), true, 512, JSON_THROW_ON_ERROR);   // read directly: a dataset resolves before coverage starts

    return [...array_values($shipped['x-manifest']['classes']), Arbitrary::class];
});
