---
name: projection-skeleton
task: >-
  Deliver the first vertical slice: artisan command → reflection → schema fragment
  on stdout, for classes whose methods are simple setters.
plan: docs/declarative-schema-generator.md §4.1, §4.2 (setter row + fallback), §4.3 (scalars), §4.4 (setter), §4.5, §4.6, §5.2 (print mode)
depends_on: none
delivers:
  - src/Internal/SchemaGenerator.php (render core: filter, envelope, setter keys, scalar value schemas, skipped())
  - src/Internal/Commands/GenerateSchemaCommand.php (print mode)
  - registration in src/LaravelDeclarationProvider.php
---

# Unit 01 — Projection Skeleton

## Vertical slice

`php artisan declaration:generate-schema 'Some\Native\Class'` prints the `definitions.<block>` fragment for a class whose methods are simple setters — derived end-to-end from reflection, nothing invented, nothing silently dropped.

- Unknown class → native `ReflectionException` (Rule 3.3).
- Undeclarable methods (zero params, by-reference params) are reported under `Skipped:`, never silently dropped (§4.1).
- The derived block key is printed in the output header so a wrong derivation is visible immediately (§4.6).

## Spec carried by this unit

### Method filter (§4.1) — identical rules to `Api::classes()`/`Api::methods()`

A native method becomes a manifest key iff all of these hold; `getMethods()` preserves native declaration order, which becomes the fragment's key order (Rule 8.1, vertical alignment with `src/Router.php`):

1. method is **public**;
2. `$method->getDeclaringClass()->getName() === $class` — declaring-class-owned;
3. docblock does not contain `@internal`;
4. name does not start with `__` (a manifest cannot construct);
5. **declarable**: at least one parameter and no by-reference parameter (STYLE 2.1 — a YAML scalar cannot be a PHP reference). Violations of rule 5 land in the skipped report, not in silence.

