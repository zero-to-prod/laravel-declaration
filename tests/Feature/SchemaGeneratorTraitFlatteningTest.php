<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Illuminate\Support\Traits\Macroable;
use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;

const TRAIT_FIXTURES = [
    'T\Alias' => <<<'PHP'
        <?php

        namespace T;

        trait Alias
        {
            public function ping(string $name): void {}

            public function pong(string $name): void {}
        }
        PHP,
    'C\UsesAlias' => <<<'PHP'
        <?php

        namespace C;

        use T\Alias;

        class UsesAlias
        {
            use Alias {
                ping as knock;
            }
        }
        PHP,
    'C\UsesVisibility' => <<<'PHP'
        <?php

        namespace C;

        use T\Alias;

        class UsesVisibility
        {
            use Alias {
                ping as public;
            }
        }
        PHP,
];

/**
 * @param  array<string, string>  $map
 */
function mapTraitSource(array $map): Closure
{
    return static fn (string $fqcn): ?string => $map[$fqcn] ?? null;
}

it('appends an as alias right after the trait\'s methods, per statement', function (): void {
    $properties = SchemaGenerator::render('C\UsesAlias', mapTraitSource(TRAIT_FIXTURES))['properties'];

    expect(array_keys($properties))->toBe(['ping', 'pong', 'knock']); // knock directly after T\Alias's methods
});

it('adds no method for a visibility-only as alias', function (): void {
    $properties = SchemaGenerator::render('C\UsesVisibility', mapTraitSource(TRAIT_FIXTURES))['properties'];

    expect(array_keys($properties))->toBe(['ping', 'pong']);
});

it('resolves insteadof to the winner, whatever the statement order', function (): void {
    $map = [
        'T\First' => <<<'PHP'
            <?php

            namespace T;

            trait First
            {
                public function m(string $a): void {}
            }
            PHP,
        'T\Second' => <<<'PHP'
            <?php

            namespace T;

            trait Second
            {
                public function m(int $a): void {}
            }
            PHP,
        'C\Prefers' => <<<'PHP'
            <?php

            namespace C;

            use T\First, T\Second;

            class Prefers
            {
                use First, Second {
                    Second::m insteadof First;
                }
            }
            PHP,
    ];

    $m = SchemaGenerator::render('C\Prefers', mapTraitSource($map))['properties']['m'];

    expect($m['type'])->toBe('integer'); // Second's m won — the distinguishing signatures prove the direction (§2.4)
});

it('inlines a diamond once', function (): void {
    // T1 has p(); T2 uses T1; the class uses T1 and T2 — the seen-set collapses T1 to one inline.
    $map = [
        'D\One' => <<<'PHP'
            <?php

            namespace D;

            trait One
            {
                public function p(string $a): void {}
            }
            PHP,
        'D\Two' => <<<'PHP'
            <?php

            namespace D;

            use D\One;

            trait Two
            {
                use One;
            }
            PHP,
        'D\UsesBoth' => <<<'PHP'
            <?php

            namespace D;

            use D\One, D\Two;

            class UsesBoth
            {
                use One, Two;
            }
            PHP,
    ];

    $properties = SchemaGenerator::render('D\UsesBoth', mapTraitSource($map))['properties'];

    expect(array_keys($properties))->toBe(['p']); // exactly once, in T1's source order (§2.4)
});

it('reproduces the PHP fatal for an ambiguous trait-less alias across multiple traits', function (): void {
    $map = [
        'A\One' => <<<'PHP'
            <?php

            namespace A;

            trait One
            {
                public function m(string $a): void {}
            }
            PHP,
        'A\Two' => <<<'PHP'
            <?php

            namespace A;

            trait Two
            {
                public function m(int $a): void {}
            }
            PHP,
        'A\Ambiguous' => <<<'PHP'
            <?php

            namespace A;

            use A\One, A\Two;

            class Ambiguous
            {
                use One, Two {
                    m as alias;
                }
            }
            PHP,
    ];

    expect(fn (): array => SchemaGenerator::render('A\Ambiguous', mapTraitSource($map)))
        ->toThrow(RuntimeException::class, 'Ambiguous alias m across multiple traits');
});

it('binds a trait-specified alias to its named trait, skipping the others', function (): void {
    $map = [
        'S\One' => <<<'PHP'
            <?php

            namespace S;

            trait One
            {
                public function alpha(string $a): void {}
            }
            PHP,
        'S\Two' => <<<'PHP'
            <?php

            namespace S;

            trait Two
            {
                public function beta(string $b): void {}
            }
            PHP,
        'S\C' => <<<'PHP'
            <?php

            namespace S;

            use S\One, S\Two;

            class C
            {
                use Two, One {
                    One::alpha as gamma;
                }
            }
            PHP,
    ];

    $properties = SchemaGenerator::render('S\C', mapTraitSource($map))['properties'];

    expect(array_keys($properties))->toBe(['beta', 'alpha', 'gamma']); // gamma appended after the statement's spliced traits
});

