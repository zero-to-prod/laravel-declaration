---
name: value-types
task: >-
  Complete the PHP type → JSON Schema map (§4.3): array, unions, nullability,
  and the honest unknown path for everything reflection cannot type.
plan: docs/declarative-schema-generator.md §4.3, §4.4 (variadic items), §4.5 (setter suffix)
depends_on: 01-projection-skeleton.md (02 not required but expected)
delivers:
  - completed SchemaGenerator::paramSchema()
  - array-typed setter stub suffix ("one call with the whole value")
---

# Unit 03 — Value Types

## Vertical slice

After this unit, every PHP type a native signature can declare maps deterministically to a lossless JSON Schema — or, where the signature cannot be read (classes, `mixed`, `iterable`, …), to an honest `true` + `TODO`. Setter keys whose value is array-shaped describe the whole-value call.

## Spec carried by this unit

### Type map (§4.3) — the complete table

Types come from the native signature only; **docblock `@param` tags are not parsed** (Rule 2.5 — the signature is the machine-readable truth, docblocks are prose).

| Native type | Generated JSON Schema |
| --- | --- |
| `string` | `{"type":"string"}` |
| `int` | `{"type":"integer"}` |
| `float` | `{"type":"number"}` |
| `bool` | `{"type":"boolean"}` |
| `array` | `{"type":["array","object"]}` — a PHP array is both list and map; curated fragments narrow this (`items`, `additionalProperties`) |
| `?T` (nullable) | `{"type":[ …T…, "null"]}` — `null` last |
| union `A\|B` of scalars | `{"type":["<A>","<B>"]}` — declared order, deduplicated |
| union containing `array` | members expanded **in place** into the type array: `string\|array` → `{"type":["string","array","object"]}` |
| union containing an untypeable member (class/enum/`Closure`/`mixed`/`iterable`/untyped/intersection) | the whole param is unknown → `true` + `TODO` (one untypeable member makes the value untypeable; the generator refuses to invent) |
| `T ...$x` (variadic) | value form is `{"type":"array","items":<T-schema>}` — emitted by unit 02's `append` row; as a non-append param the type map applies to the param type itself |
| class/interface/enum/`Closure`/`mixed`/`iterable`/`callable`/untyped/intersection | `true` (any) + ` TODO(<method>: $<param>)` appended to the key's stub description |

