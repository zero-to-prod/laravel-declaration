<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator\Basic;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator\Kinds;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator\Types;

const BASIC_FRAGMENT = [
    'description' => 'ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator\Basic methods: every key is a method name, its value the argument(s).',
    'type' => ['object', 'null'],
    'additionalProperties' => false,
    'properties' => [
        'label' => [
            'description' => '-> label($label) when the key is present',
            'type' => 'string',
        ],
        'retries' => [
            'description' => '-> retries($retries) when the key is present',
            'type' => ['integer', 'null'],
        ],
        'handler' => [
            'description' => '-> handler($handler) when the key is present TODO(handler: $handler)',
        ],
        'register' => [
            'description' => '-> register($name, $class, $callback) when the key is present TODO(register: $name, $class, $callback)',
        ],
    ],
];

function typeProperty(string $key): mixed
{
    return SchemaGenerator::render(Types::class, 'types')['properties'][$key];
}

it('projects a class into a fragment with keys in native declaration order', function (): void {
    expect(SchemaGenerator::render(Basic::class))->toBe(BASIC_FRAGMENT);
});

it('reports skipped methods instead of dropping them silently', function (): void {
    expect(SchemaGenerator::skipped(Basic::class))->toBe(['version', 'attach']);
});

it('encodes in the repos 2-space json style', function (): void {
    expect(SchemaGenerator::encode(BASIC_FRAGMENT))->toBe(<<<'JSON'
        {
          "description": "ZeroToProd\\LaravelDeclaration\\Tests\\Fixtures\\SchemaGenerator\\Basic methods: every key is a method name, its value the argument(s).",
          "type": [
            "object",
            "null"
          ],
          "additionalProperties": false,
          "properties": {
            "label": {
              "description": "-> label($label) when the key is present",
              "type": "string"
            },
            "retries": {
              "description": "-> retries($retries) when the key is present",
              "type": [
                "integer",
                "null"
              ]
            },
            "handler": {
              "description": "-> handler($handler) when the key is present TODO(handler: $handler)"
            },
            "register": {
              "description": "-> register($name, $class, $callback) when the key is present TODO(register: $name, $class, $callback)"
            }
          }
        }

        JSON);
});

it('defaults two scalar-first params to a binding map', function (): void {
    expect(SchemaGenerator::render(Kinds::class, 'kinds')['properties']['group'])->toBe([
        'description' => '-> group($name, $middleware), one call per entry',
        'type' => 'object',
        'additionalProperties' => ['type' => 'string'],
    ]);
});

it('defaults a string|array second param to the append-to anyOf shape', function (): void {
    expect(SchemaGenerator::render(Kinds::class, 'kinds')['properties']['middleware'])->toBe([
        'description' => '-> middleware($group, $middleware), one call per item',
        'type' => 'object',
        'additionalProperties' => [
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'array'],
            ],
        ],
    ]);
});

it('marks an unknown binding value with true and a todo marker', function (): void {
    expect(SchemaGenerator::render(Kinds::class, 'kinds')['properties']['bind'])->toBe([
        'description' => '-> bind($key, $binder), one call per entry TODO(bind: $binder)',
        'type' => 'object',
        'additionalProperties' => true,
    ]);
});

it('defaults a variadic-only signature to an append list', function (): void {
    expect(SchemaGenerator::render(Kinds::class, 'kinds')['properties']['handlers'])->toBe([
        'description' => '-> handlers($handlers), one call per item',
        'type' => 'array',
        'items' => ['type' => 'string'],
    ]);
});

it('stays undecided when the first param cannot be a manifest key', function (): void {
    expect(SchemaGenerator::render(Kinds::class, 'kinds')['properties']['ratio'])->toBe([
        'description' => '-> ratio($rate, $value) when the key is present TODO(ratio: $rate, $value)',
    ]);
});

it('stays undecided when no kind row matches the arity', function (): void {
    expect(SchemaGenerator::render(Kinds::class, 'kinds')['properties']['register'])->toBe([
        'description' => '-> register($name, $class, $callback) when the key is present TODO(register: $name, $class, $callback)',
    ]);
});

it('maps array to the list-and-map union', function (): void {
    expect(typeProperty('verbs'))->toBe([
        'description' => '-> verbs($verbs), one call with the whole value',
        'type' => ['array', 'object'],
    ]);
});

it('maps scalar unions with deduplication in declared order', function (): void {
    expect(typeProperty('scale')['type'])->toBe(['string', 'integer']);
});

it('maps float and bool scalars', function (): void {
    expect(typeProperty('ratio')['type'])->toBe('number')
        ->and(typeProperty('enabled')['type'])->toBe('boolean');
});

