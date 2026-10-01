# Declarative Manifest Schema Generator — Whole-File Plan

A script that regenerates `manifest.schema.json` for a **given set of classes** — the
`block => native class` map — keeping the **schema** true to what the declaration
providers actually forward.

- Input: a set of classes, one per manifest block, e.g. `router => Illuminate\Routing\Router`.
- Output: the full `manifest.schema.json` — every in-scope `definitions.<block>` fragment, regenerated in place.
- Companion plan: [declarative-schema-generator.md](declarative-schema-generator.md) bootstraps **one new block** from one native class. This plan **maintains the whole file** for the blocks that already exist. Both share one reflection engine (§9).
- No code is implemented by this document; it is the plan.

## 0. Vocabulary

Terms from [STYLE.md](../STYLE.md) plus the new ones this plan needs:

- `manifest` — the YAML file (`manifest/app.yml`) validated by the **schema**.
- `schema` — `manifest.schema.json`, draft-07 JSON Schema; the only validation layer.
- `block` — a top-level manifest key (`router`, `app`, `kernel`, …) projected from one native class.
- `declaration class` — `final readonly` class mirroring one native class; one attributed `const`+property pair per forwardable native method.
- `native method` — the real Laravel method; source of truth for existence, casing, signature order, optionality, and types (Rules 1, 2).
- `visible method` — a public method reachable on the native class by `ReflectionClass::getMethods()`: declared on the class **or inherited from a parent or trait** (§4 — three blocks prove inheritance is mandatory).
- `block map` — the ordered `block => native class-string` set the generator projects; the caller-supplied scope of a run.
- `drift` — a mismatch between the native class and the declaration class: an unprojected native method, or a declaration key with no native method.
- `fragment` — the generated `definitions.<block>` object for one block.

## 1. Understand the source code (what each file proves)

| Source | What it proves for this plan |
| --- | --- |
| `manifest.schema.json` | The target artifact. Draft-07, 2-space indent, root `properties.<block>` → `#/definitions/<block>`, `type: ["object","null"]`, `additionalProperties: false` per block; 34 `definitions`, of which 10 are shared primitives (`classString`, `reference`, `phpFile`, `closure`, `closureList`, `concrete`, `binding`, `bindingIf`, `stringOrList`, `extension`) and 1 (`config`) is not a class projection. Per-key `description` strings carry curated semantics ("A route's own `where` wins."). |
| `src/Router.php` | Declaration anatomy: `const string <key> = '<key>'` + docblock `@var` + `#[Key, Binding, Describe([Describe::default => []])]` + typed property. The `@var` types (`array<string, string>`, `array<string, list<class-string|string>>`, `?bool`, `list<string>`) are machine-readable refinements of the bare native signatures. |
| `src/Kernel.php` | Same anatomy on a second component; adds `#[Prepend]`, `#[AppendTo]` on `addToMiddlewarePriorityBefore`, and one attribute-less property (`whenRequestLifecycleIsLongerThan`) dispatched hand-rolled by its provider — proof that attribute presence is not required for schema generation, only `Describe` + phpdoc. |
| `src/App.php` | `#[Binding, Conditional]` pairs (`bindIf`, `singletonIf`, `scopedIf`) — the machine-readable cue behind the curated `bindingIf` shared definition; phpdoc `array<string, string|null>|list<string>` matches the shared `binding` `oneOf` exactly. |
| `src/View.php`, `src/Pagination.php`, `src/Response.php` | Blocks with **no dispatch attributes at all** (`Describe`-only, plus `#[Preset]` booleans in Pagination). Their schema fragments are still fully derivable from phpdoc + `Describe`. Proves the refinement source is the declaration property, not the attribute set. |
| `src/Providers/RouterDeclarationServiceProvider.php` | The five `selected(...)` loops are the runtime meaning of `Binding`/`Setter`/`PrependTo`/`AppendTo`/`Append` — the phrasing grammar for generated stub descriptions (§5.4). |
| `src/Providers/KernelDeclarationServiceProvider.php`, `ViewDeclarationServiceProvider.php` | Two more dispatch styles: `callAfterResolving(...)` queues and hand-rolled loops (`whenRequestLifecycleIsLongerThan`, `flushFinderCache` flags). Proves dispatch style varies per block while per-key value shapes stay uniform — schema generation is independent of the dispatch mechanism. |
| `src/Manifest.php` | Root wiring: one `const` + nullable-typed property per block; the canonical list of block keys; `Describe([Describe::nullable => true])` on each block property. |
| `src/Internal/Mcp/Tools/Api.php` | The canonical reflection pattern: pure static `render()` tested headlessly against fixtures; docblock `@internal` and declaring-class filters; signature rendering; `json_encode`/`var_export` default serialization. This plan reuses the shape but **widens the visibility filter** (§5.1). |
| `src/Internal/Commands/ValidateCommand.php` | The schema is loaded via `'file://'.realpath(__DIR__.'/../../../manifest.schema.json')` — the generator must keep that path stable; also the Yaml-parse + BaseConstraint validation pattern reused by the `--check` self-test. |
| `src/Internal/Commands/MigrateCommand.php` | Command conventions: `$signature`, `$aliases = ['laravel-declaration:migrate']`, `handle(Manifest $manifest): int`, `@internal`. |
| `src/LaravelDeclarationProvider.php` | Commands registered in `boot()` via `$this->commands([...])` — where the new command is added. |
| `src/DefaultProviders.php` | Package-fixed configuration as a small dedicated class — the precedent for the default `block map` (`SchemaMap`). |
| `docs/implementation/components/component-index.md` | The full block → vendor class map (~45 components). Source for the default `SchemaMap` and the excluded-block list (§3). Proves block keys (`router` vs `routes`) are not derivable from the class — the map is explicit. |
| `tests/Feature/PublicApiToolTest.php`, `tests/Fixtures/PublicApi/` | How reflection renderers are tested: pure static call against fixture directories, exact-string assertions, fixture classes exercising unions/defaults/variadics/`@internal`/traits. |
| `tests/Feature/ValidateCommandTest.php`, `tests/Feature/InstallCommandTest.php` | Artisan command testing convention: `$this->artisan('laravel-declaration:validate', ['--manifest' => ...])` + `assertSuccessful()`; fixture manifests per block under `tests/Fixtures/manifest/`. |
| `tests/TestCase.php` | Testbench bootstrap; `withConfig([...])` helper for pointing `laravel-declaration.manifest` at fixture YAML. |
| `composer.json` | `composer check` gates: pint, rector, phpstan, **100% coverage**, bc-check — every branch of the generator needs a test. |