Deterministic ordering rule: build the type array in **declared member order**, expanding an `array` member in place to `["array","object"]`, deduplicating, and appending `"null"` last (§4.3's `?T` row shows `"null"` last). Examples: `string|int` → `["string","integer"]`; `string|int|null` → `["string","integer","null"]`; `?array` → `["array","object","null"]`.

Native parameter **defaults are not emitted** as schema `default` (§4.3, Rule 6.1–6.2): every manifest key is opt-in; a native default like `singularResourceParameters($singular = true)` is reachable only as "don't call at all", which curation records in prose (`"absent key -> Laravel default (true)"`).

### Setter stub suffix (§4.5) — completed

The plan's stubs distinguish two setter phrasings (§4.5/§6.1):

- `-> resourceVerbs($verbs), one call with the whole value` — value type array contains `"array"`;
- `-> singularResourceParameters($singular) when the key is present` — otherwise (scalars and unknown values).

Rule: `str_contains(json_encode($typeArray), '"array"') ? ', one call with the whole value' : ' when the key is present'` — applied in `setter()` only; `binding`/`append`/`append-to` suffixes are unchanged (unit 02).

## Implementation

Replace unit 01's `paramSchema()` with the completed map and adjust `setter()`'s suffix:

```php
    /**
     * §4.3 value schema for one parameter — the complete map.
     * Returns [schema, unknown-param-names].
     *
     * @return array{0: mixed, 1: list<string>}
     */
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
                    $types[] = 'null'; // §4.3 ?T row: null last
                }

                return [['type' => array_values(array_unique($types))], []];
            }
        }

        // class/interface/enum/Closure/mixed/iterable/callable/untyped/intersection —
        // the generator refuses to invent semantics it cannot read from the
        // signature (§4.3, Rules 1.4, 3.4).
        return [true, [$parameter->getName()]];
    }

    /**
     * Union rule: untypeable member → whole param unknown (§4.3); otherwise
     * declared order, `array` expanded in place, deduplicated, null last.
     *
     * @return array{0: mixed, 1: list<string>}
     */
    private static function unionSchema(ReflectionUnionType $type, string $name): array
    {
        $types = [];
        $nullable = false;

        foreach ($type->getTypes() as $member) {
            if (! $member instanceof ReflectionNamedType) {
                return [true, [$name]]; // intersection member inside a union — unreadable
            }

            if ($member->getName() === 'null') {
                $nullable = true;

                continue;
            }

            $expanded = self::expand($member->getName());

            if ($expanded === null) {
                return [true, [$name]]; // one untypeable member makes the value untypeable
            }

            $types = [...$types, ...$expanded];
        }

        if ($nullable) {
            $types[] = 'null';
        }

        return [['type' => array_values(array_unique($types))], []];
    }

    /**
     * §4.3 primitive rows. `null` when the type is not expressible from the
     * signature alone (the unknown path).
     *
     * @return list<string>|null
     */
    private static function expand(string $name): ?array
    {
        return match ($name) {
            'string' => ['string'],
            'int' => ['integer'],
            'float' => ['number'],
            'bool' => ['boolean'],
            'array' => ['array', 'object'], // a PHP array is both list and map
            default => null, // mixed, iterable, callable, classes, …
        };
    }
```

In `setter()` (unit 01), derive the suffix from the resolved schema:

```php
    private static function setter(ReflectionMethod $method, ReflectionParameter $parameter): array
    {
        [$schema, $unknown] = self::paramSchema($parameter);

        $suffix = is_array($schema) && str_contains(json_encode($schema, JSON_THROW_ON_ERROR), '"array"')
            ? ', one call with the whole value' // §4.5: resourceVerbs form
            : ' when the key is present';       // §4.5: singularResourceParameters form

        return self::withDescription($schema, self::stub($method, [$parameter->getName()], $suffix, $unknown));
    }
```

Imports added: `ReflectionUnionType` (ReflectionType/ReflectionNamedType already present).

> `withDescription()` (unit 01) already collapses a `true` value schema into a description-only property object; `binding`/`append` keep bare `true` at value positions (`additionalProperties`, `items`).

## Fixtures — `tests/Fixtures/SchemaGenerator/`

`Suit.php` — a class-typed param source:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator;

enum Suit: string
{
    case Hearts = 'hearts';
}
```

`Types.php` — one method per §4.3 row:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator;

use Closure;

final class Types
{
    public function verbs(array $verbs): void {}

    public function scale(string|int $scale): void {}

    public function ratio(float $ratio): void {}

    public function enabled(bool $enabled): void {}

    public function pair(string|array $pair): void {}

    public function maybe(string|int|null $maybe): void {}

    public function optional(?array $optional): void {}

    public function sink(mixed $sink): void {}

    public function target(?Suit $suit): void {}

    public function callback(Closure $callback): void {}

    public function flow(iterable $items): void {}
}
```

All eleven classifications are final (declared member order in `scale`/`pair`/`maybe` is what the tests pin).

## Tests — added to `tests/Feature/SchemaGeneratorTest.php`

```php
<?php

use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator\Types;

function typeProperty(string $key): mixed
{
    return SchemaGenerator::render(Types::class, 'types')['properties'][$key];
}

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
```

## Acceptance checklist

- [ ] Every §4.3 table row is exercised by a fixture method and pinned by an assertion.
- [ ] Declared-order/dedup/`null`-last behavior is pinned (`scale`, `maybe`, `optional`).
- [ ] Untypeable types never invent structure — description-only + `TODO` (Rules 1.4, 3.4).
- [ ] Units 01–02 tests still pass unchanged (scalars and `string|array` append-to members behave identically under the completed map).
- [ ] `composer check` passes (every `expand`/`unionSchema` branch covered).

## Sources

- Plan: [docs/declarative-schema-generator.md](../../declarative-schema-generator.md) §4.3 (complete type map + no-defaults rule), §4.5 (stub suffixes), §6.1 (`middlewareGroup` `items` left to curation).
- [STYLE.md](../../../STYLE.md) Rule 2.5 (types from the native method), Rule 2.7 (never default arguments), Rule 3.4 (no validation/opinions).
- [manifest.schema.json](../../../manifest.schema.json) `definitions.router` — curation narrows `{"type":["array","object"]}` to `{"type":"array","items":{"type":"string"}}` (`middlewareGroup`), proving the generated form is the lossless superset.