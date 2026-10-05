<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Traits\Macroable;
use PhpParser\Error;
use ZeroToProd\LaravelDeclaration\Internal\Engine\Signature;
use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;
use ZeroToProd\LaravelDeclaration\Tests\Feature\Engine\Support;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine\Arbitrary;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator\Basic;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator\Kinds;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator\Types;

/** @param  array<string, string>  $map */
function mapSource(array $map): Closure
{
    return static fn (string $fqcn): ?string => $map[$fqcn] ?? null;
}

/** @return array<string, array<string, string|null>> method → {param: type} */
function params(string $class): array
{
    $params = [];

    foreach (SchemaGenerator::signatures($class, Support::source()) as $signature) {
        $params[$signature->name] = $signature->toArray()['params'];
    }

    return $params;
}

// ── Σ ────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('projects own, trait and parent methods in reflection order, the gate applied', function (): void {
    $names = array_map(static fn (Signature $s): string => $s->name, SchemaGenerator::signatures(Basic::class, Support::source()));

    expect($names)->toBe(['label', 'retries', 'handler', 'register', 'version', 'inherited'])   // __construct, @internal, by-ref excluded; zero-param kept; parent last
        ->and(SchemaGenerator::skipped(Basic::class, Support::source()))->toBe(['attach'])         // by-reference: reported, never silent
        ->and(SchemaGenerator::skipped(Router::class, Support::source()))->toBeEmpty();
});

it('maps every parameter type to the signature vocabulary', function (): void {
    expect(params(Types::class))->toBe([
        'verbs' => ['verbs' => 'array'],
        'scale' => ['scale' => null],                   // a union of two non-null members is untyped
        'ratio' => ['ratio' => 'float'],
        'enabled' => ['enabled' => 'bool'],
        'pair' => ['pair' => null],
        'maybe' => ['maybe' => null],
        'optional' => ['optional' => 'array'],          // ?array is array
        'sink' => ['sink' => null],                     // mixed
        'target' => ['suit' => null],                   // a class
        'callback' => ['callback' => 'closure'],
        'flow' => ['items' => null],                    // iterable
        'shape' => ['shape' => null],
        'either' => ['either' => null],                 // (A&B)|string
        'halt' => ['halt' => null],                     // never
        'container' => ['container' => null],           // object
        'closure' => ['closure' => null],               // string|Closure
    ])
        ->and(params(Kinds::class)['register'])->toBe(['name' => 'string', 'class' => 'string', 'callback' => 'closure'])  // ?Closure
        ->and(params(Kinds::class)['handlers'])->toBe(['...handlers' => 'string'])
        ->and(params(Kinds::class)['optionalKey'])->toBe(['key' => 'string', 'value' => 'string'])
        ->and(params(Arbitrary::class)['compiler'])->toBe(['compiler' => 'closure'])                                      // callable
        ->and(Support::signature('preset')->static)->toBeTrue();
});

it('includes the parent chain so a Container method projects onto the Application', function (): void {
    $names = array_map(static fn (Signature $s): string => $s->name, SchemaGenerator::signatures(Application::class, Support::source()));

    expect($names)->toContain('bind', 'singleton', 'when', 'basePath')
        ->and(array_search('basePath', $names, true))->toBeLessThan(array_search('bind', $names, true));   // own first, parents after
});

it('resolves real vendor traits through the multi-prefix PSR-4 mapping', function (): void {
    $names = array_map(static fn (Signature $s): string => $s->name, SchemaGenerator::signatures(Macroable::class, Support::source()));

    expect($names)->toBe(['macro', 'mixin', 'hasMacro', 'flushMacros']);   // __call/__callStatic filtered, zero-param kept
});

it('fails natively when no readable source exists for the class', function (): void {
    SchemaGenerator::signatures('ZeroToProd\Nope', Support::source());
})->throws(RuntimeException::class, 'No readable source for ZeroToProd\Nope');

it('fails natively when the source is not valid PHP', function (): void {
    SchemaGenerator::signatures('X\Bad', mapSource(['X\Bad' => '<?php {"not php"}}}']));
})->throws(Error::class);

it('fails natively when the class is not declared in its resolved source', function (): void {
    SchemaGenerator::signatures('X\Shim', mapSource(['X\Shim' => "<?php\n\nnamespace X;\n\nclass Other {}\n"]));
})->throws(RuntimeException::class, 'X\Shim is not declared in its resolved source');

// ── traits ───────────────────────────────────────────────────────────────────────────────────────────────────────