## 2. Understand the vendor source (the reflection targets)

The decisive vendor facts — each one changes the design:

| Block | Mapped class | Where its schema keys actually live | Consequence |
| --- | --- | --- | --- |
| `app` | `Illuminate\Foundation\Application` | `bind`, `bindIf`, `singleton`, `instance`, `alias`, `extend`, `tag`, `when`, `resolving`, `afterResolving` are declared on the **parent** `Illuminate\Container\Container`; `useAppPath`…, `setLocale`, `registered`, `booting`, `booted`, `terminating` on `Application` | A declaring-class-only filter (as in `Api::methods()`) loses half the `app` keys. The generator's filter must be **hierarchy-inclusive** (§5.1). |
| `pagination` | `Illuminate\Pagination\Paginator` | All seven keys (`useTailwind`, `useBootstrap`, `defaultView`, …) are `public static` methods declared on the **parent** `Illuminate\Pagination\AbstractPaginator` | Same consequence, plus statics must pass the filter. |
| `responses` | `Illuminate\Routing\ResponseFactory` | `macro` is declared on the trait `Illuminate\Macroable\Traits\Macroable` | Same consequence for traits. |
| `view` | `Illuminate\View\Factory` | `composer`, `creator` are declared on the trait `Illuminate\View\Concerns\ManagesEvents`; `addLocation`, `addNamespace`, `share`, `addExtension`, `flushFinderCache` on `Factory` itself | Same consequence. |
| `gate` | `Illuminate\Contracts\Auth\Access\Gate` | `policy`, `define` declared on the **interface** | Reflection must accept interfaces (`getMethods()` works; `isInterface()`). |
| `router` | `Illuminate\Routing\Router` | All current keys declared on `Router` itself (`pattern`, `model`, `bind`, `matched`, `resourceVerbs`, …) | The one block where declaring-class-only would work — which is exactly why the single-class plan (built from `Router`) under-detected the issue. |
| `kernel` | `Illuminate\Foundation\Http\Kernel` | `pushMiddleware`, `setGlobalMiddleware`, `addToMiddlewarePriorityBefore` declared on `Kernel` | `Kernel` extends nothing relevant; filter still safe. |
| `blade` | `Illuminate\View\Compilers\BladeCompiler` | `directive`, `if`, `component`, `stringable`, … declared on `BladeCompiler` | `withoutDoubleEncoding()` takes zero parameters — a **flag** key (§5.2). |
| `db` | `Illuminate\Database\DatabaseManager` | `connection`, `listen` declared on `DatabaseManager` | — |
| `validator` | `Illuminate\Validation\Factory` | `extend`, `extendImplicit`, `extendDependent`, `replacer` declared on `Factory` | — |

Reference docs per component live in `docs/repos/laravel/docs/` — used for curated `description` prose only, never by the generator.

## 3. Scope — the block map

The default map is package-fixed knowledge (like `component-index.md` and `DefaultProviders`), overridable per run:

| Block (map order) | Native class | Declaration class |
| --- | --- | --- |
| `app` | `Illuminate\Foundation\Application` | `ZeroToProd\LaravelDeclaration\App` |
| `kernel` | `Illuminate\Foundation\Http\Kernel` | `ZeroToProd\LaravelDeclaration\Kernel` |
| `router` | `Illuminate\Routing\Router` | `ZeroToProd\LaravelDeclaration\Router` |
| `view` | `Illuminate\View\Factory` | `ZeroToProd\LaravelDeclaration\View` |
| `blade` | `Illuminate\View\Compilers\BladeCompiler` | `ZeroToProd\LaravelDeclaration\Blade` |
| `responses` | `Illuminate\Routing\ResponseFactory` | `ZeroToProd\LaravelDeclaration\Response` |
| `pagination` | `Illuminate\Pagination\Paginator` | `ZeroToProd\LaravelDeclaration\Pagination` |
| `db` | `Illuminate\Database\DatabaseManager` | `ZeroToProd\LaravelDeclaration\Database` |
| `validator` | `Illuminate\Validation\Factory` | `ZeroToProd\LaravelDeclaration\Validator` |
| `gate` | `Illuminate\Contracts\Auth\Access\Gate` | `ZeroToProd\LaravelDeclaration\Gate` |

Excluded blocks, each with the proof of why reflection cannot project it:

| Block | Why excluded |
| --- | --- |
| `config` | Not a class projection: "the argument to `config([...])`" — every key is a config file path (its definition says so). |
| `providers` | A list of `class:` entries, not a method map. |
| `routes`, `route*` | A list of reserved-key entries (`path`, `methods`, `action`, …) — a **data** shape, not a method map. |
| `requests`, `models`, `queries` | Same list-of-reserved-keys shape. |
| `schema`, `tableDefinition` | `Schema\Builder` operations are five fixed keys with guard-derived idempotency, and table bodies are `Blueprint` methods wrapped in guard attributes (`ColumnGuards`, `IndexGuards`, …) — a different generator problem entirely. |
| `extra` | `{"type":"object"}` — intentionally free-form. |

## 4. Decomposition — who decides what

The generator is honest about the boundary between **derivable structure** and **curated semantics**, and adds a third source the single-class plan lacked: the **declaration class**.

| Question | Decided by | Proof |
| --- | --- | --- |
| Which keys may appear in `definitions.<block>`? | The **declaration class** — only keys the providers forward may validate (a schema-valid key with no dispatch loop is a silent no-op: a lie the schema must not tell). | `RouterDeclarationServiceProvider` dispatches exactly `Router`'s attributed properties; the `app` fragment has no `bound`/`resolved`/`make` although `Container` declares them. |
| Is each key a real native method? | The **native class** — `$Reflection->hasMethod($key)` (hierarchy + traits), case-sensitively. A declaration key that fails is a hard error (Rule 1.5). | `Router::pattern` exists on `Illuminate\Routing\Router:1220`. |
| Which native methods are *not yet* projected? | The **native class** minus the declaration — reported as drift, never auto-added. | `Illuminate\Routing\Router::patterns($patterns)` (line 1231) is native but has no declaration property — a drift report item, not a schema key. |
| What shape is each key's value? | The declaration property's phpdoc `@var` first; the **native signature** second (fallback for keys without usable phpdoc); `true` + `TODO(...)` when neither resolves (§5.3, §5.5). | `@var array<string, list<class-string>|class-string>` ↔ the `appendMiddlewareToGroup` `anyOf` shape; `?bool` ↔ `singularResourceParameters` `boolean`. |
| What call does each key make? | The **native signature** (parameter names/order) + the declaration **attributes** (per-entry / per-item / once / flag phrasing). | The five provider loops; `#[Preset]` booleans; `#[Conditional]` "*If" semantics. |
| What prose enriches the stub? | Hand-authored curation in the current `manifest.schema.json`, **preserved on merge** — never regenerated, never lost. | "A route's own `where` wins.", "404 when null", `middlewareGroup` kernel-wins note. |

Consequences:

1. **Key set = declaration keys ∩ native visible methods.** Declaration-first for *what exists*; native-first for *what each key means*. Adding a Laravel method to the schema is a two-step flow: add the attributed property (the single-class plan's `--scaffold` does this), then regenerate the schema.
2. **Inheritance is mandatory in the visibility filter** — three blocks' keys live on parents/traits (§2). The `Api::methods()` declaring-class filter is deliberately *not* copied.
3. Regeneration is safe to re-run: structure is rebuilt, curation is preserved, and the output is byte-identical when nothing changed (idempotence, tested).

## 5. Derivation specification

### 5.1 Visibility filter (hierarchy-inclusive)

Applied per native class, differing from `Api::methods()` in exactly two bullets (marked ★):

- `$method->isPublic()` (static or instance — pagination's presets are static);
- name does not start with `__` (a manifest cannot construct or clone);
- docblock does not contain `@internal`;
- ★ declared by the class itself **or any ancestor/trait** — `ReflectionClass::getMethods()` unfiltered by declaring class, because the block map names the *public-facing* class (`Paginator`, `ResponseFactory`, `Factory`, `Application`) whose usable surface includes parents and traits (§2);
- ★ at least one non-by-reference parameter **or** the declaration property is `bool` (the flag kind, §5.2 — `flushFinderCache`, `withoutDoubleEncoding`, `useTailwind` have zero parameters and are declarable flags; STYLE 2.1 still bars YAML from passing PHP references, so `&$x` methods stay out).

Methods failing only the last bullet are listed as skipped in the drift report, never silently dropped.

### 5.2 Key-set reconciliation (per block)

For block `B` with native class `N` and declaration class `D`:

1. `D_keys` = the `const string` + property pairs of `D` (reflection: `getReflectionConstants()` + `getProperties()` in declaration order — same order `src/Router.php` is written in).
2. For each `$key` in `D_keys`: if `!$N->hasMethod($key)` → hard failure naming the block and key (Rule 1.5 — the override map may never invent a key; neither may the declaration).
3. `native_only` = visible methods (§5.1) whose names are not in `D_keys` → drift report: `<block>.<method>(<params>)` with the docblock summary from `Api::summary()`.
4. Schema key order = `D_keys` order (native declaration order, vertically aligned with the declaration file — Rule 8.1).

When a block has no declaration class yet (new block), the single-class mode of the sibling plan bootstraps it first; this generator requires `D` for every block in the map and fails otherwise.

### 5.3 Attribute kinds → call phrasing

The runtime meaning is fixed by the provider loops. The kind is read from the declaration property's attributes:

| Declaration attributes | Runtime call (provider loop) | Stub phrasing |
| --- | --- | --- |
| `Binding` | `foreach ($map as $key => $value) $X->{$method}($key, $value);` | `-> method($p1, $p2), one call per entry` |
| `Setter` + `Describe([Describe::nullable => true])` | `if ($value !== null) $X->{$method}($value);` | `-> method($p1) when the key is present` |
| `AppendTo` | per-item in order over `(array)$value` | `-> method($p1, $p2), one call per item` |
| `PrependTo` | per-item **reversed** | `-> method($p1, $p2), one call per item, reversed` |
| `Append` | one call per list item | `-> method($p1), one call per item` |
| `Prepend` (Kernel) | per-item reversed, one call per item | same as `PrependTo` |
| `Preset` + `Describe([Describe::default => false])` | `if ($value === true) X::{$method}();` | `X::method() when true` |
| `bool` + `Describe([Describe::default => false])`, no dispatch attribute | `if ($value === true) $X->{$method}();` (hand-rolled flag loop, e.g. `flushFinderCache`) | `-> method() when true` |
| no dispatch attribute | whatever the provider hand-rolls | `-> method($p1, $p2) when the key is present` |
| `Conditional` alongside `Binding` | the `*If` native guard ("skipped when bound") | append `; skipped when bound` |

`#[Preset]`, `#[Conditional]` and friends are *names* read by reflection (`getAttribute(Preset::class)`) — no `match` on strings (Rule 7).

### 5.4 phpdoc `@var` → JSON value shape (the refinement engine)

Parse the declaration property's `@var` tag. Grammar (only the forms present in `src/*.php`):

```
type      := union
union     := type-atom ('|' type-atom)*        // deduplicated, order preserved
type-atom := 'array<K, V>' | 'list<V>' | '?' atom | atom
atom      := 'string' | 'int' | 'float' | 'bool' | 'class-string' | 'mixed' | FQCN
```

Mapping (deterministic; anything unparseable falls back to §5.5):

| phpdoc | JSON Schema |
| --- | --- |
| `array<string, string>` | `{"type":"object","additionalProperties":{"type":"string"}}` |
| `array<string, class-string>` | `{"type":"object","additionalProperties":{"$ref":"#/definitions/classString"}}` |
| `array<string, string|null>` | `{"type":"object","additionalProperties":{"type":["string","null"]}}` |
| `array<string, string\|list<string>>` | `{"type":"object","additionalProperties":{"$ref":"#/definitions/stringOrList"}}` — emitted concretely as `{"anyOf":[{"type":"string"},{"type":"array","items":{"type":"string"},"minItems":1}]}`; replacing it with the shared `$ref` is curation (preserved on merge once authored) |
| `array<string, list<class-string\|string>>` | `{"type":"object","additionalProperties":{"anyOf":[{"$ref":"#/definitions/classString"},{"type":"array","items":{"$ref":"#/definitions/classString"}}]}}` |
| `array<string, mixed>` | `{"type":"object"}` |
| `array<int|string, class-string\|string>` | `{"type":"object"}` (mixed keys are not constrainable) |
| `list<string>` | `{"type":"array","items":{"type":"string"}}` |
| `list<class-string>` | `{"type":"array","items":{"$ref":"#/definitions/classString"}}` |
| `array<string, string>\|list<string>` | `oneOf` of the two shapes above (the `binding` shape) |
| `?T` (setter) | shape of `T` (the key's absence already encodes "not called"; null is unreachable through the schema — matches `singularResourceParameters` in the current file) |
| `bool` (flag/preset) | `{"type":"boolean"}` |
| `string` | `{"type":"string"}` |

Native parameter **defaults are never emitted** as schema `default`: every key is opt-in (Rules 6.1–6.2), and the curated fragments record the behavior in prose ("absent key -> Laravel default (true)") instead.

### 5.5 Native signature → JSON value shape (fallback)

Inherited unchanged from the single-class plan (§4.3 there): scalars map to JSON types, `T ...$x` → `{"type":"array","items":<T>}`, unions of scalars → `"type": [...]`, unions involving `$ref` → `anyOf` (draft-07 ignores `$ref` siblings), class/enum/`Closure`/mixed/untyped → `true` + `TODO(<method>: $<param>)` appended to the stub description. Docblocks are never parsed for the native side (Rule 2.5).

### 5.6 Per-key schema by kind

- `Binding` → `{"type":"object","additionalProperties":<value shape>}` (entry key is param 1; value shape from phpdoc, falling back to param 2 of the native signature).
- `Setter` → the value shape.
- `Append` / `Prepend` → `{"type":"array","items":<value shape>}`.
- `AppendTo` / `PrependTo` → `{"type":"object","additionalProperties":{"anyOf":[<shape>, <list form of shape>]}}` — the `string|array` union the current kernel/router fragments use.
- flag/preset → `{"type":"boolean"}`.

When phpdoc already yields the full shape (§5.4), it wins; §5.6 phrasing then only picks the wrapper. Conflict between phpdoc and native signature (e.g. phpdoc `array<string, string>` but native second param is `array`) → phpdoc wins; both are visible in the drift report for review.

### 5.7 Stub description

```
-> pattern($key, $pattern), one call per entry
-> pushMiddlewareToGroup($group, $middleware), one call per item
-> prependMiddlewareToGroup($group, $middleware), one call per item, reversed
-> singularResourceParameters($singular) when the key is present
-> Paginator::useTailwind() when true
-> bindIf($abstract, $concrete), one call per entry; skipped when bound
```

Parameter names come from the native signature; the phrasing suffix from §5.3. This satisfies Rule 9.6 for the schema and keeps `--scaffold` output (single-class plan) consistent.

### 5.8 Block wrapper and shared definitions

Every generated `definitions.<block>`:

```json
{
  "description": "<preserved curated prose>",
  "type": ["object", "null"],
  "additionalProperties": false,
  "properties": { "<key>": { "description": "<stub>", ... } }
}
```

- The block-level `description` is curated (it carries "Applied first in boot()", "Applied when Laravel first resolves `view`") — preserved on merge, never regenerated.
- Shared primitives (`classString`, `reference`, `stringOrList`, `binding`, …) are **never touched**: the generator emits concrete shapes and lets curation replace them with `$ref`s; merge-preservation keeps the `$ref`s.
- Root `properties.<block>` gets `{"$ref": "#/definitions/<block>"}` only when the block is new; existing entries and the file's `$schema`, `title`, `description` are untouched.
- New blocks append to root `properties` after the last method-map block (`db`), before `providers`.

### 5.9 Merge, idempotence, prune

`SchemaGenerator::merge($schema, $fragments, bool $prune)`:

1. For each block: replace `definitions.<block>.properties` wholesale with the generated keys (order §5.2), preserving per-key `description` when the existing key already has one and the run supplies no override.
2. Keys in the current fragment that are no longer declaration keys are **kept and reported** by default (curated prose must not vanish silently); `--prune` drops them.
3. `drift` report printed: unprojected native methods (§5.2 step 3), skipped methods (§5.1), prunable keys.
4. Encode `json_encode(..., JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)` then halve `JSON_PRETTY_PRINT`'s 4-space indent to the repo's 2 spaces (`preg_replace_callback('/^( +)/m', ...)` — the indentation is an exact multiple of 4, so halving is byte-exact).
5. Atomicity: all blocks render before anything is written; one failing block aborts the run with a native error and the file is untouched.

Idempotence: structure is a pure function of (native class, declaration class, map); curation is preserved; therefore a second run rewrites the file byte-identically. `--check` exploits this: render in memory, compare to the committed file, exit `FAILURE` + diff summary on drift — a CI gate alongside `bc-check` in `composer check`.

## 6. Architecture

```
src/Internal/SchemaMap.php                 # default block => class map (package-fixed, like DefaultProviders)
src/Internal/SchemaGenerator.php           # pure static renderer + merger — no I/O
src/Internal/Commands/GenerateSchemaCommand.php  # Artisan wrapper, @internal
```

### 6.1 `SchemaMap`

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

/** @internal The manifest blocks projected from native classes, in schema order. */
final class SchemaMap
{
    public const array MAP = [
        'app' => \Illuminate\Foundation\Application::class,
        'kernel' => \Illuminate\Foundation\Http\Kernel::class,
        'router' => \Illuminate\Routing\Router::class,
        'view' => \Illuminate\View\Factory::class,
        'blade' => \Illuminate\View\Compilers\BladeCompiler::class,
        'responses' => \Illuminate\Routing\ResponseFactory::class,
        'pagination' => \Illuminate\Pagination\Paginator::class,
        'db' => \Illuminate\Database\DatabaseManager::class,
        'validator' => \Illuminate\Validation\Factory::class,
        'gate' => \Illuminate\Contracts\Auth\Access\Gate::class,
    ];
}
```

Excluded blocks (§3) are deliberately absent — the map is the scope, `--blocks=` narrows it.

### 6.2 `SchemaGenerator`

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

/** @internal */
final class SchemaGenerator
{
    /**
     * Renders the `definitions.<block>` fragment for one block.
     *
     * @param  class-string  $native       the native class the block projects
     * @param  class-string  $declaration  the declaration class (const+property pairs)
     * @param  string  $block              the manifest block key
     * @param  array<string, array{schema?: array, description?: string}>  $overrides
     * @return array<string, mixed>  the JSON-decodable fragment (caller encodes)
     *
     * @throws ReflectionException        when $native does not exist (native failure, Rule 3.3)
     * @throws OutOfBoundsException       when a declaration key is not a native method (Rule 1.5)
     * @throws InvalidArgumentException   when the declaration class is missing or malformed
     */
    public static function render(string $native, string $declaration, string $block, array $overrides = []): array;

    /**
     * @param  array<string, mixed>  $schema  parsed manifest.schema.json
     * @param  array<string, array<string, mixed>>  $fragments  block => fragment from render()
     * @return array{schema: array<string, mixed>, drift: list<string>}
     */
    public static function merge(array $schema, array $fragments, bool $prune = false): array;
}
```

Internals (each backed):

- `render()` does reflection only; the command owns all I/O (the `Api` render/handle split, proven testable headlessly).
- Declaration parsing: `new ReflectionClass($declaration)`, then `getReflectionConstants()` (filter `isPublic`, type `string`) zipped with `getProperties()` in declaration order — reproducing `src/Router.php`'s layout.
- Native parsing: `getMethods()` hierarchy-inclusive (§5.1); per-key `ReflectionMethod` for parameter names/order and docblock summary.
- phpdoc parsing: extract the first `@var` tag; run the §5.4 grammar; failure ⇒ §5.5 fallback ⇒ `true` + `TODO`. Pure string work, no external parser.
- Kind detection: `$property->getAttribute(Binding::class)` etc. — reflection on the existing attributes, zero new dispatch code (Rule 8.5).
- `merge()` preserves curation (§5.9) and returns the drift report separately from the schema so the command controls presentation.

### 6.3 `GenerateSchemaCommand`

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Commands;

use Illuminate\Console\Command;
use JsonException;
use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;
use ZeroToProd\LaravelDeclaration\Internal\SchemaMap;

use function is_file;
use function is_string;

/** @internal */
class GenerateSchemaCommand extends Command
{
    /** @var string */
    protected $signature = 'declaration:generate-schema
        {--blocks= : Comma-separated subset of blocks (default: every block in SchemaMap)}
        {--map= : Path to a PHP file returning block => class-string (default: SchemaMap::MAP)}
        {--out= : Where to write (default: the package root manifest.schema.json)}
        {--check : Do not write; exit FAILURE when the committed file drifted}
        {--prune : Drop schema keys that are no longer declaration keys}';

    /** @var string */
    protected $description = 'Regenerate manifest.schema.json from the mapped native classes';

    public function handle(): int
    {
        // 1. Resolve the map: --map (is_file, include, must return array<string, class-string>)
        //    or SchemaMap::MAP; --blocks filters it; an unknown block name is a hard error.
        // 2. For every block, render the fragment (SchemaGenerator::render) — first failure
        //    aborts the whole run; nothing is written (§5.9 atomicity).
        // 3. Read the committed schema (default: __DIR__.'/../../../manifest.schema.json',
        //    the same realpath ValidateCommand loads — the path is part of the contract).
        // 4. merge(); print the drift report (unprojected native methods, skipped, prunable).
        // 5. --check: compare the merged schema to the committed one (canonical JSON string
        //    equality); drift => print a per-block key diff summary and return FAILURE.
        // 6. Otherwise encode (2-space, §5.9) and write --out; report added/pruned keys; SUCCESS.
    }
}
```

Registration: one line in `src/LaravelDeclarationProvider.php`'s existing `$this->commands([...])`, plus the alias convention from `MigrateCommand` (`protected $aliases = ['laravel-declaration:generate-schema'];`). Note `MigrateCommand`/`ValidateCommand` use `laravel-declaration:` prefixed signatures; the shorter `declaration:` prefix is the established *alias* pattern — the signature here follows the single-class plan's `declaration:generate-schema` so the two plans share one command surface.

### 6.4 Relationship to the single-class plan

| Concern | [declarative-schema-generator.md](declarative-schema-generator.md) | This plan |
| --- | --- | --- |
| Purpose | Bootstrap **one new block** from one native class | **Maintain** the whole file for mapped blocks |
| Key set | All visible native methods (nothing declared yet) | Declaration keys ∩ native methods |
| Visibility filter | Declaring-class-only | Hierarchy-inclusive (§5.1 — mandatory per §2) |
| Value shape | Native signature + override file | Declaration phpdoc + attributes; native fallback |
| Zero-param methods | Skipped and reported | Flag kind when the declaration says `bool` (§5.2) |
| Output | One fragment (print or merge) | The full `manifest.schema.json` (write or `--check`) |

Both call into the same `SchemaGenerator` internals (type map, stub grammar, encoder). The single-class plan's §4.2 should be read as amended by §5.1/§5.2 here — the three trait/parent cases (§2) supersede its declaring-class filter, and the flag kind supersedes its "zero-parameter methods are skipped" rule.

## 7. Worked example — `pagination` and `router`

```bash
php artisan declaration:generate-schema --blocks=pagination,router
```

### 7.1 `pagination` — proves hierarchy-inclusive reflection and the flag kind

`Paginator` declares none of these methods; `AbstractPaginator` does (§2). The declaration (`src/Pagination.php`) drives kinds and shapes:

```json
{
  "description": "Illuminate\\Pagination\\Paginator configuration.",
  "type": ["object", "null"],
  "additionalProperties": false,
  "properties": {
    "defaultView": {
      "description": "-> defaultView($view) when the key is present",
      "type": "string"
    },
    "defaultSimpleView": {
      "description": "-> defaultSimpleView($view) when the key is present",
      "type": "string"
    },
    "useTailwind": {
      "description": "Paginator::useTailwind() when true",
      "type": "boolean"
    },
    "useBootstrap": {
      "description": "Paginator::useBootstrap() when true (alias for useBootstrapFour)",
      "type": "boolean"
    },
    "useBootstrapThree": { "type": "boolean" },
    "useBootstrapFour": { "type": "boolean" },
    "useBootstrapFive": { "type": "boolean" }
  }
}
```

- `defaultView`/`defaultSimpleView`: `?string` phpdoc + `Setter` ⇒ `string`, "when the key is present". (No `"null"` in the type: absence already encodes "not called" — the current fragment agrees.)
- `useTailwind`: `#[Preset]` + static zero-param native ⇒ flag phrasing naming the static call.
- Curated prose ("alias for useBootstrapFour") is preserved by merge.

### 7.2 `router` — proves declaration-first keys and curation preservation

The native class reports `patterns($patterns)` as unprojected (no declaration property ⇒ drift item, no schema key). Every existing key regenerates structurally identical to the committed fragment; the curated prose ("A route's own `where` wins.", the `bind` `Class@method` pattern, `matched`'s `append` kind) survives merge untouched. `matched` requires no override here: the declaration already says `#[Append]` with `@var list<string>` — the single-class plan needed an override file only because it had no declaration to read.

### 7.3 Drift report (stdout)

```
router: 1 native method not projected: patterns($patterns) — Patterns for the URI
pagination: 0 unprojected, 0 skipped
```

`--check` turns any structural difference into exit `FAILURE`, e.g. after a Laravel minor bump adds `Router::resourceVerbs2()` — or, realistically, after someone adds a native method without a declaration property.

## 8. Test plan

Fixture native classes under `tests/Fixtures/SchemaGenerator/` modeled on `tests/Fixtures/PublicApi/`:

- a class whose keys are partly declared on a **parent** and a **trait** (the §2 hierarchy cases);
- static methods, `@internal` methods, `__construct`, by-reference parameters, zero-parameter methods;
- a declaration class with every §5.3 attribute kind, phpdoc forms from §5.4, and one bogus key (the `OutOfBoundsException` path);
- a `--map` fixture file returning `block => class-string`, including one unknown-class entry (the `ReflectionException` path).

Feature tests (`tests/Feature/GenerateSchemaCommandTest.php`), following `PublicApiToolTest` (exact-string assertions) and `InstallCommandTest` (`$this->artisan(...)`):

1. `render()` on the fixture pair produces the exact expected fragment: keys in declaration order, §5.3/§5.4 tables satisfied, `true` + `TODO` on the untyped path.
2. Declaration key that is not a native method ⇒ `OutOfBoundsException`, nothing written (atomicity).
3. Hierarchy cases: parent-declared and trait-declared keys appear; declaring-class-only would omit them (assert explicitly, locking in §5.1).
4. `merge()` preserves existing per-key `description` and block-level `description`; appends new keys in declaration order; `--prune` removes; unrelated definitions byte-untouched (diff the decoded JSON).
5. Idempotence: run twice against the real `manifest.schema.json` copy; second run is byte-identical.
6. `--check`: pristine file ⇒ SUCCESS; mutate a copy (delete a key) ⇒ FAILURE with the key named in output.
7. `--blocks` subset and `--map` override; unknown block and unknown class fail natively (Rule 3).
8. Round-trip: `declaration:validate` (fixture manifests) still passes after regeneration — the schema the generator writes is the schema the validator consumes.
9. `composer check` gates: pint, rector, phpstan, **100% coverage** — every branch of `SchemaGenerator` and the command needs a test.

## 9. Execution order

1. `src/Internal/SchemaMap.php` (§6.1).
2. `src/Internal/SchemaGenerator.php` — declaration parser (§5.2 step 1), visibility filter (§5.1), reconciliation (§5.2), phpdoc engine (§5.4), native fallback (§5.5), kind wrappers (§5.6), stub grammar (§5.7), `render()`.
3. `SchemaGenerator::merge()` — curation preservation, ordering, prune, drift report, 2-space encoder (§5.9).
4. `src/Internal/Commands/GenerateSchemaCommand.php` + registration in `src/LaravelDeclarationProvider.php` (§6.3).
5. Fixtures + feature tests (§8).
6. Optional follow-ups (out of scope): extend `SchemaMap` as new blocks are implemented; expose `SchemaGenerator::render` as an MCP tool beside `Api`; backport the hierarchy filter and flag kind into the single-class plan's generator.

## 10. Acceptance checklist

- [ ] Every generated key matches a **declaration** property and a **native method** name character-for-character (Rules 1.1–1.3).
- [ ] A declaration key without a native method fails loudly (Rule 1.5); an unprojected native method is reported, never auto-added.
- [ ] Keys inherited from parents/traits are included (`app.bind`, `pagination.useTailwind`, `responses.macro`, `view.composer` all present).
- [ ] Types/order/optionality come from the declaration phpdoc and native signature; docblocks of the native class never drive structure (Rule 2).
- [ ] The generator invents nothing: unresolvable shapes are `true` + `TODO`, skipped methods are reported (Rules 1.4, 3).
- [ ] Regeneration never loses curated `description` prose at key or block level, and is idempotent byte-for-byte.
- [ ] Output is byte-compatible with the repo's 2-space JSON style; `$schema` header, root metadata, shared primitives, and non-method-map definitions are untouched; the file path `ValidateCommand` loads is stable.
- [ ] `--check` fails on drift; a run that fails mid-way writes nothing (atomic).
- [ ] Stateless and `@internal`; pure `render()`/`merge()` cores, I/O only in the command (Rule 4).
- [ ] `composer check` passes.

## 11. Sources

- [STYLE.md](../STYLE.md) — Rules 1–9 (key identity, argument forwarding, fail-native, optionality), Rule 7 attribute table, Canonical Skeleton, shipping checklist.
- [manifest.schema.json](../manifest.schema.json) — target artifact: draft-07, 2-space style, root `properties`, shared primitives, curated `description` style, `anyOf` union precedent.
- [src/Internal/Mcp/Tools/Api.php](../src/Internal/Mcp/Tools/Api.php) — reflection rendering pattern, `@internal` filtering, pure-static testability; the filter this plan deliberately widens (§5.1).
- [src/Router.php](../src/Router.php), [src/Kernel.php](../src/Kernel.php), [src/App.php](../src/App.php), [src/View.php](../src/View.php), [src/Pagination.php](../src/Pagination.php), [src/Response.php](../src/Response.php) — declaration anatomy; the machine-readable refinement layer (phpdoc `@var`, attributes, `Describe`).
- [src/Providers/RouterDeclarationServiceProvider.php](../src/Providers/RouterDeclarationServiceProvider.php), [src/Providers/KernelDeclarationServiceProvider.php](../src/Providers/KernelDeclarationServiceProvider.php), [src/Providers/ViewDeclarationServiceProvider.php](../src/Providers/ViewDeclarationServiceProvider.php) — the runtime meaning of each attribute kind; proof that dispatch style varies while value shapes are uniform.
- [src/Manifest.php](../src/Manifest.php) — block keys and per-block `Describe` wiring.
- [src/Internal/Commands/ValidateCommand.php](../src/Internal/Commands/ValidateCommand.php), [src/Internal/Commands/MigrateCommand.php](../src/Internal/Commands/MigrateCommand.php), [src/LaravelDeclarationProvider.php](../src/LaravelDeclarationProvider.php), [src/DefaultProviders.php](../src/DefaultProviders.php) — command pattern, aliases, registration, package-fixed configuration precedent.
- [docs/declarative-schema-generator.md](declarative-schema-generator.md) — the single-class bootstrap plan this plan maintains the output of; §6.4 records the two deliberate deltas (hierarchy filter, flag kind).
- [docs/implementation/components/component-index.md](implementation/components/component-index.md) — the block → vendor class map; scope source for `SchemaMap` and the excluded-block list.
- [tests/Feature/PublicApiToolTest.php](../tests/Feature/PublicApiToolTest.php), [tests/Fixtures/PublicApi/](../tests/Fixtures/PublicApi/), [tests/Feature/ValidateCommandTest.php](../tests/Feature/ValidateCommandTest.php), [tests/Feature/InstallCommandTest.php](../tests/Feature/InstallCommandTest.php), [tests/TestCase.php](../tests/TestCase.php) — renderer and command test conventions, fixture manifests.
- `vendor/laravel/framework/src/Illuminate/Pagination/AbstractPaginator.php` (static presets at lines 596–660), `.../Illuminate/Macroable/Traits/Macroable.php` (`macro` at line 31), `.../Illuminate/View/Concerns/ManagesEvents.php` (`composer` at line 53), `.../Illuminate/Container/Container.php` (binding methods), `.../Illuminate/Contracts/Auth/Access/Gate.php` (`define`/`policy`) — the hierarchy/ trait/ interface evidence of §2.
- `docs/repos/laravel/docs/` — source for curated `description` prose (never consumed by the generator).