it('expands an array member in place inside a union', function (): void {
    expect(typeProperty('pair')['type'])->toBe(['string', 'array', 'object']);
});

it('appends null last for nullable unions', function (): void {
    expect(typeProperty('maybe')['type'])->toBe(['string', 'integer', 'null'])
        ->and(typeProperty('optional')['type'])->toBe(['array', 'object', 'null']);
});

it('marks untypeable params honestly instead of inventing semantics', function (): void {
    expect(typeProperty('sink'))->toBe([
        'description' => '-> sink($sink) when the key is present TODO(sink: $sink)',
    ])
        ->and(typeProperty('target'))->toBe([
            'description' => '-> target($suit) when the key is present TODO(target: $suit)',
        ])
        ->and(typeProperty('callback'))->toBe([
            'description' => '-> callback($callback) when the key is present TODO(callback: $callback)',
        ])
        ->and(typeProperty('flow'))->toBe([
            'description' => '-> flow($items) when the key is present TODO(flow: $items)',
        ]);
});

it('refuses a union that carries an untypeable member', function (): void {
    expect(typeProperty('shape'))->toBe([
        'description' => '-> shape($shape) when the key is present TODO(shape: $shape)',
    ]);
});

it('refuses a union that carries an intersection member', function (): void {
    expect(typeProperty('either'))->toBe([
        'description' => '-> either($either) when the key is present TODO(either: $either)',
    ]);
});

function curatedSchema(): array
{
    return [
        '$schema' => 'http://json-schema.org/draft-07/schema#',
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'app' => ['$ref' => '#/definitions/app'],
        ],
        'definitions' => [
            'app' => [
                'description' => 'curated app prose',
                'type' => ['object', 'null'],
                'additionalProperties' => false,
                'properties' => [
                    'bind' => [
                        'description' => 'curated bind prose',
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'string', 'pattern' => '^App\\\\'],
                    ],
                    'stale' => ['description' => 'no longer native'],
                ],
            ],
        ],
    ];
}

it('appends only missing keys and preserves every curated byte of a key', function (): void {
    $fragment = SchemaGenerator::render(Basic::class, 'app');

    $merged = SchemaGenerator::merge(curatedSchema(), 'app', $fragment);
    $app = $merged['definitions']['app'];

    expect($app['description'])->toBe('curated app prose') // envelope untouched
        ->and($app['properties']['bind'])->toBe(curatedSchema()['definitions']['app']['properties']['bind'])
        ->and($app['properties']['stale'])->toBe(['description' => 'no longer native']) // never deleted
        ->and(array_keys($app['properties']))->toBe(['bind', 'stale', 'label', 'retries', 'handler', 'register']) // native order
        ->and($merged['definitions'])->toHaveKey('app') // sibling definitions untouched
        ->and($merged['properties'])->toBe(curatedSchema()['properties']); // root untouched for an existing block
});

it('wires a new block into definitions and the root properties', function (): void {
    $fragment = SchemaGenerator::render(Kinds::class, 'kinds');

    $merged = SchemaGenerator::merge(curatedSchema(), 'kinds', $fragment);

    expect($merged['definitions']['kinds'])->toBe($fragment)
        ->and($merged['properties']['kinds'])->toBe(['$ref' => '#/definitions/kinds'])
        ->and(array_keys($merged['properties']))->toBe(['app', 'kinds']); // appended at the end
});

it('merges idempotently', function (): void {
    $fragment = SchemaGenerator::render(Kinds::class, 'kinds');

    $once = SchemaGenerator::merge(curatedSchema(), 'kinds', $fragment);
    $twice = SchemaGenerator::merge($once, 'kinds', $fragment);

    expect($twice)->toBe($once);
});

it('encodes with 2-space indentation, unescaped unicode and a trailing newline', function (): void {
    $encoded = SchemaGenerator::encode([
        'description' => 'curated — prose ≈ here',
        'type' => 'object',
    ]);

    expect($encoded)->toBe(<<<'JSON'
        {
          "description": "curated — prose ≈ here",
          "type": "object"
        }

        JSON);
});

it('re-encodes the shipped schema idempotently', function (): void {
    $raw = file_get_contents(dirname(__DIR__, 2).'/manifest.schema.json');

    $schema = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    $once = SchemaGenerator::encode($schema);
    $twice = SchemaGenerator::encode(json_decode($once, true, 512, JSON_THROW_ON_ERROR));

    expect($once)->toBe($twice) // deterministic + idempotent
        ->and($once)->toContain('—') // unicode preserved
        ->and($once)->toEndWith("}\n");
});