const TRAIT_FIXTURES = [
    'T\Alias' => "<?php\n\nnamespace T;\n\ntrait Alias\n{\n    public function ping(string \$name): void {}\n\n    public function pong(string \$name): void {}\n}\n",
    'C\UsesAlias' => "<?php\n\nnamespace C;\n\nuse T\\Alias;\n\nclass UsesAlias\n{\n    use Alias {\n        ping as knock;\n    }\n}\n",
    'C\UsesVisibility' => "<?php\n\nnamespace C;\n\nuse T\\Alias;\n\nclass UsesVisibility\n{\n    use Alias {\n        ping as public;\n    }\n}\n",
    'T\First' => "<?php\n\nnamespace T;\n\ntrait First\n{\n    public function m(string \$a): void {}\n}\n",
    'T\Second' => "<?php\n\nnamespace T;\n\ntrait Second\n{\n    public function m(int \$a): void {}\n}\n",
    'C\Prefers' => "<?php\n\nnamespace C;\n\nuse T\\First, T\\Second;\n\nclass Prefers\n{\n    use First, Second {\n        Second::m insteadof First;\n    }\n}\n",
    'C\Ambiguous' => "<?php\n\nnamespace C;\n\nuse T\\First, T\\Second;\n\nclass Ambiguous\n{\n    use First, Second {\n        m as alias;\n    }\n}\n",
    'C\Unambiguous' => "<?php\n\nnamespace C;\n\nuse T\\First, T\\Alias;\n\nclass Unambiguous\n{\n    use First, Alias {\n        ping as knock;\n    }\n}\n",
    'C\Named' => "<?php\n\nnamespace C;\n\nuse T\\First, T\\Alias;\n\nclass Named\n{\n    use Alias, First {\n        First::m as gamma;\n    }\n}\n",
    'C\Dangling' => "<?php\n\nnamespace C;\n\nuse T\\First;\n\nclass Dangling\n{\n    use First {\n        First::b as c;\n    }\n}\n",
    'D\One' => "<?php\n\nnamespace D;\n\ntrait One\n{\n    public function p(string \$a): void {}\n}\n",
    'D\Two' => "<?php\n\nnamespace D;\n\nuse D\\One;\n\ntrait Two\n{\n    use One;\n}\n",
    'D\UsesBoth' => "<?php\n\nnamespace D;\n\nuse D\\One, D\\Two;\n\nclass UsesBoth\n{\n    use One, Two;\n}\n",
    'I\Base' => "<?php\n\nnamespace I;\n\ninterface Base\n{\n    public function a(string \$x): void;\n}\n",
    'I\Child' => "<?php\n\nnamespace I;\n\ninterface Child extends Base\n{\n    public function b(string \$x): void;\n}\n",
];

function traitNames(string $class): array
{
    return array_map(static fn (Signature $s): string => $s->name, SchemaGenerator::signatures($class, mapSource(TRAIT_FIXTURES)));
}

it('adds an as alias right before the method it names, and none for a visibility-only alias', function (): void {
    expect(traitNames('C\UsesAlias'))->toBe(['knock', 'ping', 'pong'])
        ->and(traitNames('C\UsesVisibility'))->toBe(['ping', 'pong']);
});

it('resolves insteadof to the winner', function (): void {
    [$m] = SchemaGenerator::signatures('C\Prefers', mapSource(TRAIT_FIXTURES));

    expect($m->toArray())->toBe(['params' => ['a' => 'int']]);   // Second's m won
});

it('inlines a diamond once and walks interface parents', function (): void {
    expect(traitNames('D\UsesBoth'))->toBe(['p'])
        ->and(traitNames('I\Child'))->toBe(['b', 'a']);
});

it('binds a trait-less alias to the one trait that defines the method, and a named alias to its trait', function (): void {
    expect(traitNames('C\Unambiguous'))->toBe(['m', 'knock', 'ping', 'pong'])
        ->and(traitNames('C\Named'))->toBe(['ping', 'pong', 'gamma', 'm']);
});

it('reproduces the PHP fatal for an ambiguous trait-less alias', function (): void {
    traitNames('C\Ambiguous');
})->throws(RuntimeException::class, 'Ambiguous alias m across multiple traits');

it('reproduces the PHP fatal for a dangling alias', function (): void {
    traitNames('C\Dangling');
})->throws(RuntimeException::class, 'No such trait method T\First::b');

// ── generate ─────────────────────────────────────────────────────────────────────────────────────────────────────

it('generates a total, FQCN-keyed definition per class and the bodies vocabulary', function (): void {
    [$schema, $report] = SchemaGenerator::generate([Basic::class, Kinds::class], null, Support::source());

    expect(array_keys($schema['definitions']))->toBe([Basic::class, Kinds::class, 'bodies'])
        ->and(array_keys($schema['definitions'][Basic::class]['properties']))->toBe(['label', 'retries', 'handler', 'register', 'version', 'inherited'])
        ->and($schema['definitions'][Basic::class]['additionalProperties'])->toBeFalse()
        ->and($schema['definitions'][Basic::class]['properties']['label']['description'])->toBe('-> label($label)')
        ->and($schema['definitions'][Basic::class]['properties']['handler']['description'])->toBe('-> handler($handler) TODO(handler: $handler)')
        ->and($schema['definitions']['bodies']['properties'])->toBe([Basic::class => ['$ref' => '#/definitions/'.Basic::class], Kinds::class => ['$ref' => '#/definitions/'.Kinds::class]])
        ->and($schema['x-manifest'])->toBe(['classes' => [Basic::class, Kinds::class]])
        ->and($schema['properties'])->toBeInstanceOf(stdClass::class)                              // no root receiver, no static receiver, no data key
        ->and($schema['type'])->toBe(['object', 'null'])
        ->and($report['todo'])->toContain(Basic::class.'::handler', Kinds::class.'::bind')
        ->and($report['curation-only'])->toBeEmpty();
});

