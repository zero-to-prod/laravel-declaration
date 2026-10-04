---
name: signature-shapes
task: >-
  Complete the kind defaults (§4.2): binding, append-to, append — multi-param and
  variadic methods project to their runtime forwarding shapes.
plan: docs/declarative-schema-generator.md §4.2, §4.4, §4.5
depends_on: 01-projection-skeleton.md
delivers:
  - SchemaGenerator::key() precedence chain (append, append-to, binding rows before setter/fallback)
  - scalarKey(), stringArrayUnion(), binding(), appendTo(), append() helpers
---

# Unit 02 — Signature Shapes

## Vertical slice

After this unit, `render()` classifies every derivable signature shape: a 2-param scalar-first method becomes a `binding` map, a `string|array` second param becomes an `append-to` scalar-or-list map, a variadic-only method becomes an `append` list — each with the runtime call shape baked into the schema, exactly as the provider loops forward values. Undecidable signatures stay loudly undecided.

## Spec carried by this unit

### Kind defaults (§4.2) — deterministic, from the dispatch loops

The runtime meaning of each kind is fixed by `RouterDeclarationServiceProvider::boot()` / `KernelDeclarationServiceProvider::boot()`:

| Kind | Runtime call (provider loop) | Fired when |
| --- | --- | --- |
| `append` | one call per list item | **variadic-only** signature (the single param is variadic) |
| `append-to` | per-item calls in declaration order (hand-correctable to `prepend-to`, which reverses) | exactly 2 params, first scalar-ish, second declared **`string\|array` union** |
| `binding` | `foreach ($Manifest->block->$method as $key => $value) $X->$method($key, $value);` | exactly 2 params, first scalar-ish, second any |
| `setter` | `if ($value !== null) $X->$method($value);` | exactly 1 non-variadic param (unit 01) |
| undecided | invent nothing; `TODO(<method>: <all params>)` | no row matches (completing rule, unit 01) |

**Scalar-ish first param** (`scalarKey()`): declared `string`, `int`, a union of those, or **untyped**. Untyped counts because the plan's own row-1 examples (`bind($key, $binder)`, `pattern($key, $pattern)`) have untyped first params and §6.1 shows them generating binding shapes. A `float`/`bool` first param is *not* a manifest key type (PHP array keys are `int|string`) — such a signature falls through to undecided, which is also semantically right.

**Precedence** — the `append-to` row is a specialization of the binding shape (both are 2-param scalar-first), so it must be tested *before* the binding row, or "second any" would swallow it. Full chain, evaluated top-down:

1. variadic-only → `append`
2. exactly 1 non-variadic param → `setter`
3. exactly 2 params, first scalar-ish, second `string|array` union → `append-to`
4. exactly 2 params, first scalar-ish → `binding`
5. else → undecided

**Strictness of the `string|array` test** (`stringArrayUnion()`): the second param's type is a `ReflectionUnionType` whose members are exactly the named types `string` and `array` (no `null` member, no others). Nullable or wider unions fall through to binding — loud, hand-correctable. A trailing variadic second param (`($a, string ...$rest)`) also falls through to binding; its value schema is the variadic's `{"type":"array","items":…}` form (§4.3), which is visibly wrong for per-entry forwarding and cheap to hand-correct — the plan's "wrong default is loud and cheap" philosophy (§3).

**`prepend-to` is never generated.** Reversal order is a semantic, not a signature property (§3: `prependMiddlewareToGroup` and `pushMiddlewareToGroup` share their signature shape). The generated stub says `one call per item` — the `, reversed` prose from §4.5 is *curation* added after the hand correction. Regeneration must never re-invent it (unit 04).

### Key schemas (§4.4)

| Kind | Generated JSON Schema |
| --- | --- |
| `binding` | `{"type":"object","additionalProperties":<param-2 schema>}` (one call per entry; entry key is the first param) |
| `append` | `{"type":"array","items":<param-1 schema>}` (one call per item) |
| `append-to` | `{"type":"object","additionalProperties":{"anyOf":[{"type":"string"},{"type":"array"}]}}` — the existing `anyOf: [string, array-of-string]` shape; `items` are hand-curated later (§6.1 precedent) |

### Stub descriptions (§4.5)

| Kind | Suffix |
| --- | --- |
| `binding` | `, one call per entry` |
| `append` / `append-to` | `, one call per item` |

`TODO(<method>: $<param>)` marks only params whose type surfaces in the **value** schema (param-2 for `binding`/`append-to`, param-1 for `append`); the entry key (param-1 of `binding`) never appears — §6.1's `-> bind($key, $binder), one call per entry TODO(bind: $binder)`.

## Implementation

Changes to `src/Internal/SchemaGenerator.php` — replace unit 01's `key()` and add the helpers below:

