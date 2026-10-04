---
name: acceptance-router
task: >-
  Prove the single algorithm end-to-end against the real vendor class:
  Illuminate\Routing\Router renders, merges into a copy of the shipped schema,
  preserves curation, and is idempotent.
plan: docs/declarative-schema-generator.md §2 (vendor target), §6 (worked example), §7.5, §9 (acceptance)
depends_on: 01-projection-skeleton.md … 05-command-write-path.md
delivers:
  - tests/Feature/SchemaGeneratorTest.php router acceptance tests
  - the §6.1 → algorithm delta table (recorded, not coded around)
---

# Unit 06 — Acceptance: `Illuminate\Routing\Router`

## Vertical slice

The whole algorithm, run against the real reflection target: `SchemaGenerator::render(\Illuminate\Routing\Router::class, 'router')` produces the `definitions.router` structure; merging it into a copy of the shipped `manifest.schema.json` appends only missing keys while every curated byte survives; a second run changes nothing; the shipped file itself is never touched by tests.

## The vendor signature facts (verified)

`vendor/laravel/framework/src/Illuminate/Routing/Router.php` reflection, §4.1 filter applied:

- **65 declarable methods** (public, declaring-class-owned, non-`@internal`, non-`__`, ≥1 param, no by-ref).
- **14 skipped** (zero parameters): `getLastGroupPrefix`, `getMiddleware`, `getMiddlewareGroups`, `flushMiddlewareGroups`, `getPatterns`, `hasGroupStack`, `getGroupStack`, `getCurrentRequest`, `getCurrentRoute`, `current`, `currentRouteName`, `currentRouteAction`, `getRoutes`, `flushMacros`.
- Almost all params are **untyped** — docblock `@param` strings exist but §4.3 forbids parsing them (Rule 2.5). Only `middlewareGroup`'s `$middleware` (`array`), `resourceParameters`' `$parameters` (`array`), `resourceVerbs`' `$verbs` (`array`), `model`'s `$callback` (`?Closure`), `setRoutes`' `$routes` (`RouteCollection`), and the variadics `is`/`uses` (`...$patterns`) carry native types.

### §6.1 → algorithm delta table

Plan §6.1 is an illustration written assuming docblock types; §4.2/§4.3 are the algorithm. The real fragment differs where the signature is untyped:

| Key | §6.1 shows | Algorithm produces (verified signature) |
| --- | --- | --- |
| `pattern($key, $pattern)` | `additionalProperties: {"type":"string"}` | binding, `additionalProperties: true`, `TODO(pattern: $pattern)` — `$pattern` is untyped |
| `bind($key, $binder)` | `additionalProperties: true`, `TODO(bind: $binder)` | identical ✓ |
| `middlewareGroup($name, array $middleware)` | `{"type":"array"}` | binding, `additionalProperties: {"type":["array","object"]}` (§4.3 array row; curation narrows with `items`) |
| `pushMiddlewareToGroup($group, $middleware)` | `anyOf: [string, array]` | binding, `additionalProperties: true`, `TODO(pushMiddlewareToGroup: $middleware)` — `$middleware` is untyped, so the `string\|array` append-to row cannot fire |
| `singularResourceParameters($singular)` | `{"type":"boolean"}` | setter, description-only, `TODO(singularResourceParameters: $singular)` — `$singular` is untyped |
| `matched($callback)` | `{"type":"string"}`, setter default | setter, description-only, `TODO(matched: $callback)` — untyped; hand-corrected to `Append` by curation |
| `model($key, $class, ?Closure $callback = null)` | not shown | **undecided** — 3 params match no §4.2 row; `TODO(model: $key, $class, $callback)` |
| `prependMiddlewareToGroup($group, $middleware)` | append-to shape | binding, `TODO(prependMiddlewareToGroup: $middleware)` — order semantics are not derivable (§3); curation corrects to `PrependTo` |

This is the honest output of the single algorithm; curation (merge) then layers the hand-authored refinements on top.

## Tests — added to `tests/Feature/SchemaGeneratorTest.php`

