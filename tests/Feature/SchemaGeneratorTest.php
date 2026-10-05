<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Illuminate\Foundation\Application;
use PhpParser\Error;
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

/**
 * The command's locate/read seam (§2.1): findFile resolves Laravel's multi-prefix
 * PSR-4 mapping (§1.8) for fixtures and vendor sources alike.
 */
function fileSource(): Closure
{
    return static function (string $fqcn): ?string {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $path = $loader->findFile($fqcn);

            if (is_string($path) && is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        return null;
    };
}

/**
 * @param  array<string, string>  $map
 */
function mapSource(array $map): Closure
{
    return static fn (string $fqcn): ?string => $map[$fqcn] ?? null;
}

function typeProperty(string $key): mixed
{
    return SchemaGenerator::render(Types::class, fileSource())['properties'][$key];
}

it('projects a class into a fragment with keys in native declaration order', function (): void {
    expect(SchemaGenerator::render(Basic::class, fileSource()))->toBe(BASIC_FRAGMENT);
});

it('reports skipped methods instead of dropping them silently', function (): void {
    expect(SchemaGenerator::skipped(Basic::class, fileSource()))->toBe(['version', 'attach']);
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
    expect(SchemaGenerator::render(Kinds::class, fileSource())['properties']['group'])->toBe([
        'description' => '-> group($name, $middleware), one call per entry',
        'type' => 'object',
        'additionalProperties' => ['type' => 'string'],
    ]);
});

it('defaults a string|array second param to the append-to anyOf shape', function (): void {
    expect(SchemaGenerator::render(Kinds::class, fileSource())['properties']['middleware'])->toBe([
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

it('fires append-to for a union declared in reverse member order', function (): void {
    expect(SchemaGenerator::render(Kinds::class, fileSource())['properties']['suffix'])->toBe([
        'description' => '-> suffix($group, $values), one call per item',
        'type' => 'object',
        'additionalProperties' => [
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'array'],
            ],
        ],
    ]);
});

it('treats a scalar union first param as a manifest key', function (): void {
    expect(SchemaGenerator::render(Kinds::class, fileSource())['properties']['unionKey'])->toBe([
        'description' => '-> unionKey($key, $value), one call per entry',
        'type' => 'object',
        'additionalProperties' => ['type' => 'string'],
    ]);
});

it('stays undecided when the first param is nullable', function (): void {
    expect(SchemaGenerator::render(Kinds::class, fileSource())['properties']['optionalKey'])->toBe([
        'description' => '-> optionalKey($key, $value) when the key is present TODO(optionalKey: $key, $value)',
    ]);
});

it('marks an unknown binding value with true and a todo marker', function (): void {
    expect(SchemaGenerator::render(Kinds::class, fileSource())['properties']['bind'])->toBe([
        'description' => '-> bind($key, $binder), one call per entry TODO(bind: $binder)',
        'type' => 'object',
        'additionalProperties' => true,
    ]);
});

it('defaults a variadic-only signature to an append list', function (): void {
    expect(SchemaGenerator::render(Kinds::class, fileSource())['properties']['handlers'])->toBe([
        'description' => '-> handlers($handlers), one call per item',
        'type' => 'array',
        'items' => ['type' => 'string'],
    ]);
});

it('stays undecided when the first param cannot be a manifest key', function (): void {
    expect(SchemaGenerator::render(Kinds::class, fileSource())['properties']['ratio'])->toBe([
        'description' => '-> ratio($rate, $value) when the key is present TODO(ratio: $rate, $value)',
    ]);
});

it('stays undecided when no kind row matches the arity', function (): void {
    expect(SchemaGenerator::render(Kinds::class, fileSource())['properties']['register'])->toBe([
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
        ])
        ->and(typeProperty('halt'))->toBe([
            'description' => '-> halt($halt) when the key is present TODO(halt: $halt)',
        ])
        ->and(typeProperty('container'))->toBe([
            'description' => '-> container($container) when the key is present TODO(container: $container)',
        ]);
});

it('refuses a union that carries an untypeable member', function (): void {
    expect(typeProperty('shape'))->toBe([
        'description' => '-> shape($shape) when the key is present TODO(shape: $shape)',
    ]);
});

it('refuses a union that carries a class member', function (): void {
    expect(typeProperty('closure'))->toBe([
        'description' => '-> closure($closure) when the key is present TODO(closure: $closure)',
    ]);
});

it('refuses a union that carries an intersection member', function (): void {
    expect(typeProperty('either'))->toBe([
        'description' => '-> either($either) when the key is present TODO(either: $either)',
    ]);
});

it('excludes parent methods from the projection (Rule 0.4)', function (): void {
    // Illuminate\Foundation\Application extends Container: bind/singleton declare Container.
    $fragment = SchemaGenerator::render(Application::class, fileSource());

    expect($fragment['properties'])
        ->not->toHaveKey('bind')
        ->not->toHaveKey('singleton')
        ->toHaveKey('basePath');
});

it('fails natively when no readable source exists for the class', function (): void {
    expect(fn (): array => SchemaGenerator::render('ZeroToProd\Nope', fileSource()))
        ->toThrow(RuntimeException::class, 'No readable source for ZeroToProd\Nope');
});

it('fails natively when the source is not valid PHP (Rule 3.3)', function (): void {
    expect(fn (): array => SchemaGenerator::render('X\Bad', mapSource(['X\Bad' => '<?php {"not php"}}}'])))
        ->toThrow(Error::class);
});

it('fails natively when the class is not declared in its resolved source', function (): void {
    expect(fn (): array => SchemaGenerator::render('X\Shim', mapSource(['X\Shim' => '<?php

        namespace X;

        class Other {}
        '])))
        ->toThrow(RuntimeException::class, 'X\Shim is not declared in its resolved source');
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
            Basic::class => [
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
    $fragment = SchemaGenerator::render(Basic::class, fileSource());

    $merged = SchemaGenerator::merge(curatedSchema(), Basic::class, $fragment);
    $curated = $merged['definitions'][Basic::class];

    expect($curated['description'])->toBe('curated app prose') // envelope untouched
        ->and($curated['properties']['bind'])->toBe(curatedSchema()['definitions'][Basic::class]['properties']['bind'])
        ->and($curated['properties']['stale'])->toBe(['description' => 'no longer native']) // never deleted
        ->and(array_keys($curated['properties']))->toBe(['bind', 'stale', 'label', 'retries', 'handler', 'register']) // native order
        ->and($merged['definitions'])->toHaveKey(Basic::class) // sibling definitions untouched
        ->and($merged['properties'])->toBe(curatedSchema()['properties']); // root untouched for an existing definition
});

it('inserts the whole fragment for a new definition and leaves the root properties untouched', function (): void {
    $fragment = SchemaGenerator::render(Kinds::class, fileSource());

    $merged = SchemaGenerator::merge(curatedSchema(), Kinds::class, $fragment);

    expect($merged['definitions'][Kinds::class])->toBe($fragment)
        ->and($merged['properties'])->toBe(curatedSchema()['properties']); // the $ref wiring is hand-written curation (§2.3)
});

it('merges idempotently', function (): void {
    $fragment = SchemaGenerator::render(Kinds::class, fileSource());

    $once = SchemaGenerator::merge(curatedSchema(), Kinds::class, $fragment);
    $twice = SchemaGenerator::merge($once, Kinds::class, $fragment);

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