it('re-merges the prior curation per key, keeps unknown keys and foreign definitions, and composes the root', function (): void {
    $prior = [
        'title' => 'Curated title',
        'definitions' => [
            'closure' => ['type' => 'string'],
            Arbitrary::class => [
                'description' => 'regenerated envelope',
                'properties' => [
                    'name' => ['description' => 'curated prose', 'pattern' => '^[a-z]+$', 'type' => 'stale', 'x-manifest' => ['static' => 'stale', 'resolve' => ['name' => 'path'], 'bogus' => 1]],
                    'stale' => ['description' => 'a __call surface'],
                ],
            ],
        ],
        'properties' => [
            'requests' => ['type' => 'array', 'x-manifest' => ['data' => true]],
            'retired' => ['type' => 'object'],
        ],
    ];

    [$schema, $report] = SchemaGenerator::generate([Arbitrary::class], $prior, Support::source());
    $name = $schema['definitions'][Arbitrary::class]['properties']['name'];

    expect($schema['title'])->toBe('Curated title')
        ->and($name['description'])->toBe('curated prose')
        ->and($name['pattern'])->toBe('^[a-z]+$')
        ->and($name['anyOf'][1])->toBe(['$ref' => '#/definitions/path'])                                  // the curated vocabulary drives the shape
        ->and($name['x-manifest'])->toBe(['params' => ['name' => 'string'], 'resolve' => ['name' => 'path']])   // signature regenerated, curation kept, noise dropped
        ->and($name)->not->toHaveKey('type')
        ->and($schema['definitions'][Arbitrary::class]['properties']['stale'])->toBe(['description' => 'a __call surface'])
        ->and($schema['definitions'][Arbitrary::class]['description'])->toStartWith(Arbitrary::class.' methods')
        ->and($schema['definitions']['closure'])->toBe(['type' => 'string'])
        ->and(array_keys($schema['properties']))->toBe(['requests', Arbitrary::class])                     // data key kept, retired root key dropped, static receiver added
        ->and(array_keys($schema['properties'][Arbitrary::class]['properties']))->toBe(['preset', 'reset'])
        ->and($schema['properties'][Arbitrary::class]['properties']['preset'])->toBe(['$ref' => '#/definitions/'.Arbitrary::class.'/properties/preset'])
        ->and($report['curation-only'])->toBe([Arbitrary::class.'::stale']);
});

it('points the root at the root receiver\'s keys when the Application is in scope', function (): void {
    [$schema] = SchemaGenerator::generate([Application::class], null, Support::source());

    expect($schema['properties']['bind'])->toBe(['$ref' => '#/definitions/'.Application::class.'/properties/bind'])
        ->and($schema['properties'])->toHaveKey(Application::class);   // its own statics (macro, configure, …)
});

it('is a fixed point: regenerating from its own output is byte-identical', function (): void {
    [$once] = SchemaGenerator::generate(Support::CLASSES, Support::prior(), Support::source());
    [$twice] = SchemaGenerator::generate(Support::CLASSES, $once, Support::source());

    expect(SchemaGenerator::encode($twice))->toBe(SchemaGenerator::encode($once));
});

// ── encode ───────────────────────────────────────────────────────────────────────────────────────────────────────

it('prints short nodes inline and long ones across 2-space lines, unicode kept, idempotently', function (): void {
    $encoded = SchemaGenerator::encode([
        'description' => 'curated — prose ≈ here',
        'type' => ['object', 'null'],
        'empty' => [],
        'object' => new stdClass,
        'long' => ['a' => str_repeat('x', 60), 'b' => str_repeat('y', 60)],
        'list' => [['k' => 'v'], 'plain'],
    ]);
    $arrays = str_replace("\n  \"object\": {},", '', $encoded);

    expect($encoded)->toBe(<<<'JSON'
        {
          "description": "curated — prose ≈ here",
          "type": ["object", "null"],
          "empty": [],
          "object": {},
          "long": {
            "a": "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
            "b": "yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy"
          },
          "list": [{"k": "v"}, "plain"]
        }

        JSON)
        ->and(SchemaGenerator::encode(json_decode($arrays, true, 512, JSON_THROW_ON_ERROR)))->toBe($arrays);   // idempotent over arrays (an empty object decodes to a list)
});

it('re-encodes the shipped schema idempotently', function (): void {
    $raw = (string) file_get_contents(dirname(__DIR__, 3).'/manifest.schema.json');

    expect(SchemaGenerator::encode(json_decode($raw, true, 512, JSON_THROW_ON_ERROR)))->toBe($raw);
});