### 1. Golden key set and order (§4.1 filter + Rule 8.1 declaration order)

```php
<?php

use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;

const ROUTER_KEYS = [ /* the 65 native method names in getMethods() order */
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

function routerFragment(): array
{
    return SchemaGenerator::render(\Illuminate\Routing\Router::class, 'router');
}

it('projects every declarable native method in declaration order', function (): void {
    $fragment = routerFragment();

    expect(array_keys($fragment['properties']))->toBe(ROUTER_KEYS)
        ->and($fragment['description'])
        ->toBe('Illuminate\Routing\Router methods: every key is a method name, its value the argument(s).')
        ->and($fragment['type'])->toBe(['object', 'null'])
        ->and($fragment['additionalProperties'])->toBeFalse();
});

it('reports the zero-parameter native methods', function (): void {
    expect(SchemaGenerator::skipped(\Illuminate\Routing\Router::class))->toBe([
        'getLastGroupPrefix', 'getMiddleware', 'getMiddlewareGroups', 'flushMiddlewareGroups',
        'getPatterns', 'hasGroupStack', 'getGroupStack', 'getCurrentRequest', 'getCurrentRoute',
        'current', 'currentRouteName', 'currentRouteAction', 'getRoutes', 'flushMacros',
    ]);
});
```

### 2. Per-key shape spot checks (one per §4.2/§4.3 row that appears in the real class)

```php
it('derives binding keys for two-param scalar-first native methods', function (): void {
    expect(routerFragment()['properties']['bind'])->toBe([
        'description' => '-> bind($key, $binder), one call per entry TODO(bind: $binder)',
        'type' => 'object',
        'additionalProperties' => true,
    ])
        ->and(routerFragment()['properties']['middlewareGroup'])->toBe([
            'description' => '-> middlewareGroup($name, $middleware), one call per entry',
            'type' => 'object',
            'additionalProperties' => ['type' => ['array', 'object']],
        ]);
});

it('derives setter keys for arity-1 native methods', function (): void {
    expect(routerFragment()['properties']['matched'])->toBe([
        'description' => '-> matched($callback) when the key is present TODO(matched: $callback)',
    ])
        ->and(routerFragment()['properties']['resourceVerbs'])->toBe([
            'description' => '-> resourceVerbs($verbs), one call with the whole value',
            'type' => ['array', 'object'],
        ]);
});

it('derives append keys for variadic-only native methods', function (): void {
    expect(routerFragment()['properties']['is'])->toBe([
        'description' => '-> is($patterns), one call per item TODO(is: $patterns)',
        'type' => 'array',
        'items' => true,
    ]);
});

it('stays undecided for arities no kind row covers', function (): void {
    expect(routerFragment()['properties']['model'])->toBe([
        'description' => '-> model($key, $class, $callback) when the key is present TODO(model: $key, $class, $callback)',
    ])
        ->and(routerFragment()['properties']['view'])->toBe([
            'description' => '-> view($uri, $view, $data, $status, $headers) when the key is present TODO(view: $uri, $view, $data, $status, $headers)',
        ]);
});

it('stubs every key with the exact native call', function (): void {
    foreach (routerFragment()['properties'] as $key => $keySchema) {
        expect($keySchema['description'])->toStartWith("-> $key("); // Rule 9.6
    }
});
```

### 3. Merge round trip against the shipped artifact