> Verified PHP semantics (evidence for the overview's reconciliation 1): rules 2 excludes **parent-class** methods (`Application::bind` declares `Illuminate\Container\Container`) but **includes trait-provided** methods (`Router::macro` declares `Illuminate\Routing\Router`), because `getDeclaringClass()` reports the using class for trait imports. The filter is implemented exactly as stated; fixtures prove both behaviors.

### Block key (§4.6)

`lcfirst(<class basename>)` — `Illuminate\Routing\Router` → `router`, `Illuminate\Foundation\Application` → `application`. Matches the `src/Manifest.php` property names. Alias blocks (`routes`) stay hand-written; the derived key is printed in the command header.

### Envelope (§6.1)

```json
{
  "description": "<class FQCN> methods: every key is a method name, its value the argument(s).",
  "type": ["object", "null"],
  "additionalProperties": false,
  "properties": { }
}
```

`"object", "null"` because a manifest block is opt-in (`src/Manifest.php` declares `?Router $router`, Rule 6.1). `additionalProperties: false` because an unknown key throws at runtime. This envelope text is a **stub** — curation may replace it and merge must keep the curated one (unit 04).

### Kind default in this unit (§4.2, one row)

- **exactly 1 non-variadic param → `setter`**: runtime call `if ($value !== null) $X->$method($value);` (provider loop). Key schema = the param's value schema (§4.4).
- **everything else → undecided** (completing rule; see [00-overview.md](00-overview.md) reconciliation 5): description-only schema + `TODO(<method>: <every param>)`. Later units insert the `append`/`append-to`/`binding` rows *before* the fallback in the precedence chain; a 3-param method stays undecided forever, which is what keeps unit 01's fixture stable.

### Value schemas in this unit (§4.3, partial — completed in unit 03)

| Native param type | Generated JSON Schema |
| --- | --- |
| `string` | `{"type":"string"}` |
| `int` | `{"type":"integer"}` |
| `float` | `{"type":"number"}` |
| `bool` | `{"type":"boolean"}` |
| `?T` (nullable scalar) | `{"type":["<T>","null"]}` |
| untyped / class / interface / enum / `Closure` / `mixed` / anything else | `true` at *value* position; at *property* position the key object carries only its `description` (a JSON Schema `true` cannot carry siblings) + ` TODO(<method>: $<param>)` appended to the stub description |

Native parameter **defaults are not emitted** (§4.3, Rule 6.1–6.2): every manifest key is opt-in; a native default is reachable only as "don't call at all", which curation records in prose.

### Stub description (§4.5)

- params rendered as `$name`, comma-separated — no types, no defaults, no `&`, no `...` (§6.1 stubs show `-> singularResourceParameters($singular)` for `singularResourceParameters($singular = true)`).
- setter suffix: ` when the key is present` (Rule 6.1 opt-in phrasing).
- `TODO` marker appended with a single space, no comma: `-> bind($key, $binder), one call per entry TODO(bind: $binder)`. Only params whose type surfaces in the **value** schema are listed — the entry key of a map (`binding` param-1) never appears (§6.1's `bind` lists only `$binder`).

## Implementation

`src/Internal/SchemaGenerator.php` — pure static, no I/O (the command owns I/O; `Api::render()` proved this split testable):

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use Closure;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/** @internal */
final class SchemaGenerator
{
    /**
     * Renders the `definitions.<block>` fragment for one native class.
     *
     * @param  class-string  $class  the native Laravel class to project
     * @param  string  $block       the manifest block key (`router`, `app`, …)
     * @return array<string, mixed> the JSON-decodable definition object (not encoded — the caller encodes)
     *
     * @throws ReflectionException when $class does not exist (native failure, Rule 3.3)
     */
    public static function render(string $class, string $block): array
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
     * Native methods that pass the §4.1 filter but are not declarable:
     * zero-parameter or by-reference-parameter methods (STYLE 2.1).
     * Reported by the command, never silently dropped.
     *
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
     * Splits the class's public, declaring-class-owned, non-@internal, non-magic
     * methods into [declarable, skipped] in native declaration order (§4.1).
     *
     * @param  ReflectionClass<object>  $Reflection
     * @return array{0: list<ReflectionMethod>, 1: list<ReflectionMethod>}
     */
    private static function declarable(ReflectionClass $Reflection): array
    {
        $methods = [];
        $skipped = [];

        foreach ($Reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $Reflection->getName()) {
                continue; // parent methods belong to the parent's projection (Rule 0.4)
            }

            if (str_contains((string) $method->getDocComment(), '@internal')) {
                continue;
            }

            if (str_starts_with($method->getName(), '__')) {
                continue; // a manifest cannot construct
            }

            $parameters = $method->getParameters();

            if ($parameters === [] || array_any($parameters, static fn (ReflectionParameter $parameter): bool => $parameter->isPassedByReference())) {
                $skipped[] = $method; // STYLE 2.1: a YAML scalar cannot be a PHP reference

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

        // §4.2 setter row — kind rows for append/append-to/binding are inserted
        // above this line by units 02/03; a 3+-param method stays undecided.
        if (count($parameters) === 1 && ! $parameters[0]->isVariadic()) {
            return self::setter($method, $parameters[0]);
        }

        return self::undecided($method);
    }

    /** §4.4 setter → the whole value in one call. @return array<string, mixed> */
    private static function setter(ReflectionMethod $method, ReflectionParameter $parameter): array
    {
        [$schema, $unknown] = self::paramSchema($parameter);

        $unknown = [...$unknown];

        return self::withDescription($schema, self::stub($method, [$parameter->getName()], ' when the key is present', $unknown));
    }

    /**
     * Completing rule for signatures §4.2's rows do not cover: invent nothing,
     * mark every param as undecided (§3 — loud and cheap to fix by hand).
     *
     * @return array<string, mixed>
     */
    private static function undecided(ReflectionMethod $method): array
    {
        $params = array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $method->getParameters());

        return self::withDescription(true, self::stub($method, $params, ' when the key is present', $params));
    }

    /**
     * §4.3 value schema for one parameter. Returns [schema, unknown-param-names].
     * Partial map (units 02/03 extend it): scalars, nullable scalars, unknown → true.
     *
     * @return array{0: mixed, 1: list<string>}
     */
    private static function paramSchema(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType) {
            return [true, [$parameter->getName()]]; // untyped, union, intersection — unit 03 completes unions
        }

        if ($type->isBuiltin()) {
            $json = match ($type->getName()) {
                'string' => 'string',
                'int' => 'integer',
                'float' => 'number',
                'bool' => 'boolean',
                default => null, // `array`, `mixed`, `iterable`, `callable`, … — unit 03
            };

            if ($json !== null) {
                return [$type->allowsNull() ? ['type' => [$json, 'null']] : ['type' => $json], []];
            }
        }

        // class/interface/enum/Closure/mixed — the generator refuses to invent
        // semantics it cannot read from the signature (§4.3, Rules 1.4, 3.4).
        return [true, [$parameter->getName()]];
    }

    /**
     * §4.5 stub: `-> method($a, $b)` + suffix + ` TODO(<method>: $<param>)`.
     *
     * @param  list<string>  $params      params to render into the call
     * @param  list<string>  $undecided   params whose type surfaced as unknown
     * @return string
     */
    private static function stub(ReflectionMethod $method, array $params, string $suffix, array $undecided): string
    {
        $todo = $undecided === [] ? '' : ' TODO('.$method->getName().': '.implode(', ', array_map(static fn (string $param): string => '$'.$param, $undecided)).')';

        return '-> '.$method->getName().'('.implode(', ', array_map(static fn (string $param): string => '$'.$param, $params)).')'.$suffix.$todo;
    }

    /** @param  mixed  $schema  value schema; `true` collapses into a description-only property object */
    private static function withDescription(mixed $schema, string $description): array
    {
        return is_array($schema) ? ['description' => $description, ...$schema] : ['description' => $description];
    }
}
```

> The `Closure` import is only needed from unit 02 (fixtures use it); drop it here if pint flags it — the class above compiles without touching it only when no signature mentions `Closure`.

`src/Internal/Commands/GenerateSchemaCommand.php` — thin shell, print mode only in this unit (`--out` arrives in unit 05):

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Commands;

use Illuminate\Console\Command;
use ReflectionException;
use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;

/** @internal */
class GenerateSchemaCommand extends Command
{
    /** @var string */
    protected $signature = 'declaration:generate-schema
        {class : The native Laravel class FQCN, e.g. Illuminate\\Routing\\Router}
        {--out= : Write the merged manifest.schema.json (default: print the fragment)}';

    /** @var array<int, string> */
    protected $aliases = ['laravel-declaration:generate-schema'];

    /** @var string */
    protected $description = 'Generate manifest.schema.json definitions from a native Laravel class';

    public function handle(): int
    {
        $class = $this->argument('class');
        $block = lcfirst(substr($class, (int) (strrpos($class, '\\') + 1))); // §4.6

        $this->components->info("Block: $block"); // §4.6 — a wrong derivation is visible immediately

        $skipped = SchemaGenerator::skipped($class);

        if ($skipped !== []) {
            $this->components->warn('Skipped: '.implode(', ', $skipped)); // §4.1 — never silently dropped
        }

        // --out merge/write path arrives in unit 05; this unit only prints.
        $this->line(SchemaGenerator::encode(SchemaGenerator::render($class, $block)));

        return self::SUCCESS;
    }
}
```

`SchemaGenerator::encode()` is delivered by unit 04; to keep unit 01 runnable on its own, add the minimal version now and unit 04 completes its byte properties:

```php
/**
 * Encodes a schema array in the repo's 2-space JSON style (§5.1).
 * Unit 04 completes this (JSON_UNESCAPED_UNICODE evidence, idempotency tests).
 *
 * @param  array<string, mixed>  $schema
 */
public static function encode(array $schema): string
{
    $json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    return preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json)."\n";
}
```

Registration — one entry in the existing list, `src/LaravelDeclarationProvider.php::boot()`:

```php
$this->commands([
    InstallCommand::class,
    ValidateCommand::class,
    MigrateCommand::class,
    GenerateSchemaCommand::class, // added
]);
```

## Fixtures — `tests/Fixtures/SchemaGenerator/`

Namespace `ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator` (composer autoload-dev maps `Tests\` → `tests/`).

`BasicParent.php` — proves the declaring-class filter excludes parent methods (Rule 0.4):

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator;

abstract class BasicParent
{
    public function inherited(string $value): void {}
}
```

`Basic.php` — every filter rule, every unit-01 value schema, and one permanently-undecided signature:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator;

use Closure;

final class Basic extends BasicParent
{
    public function __construct() {}

    /** @internal */
    public function internal(string $value): void {}

    public function label(string $label): void {}

    public function retries(?int $retries): void {}

    public function handler($handler): void {}

    public function register(string $name, string $class, ?Closure $callback = null): void {}

    public function version(): void {}

    public function attach(array &$registry): void {}
}
```

Expected classification: `__construct` and `internal` filtered; `inherited` excluded (parent); `version` and `attach` skipped+reported; `label`/`retries`/`handler` setters; `register` undecided (3 params — no §4.2 row matches, in any unit).

## Tests — `tests/Feature/SchemaGeneratorTest.php`

Exact-array assertions on the pure statics (`PublicApiToolTest` convention). The expected fragment is the whole unit-01 behavior in one artifact:

```php
<?php

use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator\Basic;

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

it('projects a class into a fragment with keys in native declaration order', function (): void {
    expect(SchemaGenerator::render(Basic::class, 'basic'))->toBe(BASIC_FRAGMENT);
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
```

`tests/Feature/GenerateSchemaCommandTest.php` — the artisan slice:

```php
<?php

use Illuminate\Routing\Router;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator\Basic;

it('prints the block key, skipped methods and the fragment', function (): void {
    $this->artisan('declaration:generate-schema', ['class' => Basic::class])
        ->expectsOutputToContain('Block: basic')
        ->expectsOutputToContain('Skipped: version, attach')
        ->expectsOutputToContain('-> label($label) when the key is present')
        ->expectsOutputToContain('TODO(register: $name, $class, $callback)')
        ->assertSuccessful()
        ->run();
});

it('derives the block key from the class basename', function (): void {
    $this->artisan('declaration:generate-schema', ['class' => Router::class])
        ->expectsOutputToContain('Block: router')
        ->assertSuccessful()
        ->run();
});

it('excludes parent methods from the projection (Rule 0.4)', function (): void {
    // Illuminate\Foundation\Application extends Container: bind/singleton declare Container.
    $fragment = SchemaGenerator::render(
        Illuminate\Foundation\Application::class,
        'application',
    );

    expect($fragment['properties'])
        ->not->toHaveKey('bind')
        ->not->toHaveKey('singleton')
        ->toHaveKey('basePath');
});

it('includes trait-provided methods (declaring class is the using class)', function (): void {
    $properties = SchemaGenerator::render(Router::class, 'router')['properties'];

    expect($properties)->toHaveKey('macro');
});

it('fails natively for an unknown class (Rule 3.3)', function (): void {
    expect(fn (): array => SchemaGenerator::render('ZeroToProd\Nope', 'nope'))
        ->toThrow(ReflectionException::class);
});
```

## Acceptance checklist

- [ ] Every generated key matches a **native method** name character-for-character (Rule 1.3); keys in native declaration order.
- [ ] Types come from the **native signature**; docblocks never drive structure (Rule 2.5) — the `@internal` fixture proves docblocks are only consulted for that tag.
- [ ] Unknown types are honest: description-only schema + `TODO` (Rules 1.4, 3.4).
- [ ] Skipped methods are reported, never silently dropped (§4.1).
- [ ] Unknown class fails with a native `ReflectionException` (Rule 3.3).
- [ ] Stateless, `@internal`, pure statics, I/O only in the command (Rule 4).
- [ ] `composer check` passes (pint, rector, phpstan level 9, 100% coverage of every branch above).

## Sources

- Plan: [docs/declarative-schema-generator.md](../../declarative-schema-generator.md) §4.1, §4.2, §4.3, §4.4, §4.5, §4.6, §5.1, §5.2, §7.
- [src/Internal/Mcp/Tools/Api.php](../../../src/Internal/Mcp/Tools/Api.php) — `methods()` filter and pure-static-render split copied here.
- [src/Providers/RouterDeclarationServiceProvider.php](../../../src/Providers/RouterDeclarationServiceProvider.php) — the `Setter` loop (`if ($value !== null)`) justifying §4.2's setter row.
- [src/Manifest.php](../../../src/Manifest.php) — `?Router $router` proving the `["object","null"]` envelope and `lcfirst(basename)` block keys.
- [src/Router.php](../../../src/Router.php) — declaration-order anatomy (Rule 8.1).
- [tests/Feature/PublicApiToolTest.php](../../../tests/Feature/PublicApiToolTest.php), [tests/Fixtures/PublicApi/Widget.php](../../../tests/Fixtures/PublicApi/Widget.php) — headless-test and fixture style (untyped params are phpstan-clean).
- `vendor/laravel/framework/src/Illuminate/Foundation/Application.php`, `.../Support/Traits/Macroable.php` — declaring-class filter evidence (parent excluded, trait included).