```php
    /**
     * §4.2 precedence chain, evaluated top-down. Rows 1 and 5 are unit 01's;
     * this unit inserts the append/append-to/binding rows in between.
     *
     * @return array<string, mixed>
     */
    private static function key(ReflectionMethod $method): array
    {
        $parameters = $method->getParameters();

        if (count($parameters) === 1 && $parameters[0]->isVariadic()) {
            return self::append($method, $parameters[0]); // §4.2 row: variadic-only
        }

        if (count($parameters) === 1) {
            return self::setter($method, $parameters[0]); // §4.2 row: exactly 1 non-variadic param
        }

        if (count($parameters) === 2 && self::scalarKey($parameters[0]) && self::stringArrayUnion($parameters[1]->getType())) {
            return self::appendTo($method, $parameters[0], $parameters[1]); // §4.2 row: (scalar, string|array)
        }

        if (count($parameters) === 2 && self::scalarKey($parameters[0])) {
            return self::binding($method, $parameters[0], $parameters[1]); // §4.2 row: (scalar, any)
        }

        return self::undecided($method);
    }

    /**
     * A manifest key type: declared string, int, a union of those — or untyped
     * (unprovable; §6.1's row-1 examples are untyped). PHP array keys are
     * int|string, so float/bool first params cannot be entry keys.
     */
    private static function scalarKey(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        if ($type === null) {
            return true;
        }

        $members = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

        return array_every($members, static fn (ReflectionType $type): bool => $type instanceof ReflectionNamedType
            && ! $type->allowsNull()
            && in_array($type->getName(), ['string', 'int'], true));
    }

    /** §4.2: second param declared exactly `string|array` — no null, no others. */
    private static function stringArrayUnion(?ReflectionType $type): bool
    {
        if (! $type instanceof ReflectionUnionType) {
            return false;
        }

        $names = array_map(static fn (ReflectionType $type): string => $type instanceof ReflectionNamedType ? $type->getName() : '', $type->getTypes());

        sort($names);

        return $names === ['array', 'string'];
    }

    /** §4.4 binding: one call per entry; entry key is the first param. @return array<string, mixed> */
    private static function binding(ReflectionMethod $method, ReflectionParameter $key, ReflectionParameter $value): array
    {
        [$schema, $unknown] = self::paramSchema($value);

        $additionalProperties = $schema === true ? true : ['additionalProperties' => $schema];

        return self::withDescription(
            ['type' => 'object', ...$additionalProperties],
            self::stub($method, [$key->getName(), $value->getName()], ', one call per entry', $unknown),
        );
    }

    /** §4.4 append-to: per-item calls in declaration order. @return array<string, mixed> */
    private static function appendTo(ReflectionMethod $method, ReflectionParameter $key, ReflectionParameter $value): array
    {
        return self::withDescription(
            [
                'type' => 'object',
                'additionalProperties' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'array'], // items hand-curated later (§6.1 precedent)
                    ],
                ],
            ],
            self::stub($method, [$key->getName(), $value->getName()], ', one call per item', []),
        );
    }

    /** §4.4 append: one call per list item. @return array<string, mixed> */
    private static function append(ReflectionMethod $method, ReflectionParameter $parameter): array
    {
        [$schema, $unknown] = self::paramSchema($parameter);

        return self::withDescription(
            ['type' => 'array', 'items' => $schema],
            self::stub($method, [$parameter->getName()], ', one call per item', $unknown),
        );
    }
```

Imports added to the class header: `ReflectionType`, `ReflectionUnionType`.

> `binding()` reuses `withDescription()` from unit 01. When the value schema is `true`, `additionalProperties` carries the bare `true` (no description can attach to it — §6.1's `bind` shape) and the `TODO` lives in the key's `description`.

> Unit 01's `paramSchema()` is untouched here: a union type still resolves to `true` + unknown until unit 03 completes the union rules. That is why this unit's fixture avoids union value types other than the strict `string|array` append-to test.

## Fixture — `tests/Fixtures/SchemaGenerator/Kinds.php`

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator;

use Closure;

final class Kinds
{
    public function group(string $name, string $middleware): void {}

    public function middleware(string $group, string|array $middleware): void {}

    public function bind($key, $binder): void {}

    public function handlers(string ...$handlers): void {}

    public function ratio(float $rate, string $value): void {}

    public function register(string $name, string $class, ?Closure $callback = null): void {}
}
```

Expected classification: `group` binding; `middleware` append-to; `bind` binding with unknown value (`$binder` untyped); `handlers` append; `ratio` undecided (float cannot be an entry key); `register` undecided (3 params). All six classifications are final — no later unit changes them.

## Tests — added to `tests/Feature/SchemaGeneratorTest.php`

```php
<?php

use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator\Kinds;

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
```

## Acceptance checklist

- [ ] Every §4.2 row fires exactly per its signature shape; precedence is proven (`middleware` would have been `binding` without the ordering).
- [ ] Undecided keys name the native call and list every undecided param (§3 — loud, cheap to fix).
- [ ] `prepend-to` is never generated; its `reversed` prose is curation only (§3, §4.5).
- [ ] Unit 01's tests still pass unchanged (layering rule).
- [ ] `composer check` passes (100% coverage of the new rows, both `scalarKey` branches, `stringArrayUnion` false paths).

## Sources

- Plan: [docs/declarative-schema-generator.md](../../declarative-schema-generator.md) §4.2, §4.4, §4.5, §3 (non-derivable kinds), §6.1 (worked fragment).
- [src/Providers/RouterDeclarationServiceProvider.php](../../../src/Providers/RouterDeclarationServiceProvider.php), [src/Providers/KernelDeclarationServiceProvider.php](../../../src/Providers/KernelDeclarationServiceProvider.php) — the five loops defining each kind's runtime call; basis of the defaults table.
- [src/Router.php](../../../src/Router.php) — `matched` is `Append` while `resourceVerbs` is `Setter` at identical arity-1 shapes: proof that only the rows above are derivable and everything else is curation.
- [manifest.schema.json](../../../manifest.schema.json) `definitions.router` — the curated `anyOf`/`items`/`pattern` shapes the merge must preserve.