it('reproduces the PHP fatal for a dangling alias', function (): void {
    $map = [
        'G\T' => <<<'PHP'
            <?php

            namespace G;

            trait T
            {
                public function a(string $x): void {}
            }
            PHP,
        'G\C' => <<<'PHP'
            <?php

            namespace G;

            use G\T;

            class C
            {
                use T {
                    T::b as c;
                }
            }
            PHP,
    ];

    expect(fn (): array => SchemaGenerator::render('G\C', mapTraitSource($map)))
        ->toThrow(RuntimeException::class, 'No such trait method G\T::b');
});

it('resolves real vendor traits through the multi-prefix PSR-4 mapping', function (): void {
    // Illuminate\Support\Traits\Macroable lives in Macroable/Traits/Macroable.php (§1.8).
    $properties = SchemaGenerator::render(Macroable::class, fileSource())['properties'];

    expect(array_keys($properties))->toBe(['macro', 'mixin', 'hasMacro']); // flushMacros is skipped, __* filtered
});

const ROUTER_KEYS = [ // the 65 native method names in getMethods() order (§1.9)
    'get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'fallback', 'redirect',
    'permanentRedirect', 'view', 'match', 'resources', 'softDeletableResources', 'resource',
    'apiResources', 'apiResource', 'singletons', 'singleton', 'apiSingletons', 'apiSingleton',
    'group', 'mergeWithLastGroup', 'addRoute', 'newRoute', 'respondWithRoute', 'dispatch',
    'dispatchToRoute', 'gatherRouteMiddleware', 'resolveMiddleware', 'prepareResponse',
    'toResponse', 'substituteBindings', 'substituteImplicitBindings',
    'substituteImplicitBindingsUsing', 'matched', 'aliasMiddleware', 'hasMiddlewareGroup',
    'middlewareGroup', 'prependMiddlewareToGroup', 'pushMiddlewareToGroup',
    'removeMiddlewareFromGroup', 'bind', 'model', 'getBindingCallback', 'pattern', 'patterns',
    'input', 'has', 'is', 'currentRouteNamed', 'uses', 'currentRouteUses',
    'singularResourceParameters', 'resourceParameters', 'resourceVerbs', 'setRoutes',
    'setCompiledRoutes', 'uniqueMiddleware', 'setContainer', 'macro', 'mixin', 'hasMacro',
    'macroCall', 'tap',
];

it('projects every declarable native method in declaration order', function (): void {
    $fragment = SchemaGenerator::render(Router::class, fileSource());

    expect(array_keys($fragment['properties']))->toBe(ROUTER_KEYS)
        ->and($fragment['description'])
        ->toBe('Illuminate\Routing\Router methods: every key is a method name, its value the argument(s).')
        ->and($fragment['type'])->toBe(['object', 'null'])
        ->and($fragment['additionalProperties'])->toBeFalse();
});

it('reports the zero-parameter native methods', function (): void {
    expect(SchemaGenerator::skipped(Router::class, fileSource()))->toBe([
        'getLastGroupPrefix', 'getMiddleware', 'getMiddlewareGroups', 'flushMiddlewareGroups',
        'getPatterns', 'hasGroupStack', 'getGroupStack', 'getCurrentRequest', 'getCurrentRoute',
        'current', 'currentRouteName', 'currentRouteAction', 'getRoutes', 'flushMacros',
    ]);
});

it('derives binding keys for two-param scalar-first native methods', function (): void {
    expect(SchemaGenerator::render(Router::class, fileSource())['properties']['bind'])->toBe([
        'description' => '-> bind($key, $binder), one call per entry TODO(bind: $binder)',
        'type' => 'object',
        'additionalProperties' => true,
    ])
        ->and(SchemaGenerator::render(Router::class, fileSource())['properties']['middlewareGroup'])->toBe([
            'description' => '-> middlewareGroup($name, $middleware), one call per entry',
            'type' => 'object',
            'additionalProperties' => ['type' => ['array', 'object']],
        ]);
});

it('derives setter keys for arity-1 native methods', function (): void {
    expect(SchemaGenerator::render(Router::class, fileSource())['properties']['matched'])->toBe([
        'description' => '-> matched($callback) when the key is present TODO(matched: $callback)',
    ])
        ->and(SchemaGenerator::render(Router::class, fileSource())['properties']['resourceVerbs'])->toBe([
            'description' => '-> resourceVerbs($verbs), one call with the whole value',
            'type' => ['array', 'object'],
        ]);
});

it('derives append keys for variadic-only native methods', function (): void {
    expect(SchemaGenerator::render(Router::class, fileSource())['properties']['is'])->toBe([
        'description' => '-> is($patterns), one call per item TODO(is: $patterns)',
        'type' => 'array',
        'items' => true,
    ]);
});

it('stays undecided for arities no kind row covers', function (): void {
    expect(SchemaGenerator::render(Router::class, fileSource())['properties']['model'])->toBe([
        'description' => '-> model($key, $class, $callback) when the key is present TODO(model: $key, $class, $callback)',
    ])
        ->and(SchemaGenerator::render(Router::class, fileSource())['properties']['view'])->toBe([
            'description' => '-> view($uri, $view, $data, $status, $headers) when the key is present TODO(view: $uri, $view, $data, $status, $headers)',
        ]);
});

it('stubs every key with the exact native call', function (): void {
    foreach (SchemaGenerator::render(Router::class, fileSource())['properties'] as $key => $keySchema) {
        expect($keySchema['description'])->toStartWith("-> $key("); // Rule 9.6
    }
});