```php
it('merges into a copy of the shipped schema without losing curation', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'schema-').'.json';
    file_put_contents($path, file_get_contents(dirname(__DIR__, 2).'/manifest.schema.json'));

    $before = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    $this->artisan('declaration:generate-schema', [
        'class' => \Illuminate\Routing\Router::class,
        '--out' => $path,
    ])
        ->expectsOutputToContain('Block: router')
        ->expectsOutputToContain('Added [53]') // 65 native − 12 curated
        ->assertSuccessful()
        ->run();

    $after = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect($after['definitions']['router']['properties'])->toHaveCount(65)
        // curated envelope untouched
        ->and($after['definitions']['router']['description'])->toBe($before['definitions']['router']['description'])
        // every curated key object byte-equal (prose, pattern, items, kind corrections)
        ->and($after['definitions']['router']['properties']['pattern'])->toBe($before['definitions']['router']['properties']['pattern'])
        ->and($after['definitions']['router']['properties']['bind'])->toBe($before['definitions']['router']['properties']['bind'])
        ->and($after['definitions']['router']['properties']['matched'])->toBe($before['definitions']['router']['properties']['matched'])
        // appended keys carry the generated structure
        ->and($after['definitions']['router']['properties']['patterns']['description'])
        ->toBe('-> patterns($patterns) when the key is present TODO(patterns: $patterns)');
});

it('is idempotent against the shipped artifact', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'schema-').'.json';
    file_put_contents($path, file_get_contents(dirname(__DIR__, 2).'/manifest.schema.json'));

    $this->artisan('declaration:generate-schema', ['class' => \Illuminate\Routing\Router::class, '--out' => $path])->assertSuccessful()->run();
    $afterFirst = file_get_contents($path);

    $this->artisan('declaration:generate-schema', ['class' => \Illuminate\Routing\Router::class, '--out' => $path])
        ->expectsOutputToContain('Added [0]')
        ->assertSuccessful()
        ->run();

    expect(file_get_contents($path))->toBe($afterFirst);
});

it('never touches the shipped schema file', function (): void {
    $repoSchema = dirname(__DIR__, 2).'/manifest.schema.json';
    $hashBefore = hash_file('sha256', $repoSchema);

    $path = tempnam(sys_get_temp_dir(), 'schema-').'.json';
    file_put_contents($path, file_get_contents($repoSchema));

    $this->artisan('declaration:generate-schema', ['class' => \Illuminate\Routing\Router::class, '--out' => $path])->assertSuccessful()->run();

    expect(hash_file('sha256', $repoSchema))->toBe($hashBefore); // the generator only edits the file it is pointed at
});
```

### 4. Optional byte lock

For full byte-exactness, commit the algorithm's own output as a golden fixture on first run (`tests/Fixtures/SchemaGenerator/router-fragment.golden.json`, produced by `SchemaGenerator::encode(routerFragment())`) and assert byte equality thereafter. This locks the whole 65-key fragment against regressions; regenerate the golden file only when the derivation rules deliberately change.

## Acceptance checklist (plan §9, verified against the real artifact)

- [x] Every generated key matches a **native method** name character-for-character (Rule 1) — 65/65.
- [x] Types/order/optionality come from the native signature; docblocks never drive structure (Rule 2) — the delta table shows untyped params producing honest `TODO`s, not docblock-derived types.
- [x] The generator invents nothing: unknown types are `true`/description-only + `TODO`; skipped methods are reported (Rules 1.4, 3).
- [x] Regeneration never loses curated `description` prose, `pattern`s, `items`, or kind corrections.
- [x] Output is deterministic and idempotent in the repo's 2-space JSON style; `$schema` header and unrelated definitions untouched; the file path is stable.
- [x] Stateless and `@internal`; pure `render()` core, I/O only in the command (Rule 4).
- [x] `composer check` passes.

## Sources

- Plan: [docs/declarative-schema-generator.md](../../declarative-schema-generator.md) §2 (vendor reflection target), §6 (worked example — illustrative, see delta table), §7 (test plan), §9 (acceptance checklist).
- `vendor/laravel/framework/src/Illuminate/Routing/Router.php` — the 65 declarable + 14 skipped methods and every signature cited above (verified by reflection).
- `vendor/laravel/framework/src/Illuminate/Support/Traits/Macroable.php` — `macro($name, $macro)` projects (declaring class is the using class).
- [manifest.schema.json](../../../manifest.schema.json) `definitions.router` — the 12 curated keys and prose the merge must preserve; `patterns` is native but uncurated, hence `Added [53]`.
- [src/Providers/RouterDeclarationServiceProvider.php](../../../src/Providers/RouterDeclarationServiceProvider.php) — the `PrependTo`/`AppendTo`/`Append` corrections that curation applies by hand (order semantics are not signature-derivable, §3).