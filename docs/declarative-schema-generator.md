# Declarative Schema Generator — Implementation Plan

A script that generates the **schema** for a YAML **manifest** block directly from a native Laravel class.

- Input: one native Laravel class, e.g. `Illuminate\Routing\Router`.
- Output: the `manifest.schema.json` fragment for that class (the `definitions.<block>` entry).
- No code is implemented by this document; it is the plan.

## 0. Vocabulary

Terms are from [STYLE.md](../STYLE.md):

- `manifest` — the YAML file (`manifest/app.yml`) validated by the **schema**.
- `schema` — `manifest.schema.json`, draft-07 JSON Schema; the only validation layer.
- `declaration class` — `final readonly` class mirroring exactly one native Laravel class; one attributed `const`+property pair per native method.
- `native method` — the real Laravel method; source of truth for key casing, signature order, optionality, and types (Rules 1, 2).
- `binding` / `setter` / `append` / `append-to` / `prepend-to` — the five attribute kinds that determine how a manifest value is forwarded.
- `block` — the top-level manifest key a declaration projects (`router`, `app`, `kernel`, …).
- `fragment` — the generated `definitions.<block>` JSON Schema object.

## 1. Understand the source code (what each file proves)

| Source | What it proves for this plan |
| --- | --- |
| `manifest.schema.json` | The target artifact. Draft-07, 2-space indented, root `properties.<block>` → `#/definitions/<block>`, shared primitives in `definitions` (`classString`, `reference`, `phpFile`, `closure`, `closureList`, `binding`, …). Per-key `description` strings carry curated semantics. |
| `src/Internal/Mcp/Tools/Api.php` | The canonical reflection pattern: iterate a directory/namespace, filter to **public, declaring-class-owned, non-`@internal`** members, render signatures from `ReflectionMethod`/`ReflectionParameter`, serialize default values with `json_encode`/`var_export`. `Api::render($directory, $namespace)` is a pure static entry point tested headlessly — this plan copies that shape. |
| `src/Router.php` | Declaration-class anatomy: `const string <key> = '<key>'` + docblock + `#[Key, Binding\|Setter\|Append\|AppendTo\|PrependTo, Describe([...])]` + typed property, one pair per native method, in native declaration order. |
| `src/Providers/RouterDeclarationServiceProvider.php` | The five dispatch loops define exactly what each attribute kind means at runtime: **Binding** → `->method($key, $value)` per entry; **Setter** → one `->method($value)` when non-null; **PrependTo/AppendTo** → per-item calls over `(array)$middlewares` (reversed / in order); **Append** → one call per list item. These loops are the justification for the shape rules in §4. |
| `src/Providers/KernelDeclarationServiceProvider.php` | Same five loops on a second component — confirms the dispatch shapes are uniform across components. |
| `src/Manifest.php` | Root wiring: `#[Describe([Describe::nullable => true])] public ?Router $router;` per block — what a new block must add. |
| `src/Internal/Commands/ValidateCommand.php` | The internal-command pattern to follow (`@internal`, `protected $signature`, `handle(): int`, native failure output). Also proves the schema is loaded via `file://` realpath — the generator only edits the file, never moves it. |
| `src/Internal/Commands/MigrateCommand.php` | Command registration/alias convention: `protected $aliases = ['laravel-declaration:migrate'];`. |
| `src/LaravelDeclarationProvider.php` | Commands are registered via `$this->commands([...])` (Install, Validate, Migrate) — where the new command is added. |
| `tests/Feature/PublicApiToolTest.php` | How reflection renderers are tested: pure static `render()` against fixture directories, exact-string assertions. |
| `tests/Fixtures/PublicApi/*.php` | Fixture style for varied signatures: unions, defaults, variadics, enums, references, `@internal`, traits, enums, interfaces. Reused as the model for new generator fixtures. |
| `tests/Feature/InstallCommandTest.php` | Artisan command testing convention via `$this->artisan(...)` in testbench. |
| `docs/implementation/components/component-index.md` | The block → native class map. Proves a class can serve more than one block (`router` and `routes` both → `Illuminate\Routing\Router`); under this plan the single supported block key is the derived one (§4.6), and alias blocks are hand-written. |

## 2. Understand the vendor source (the reflection target)

- `vendor/laravel/framework/src/Illuminate/Routing/Router.php` — public methods `pattern`, `model`, `bind`, `middlewareGroup`, `aliasMiddleware`, `prependMiddlewareToGroup`, `pushMiddlewareToGroup`, `removeMiddlewareFromGroup`, `singularResourceParameters`, `resourceParameters`, `resourceVerbs`, `matched`, … These are the keys of the existing hand-written `router` fragment, character-for-character (Rule 1.1–1.3).
- `vendor/laravel/framework/src/Illuminate/Foundation/Application.php` (extends `Container`) — proves the **declaring-class filter** matters: `bind`/`singleton` are declared on `Container`; an `Application` projection must not swallow them (Rule 0.4: one declaration class mirrors exactly one native class; the parent gets its own projection).
- `vendor/laravel/framework/src/Illuminate/Support/Traits/Macroable.php` — `macro()` is trait-inherited; the declaring-class filter excludes it automatically.
- Reference docs per component live in `docs/repos/laravel/docs/` (`routing.md`, `container.md`, …) — used later for curated `description` prose, never by the generator itself.

## 3. Decomposition — what reflection can and cannot decide

The generator is honest about the boundary between **derivable structure** and **curated semantics**:

**Derivable from `ReflectionClass` (authoritative, per Rules 2.3–2.5):**

1. The key set: native public method names, declared in the class, not `@internal`, not `__*` magic (filter proven by `Api::methods()`).
2. Key order: native declaration order (`getMethods()` preserves it) — vertical alignment with `src/Router.php` (Rule 8.1).
3. Parameter names, order, types, unions, nullability, variadics — the argument-forwarding shape (Rule 2.3–2.5).
4. Docblock summaries — `Api::summary()` already extracts them.

**Not derivable (mapping decisions, corrected by hand in `manifest.schema.json` after generation):**

1. The **attribute kind**. Proof: `Router::matched(string $callback)` and `Router::resourceVerbs(array $verbs)` have indistinguishable arity-1 shapes, yet `matched` is `Append` (one call per item) and `resourceVerbs` is `Setter` (one call with the whole map) — see `src/Router.php`. Likewise `prependMiddlewareToGroup` and `pushMiddlewareToGroup` share the exact signature `($group, string|array $middleware)` but differ only in order semantics (`PrependTo` vs `AppendTo`).
2. **Semantic prose** in `description` (`"A route's own where wins."`, `"404 when null"`) — comes from `docs/repos/laravel/docs/`, not reflection.
3. **Refinements PHP types cannot express**: `array` of strings vs map of class-strings, `reference` patterns, `.php` file forms.

**Consequence — the generator emits two layers:**

| Layer | Origin | Merge behavior |
| --- | --- | --- |
| Structure (keys, order, value types, stub descriptions `-> method($a, $b)`, `TODO(...)` markers) | reflection | regenerated every run |
| Curation (`description` prose, `pattern`s, `items`, kind corrections) | hand-authored in `manifest.schema.json` | **preserved on merge** |

A wrong default is *loud and cheap to fix by hand*: the generated stub description still names the native call, and a `TODO(<method>: $<param>)` marks every spot reflection could not decide — so regeneration is safe to re-run and manual fixes survive it.

## 4. Derivation specification

### 4.1 Method filter

Identical rules to `Api::classes()`/`Api::methods()` (`src/Internal/Mcp/Tools/Api.php`):

- method is public;
- `$method->getDeclaringClass()->getName() === $class` (declaring-class-owned — parent methods belong to the parent's projection, Rule 0.4);
- docblock does not contain `@internal`;
- name does not start with `__` (no constructors/magic — a manifest cannot construct);
- at least one non-by-reference parameter (STYLE 2.1: *pass references through untouched* — a YAML scalar cannot be a PHP reference, so such methods are not declarable; they are listed under "skipped" in the output, never silently dropped).

### 4.2 Attribute-kind defaults (deterministic, from the dispatch loops)

The runtime meaning of each kind is fixed by `RouterDeclarationServiceProvider::boot()` / `KernelDeclarationServiceProvider::boot()`. The defaults below choose the **most common** shape per signature; any key can be corrected by hand in `manifest.schema.json` after generation (the merge never regenerates curated prose — §3).

| Native signature shape | Default kind | Runtime call (provider loop) |
| --- | --- | --- |
| exactly 2 params, first scalar (`string\|int`), second any | `binding` | `foreach ($Manifest->block->$method as $key => $value) $X->$method($key, $value);` |
| `(scalar $name, string\|array $x)` — second is a `string\|array` union | `append-to` | per-item calls in declaration order (hand-correctable to `prepend-to`, which reverses) |
| exactly 1 non-variadic param | `setter` | `if ($value !== null) $X->$method($value);` |
| variadic-only or list-of-callables (`string ...$handlers`, `$callback` lists) | `append` | one call per list item |

Justification for the 1-param default being `setter` despite `matched($callback)` being `append` in `src/Router.php`: a wrong default is *loud and cheap to fix* (one hand edit, and the generated stub description still names the native call), whereas inventing semantic heuristics ("param named `$callback` ⇒ append") would violate Rule 1.4 (no invented nouns) and Rule 3.4 (no validation/opinions). Zero-parameter methods are skipped and reported.

### 4.3 PHP type → JSON Schema (lossless core)

Types come from the native signature only — **docblock `@param` tags are not parsed** (Rule 2.5: types come from the native method; the signature is the machine-readable truth, docblocks are prose).

| Native type | Generated JSON Schema |
| --- | --- |
| `string` | `{"type":"string"}` |
| `int` | `{"type":"integer"}` |
| `float` | `{"type":"number"}` |
| `bool` | `{"type":"boolean"}` |
| `array` | `{"type":["array","object"]}` — a PHP array is both list and map; curated fragments narrow this (`items`, `additionalProperties`) |
| `?T` (nullable scalar) | `{"type":["<T>","null"]}` |
| union `A\|B` of scalars | `{"type":["<A>","<B>"]}` (deduplicated) |
| union involving `$ref` | `{"anyOf":[ … ]}` — draft-07 ignores siblings of `$ref` |
| `T ...$x` (variadic) | `{"type":"array","items":<T-schema>}` |
| class/interface/enum/`Closure`/mixed/untyped | `true` (any) + `TODO(<method>: $<param>)` appended to the key's stub description — the generator refuses to invent semantics it cannot read from the signature |

Native parameter **defaults are not emitted** as schema `default`: every manifest key is opt-in (Rule 6.1–6.2: missing key ⇒ no call), so a native default like `singularResourceParameters($singular = true)` is only reachable as "don't call at all" — which the curated description records (`"absent key -> Laravel default (true)"`), exactly as the existing `router` fragment does.

### 4.4 Key schema per kind

- `binding` → `{"type":"object","additionalProperties":<param-2 schema>}` (one call per entry; entry key is the first param).
- `setter` → `<param-1 schema>` (whole value in one call).
- `append` → `{"type":"array","items":<param-1 schema>}` (one call per item).
- `append-to` / `prepend-to` → `{"type":"object","additionalProperties":{"anyOf":[<param-2 schema as scalar>, <param-2 array form>]}}` — `string|array` unions become the existing `anyOf: [string, array-of-string]` shape.

### 4.5 Stub description

Every generated key gets the native call as its description stub, matching the prose pattern already in `manifest.schema.json` (`"-> bind($abstract, $concrete)"`, `"-> instance($abstract, $instance)"`):

```
-> pattern($key, $pattern), one call per entry
-> matched($callback), one call per item
-> resourceVerbs($verbs), one call with the whole value
-> prependMiddlewareToGroup($group, $middleware), one call per item, reversed
-> singularResourceParameters($singular) when the key is present
```

This satisfies Rule 9.6 (the docblock states the exact native call per entry) for the schema description.

### 4.6 Block key

The block key is derived: `lcfirst(<class basename>)` — `Illuminate\Routing\Router` → `router`, `Illuminate\View\View` → `view`. This matches the `src/Manifest.php` property names exactly.

The component index proves a class can serve more than one block (`router` **and** `routes` both project `Illuminate\Routing\Router`); the derived key is the one this command supports — alias blocks like `routes` remain hand-written. The derived block key is reported in the output header so a wrong derivation is visible immediately.

## 5. Architecture

```
src/Internal/SchemaGenerator.php            # pure static renderer — no I/O
src/Internal/Commands/GenerateSchemaCommand.php  # Artisan wrapper, @internal
```

Mirrors the `Api` split: pure `render()` core + thin presentation shell (`Api::render` vs `Api::handle`), so the renderer is testable headlessly like `PublicApiToolTest::renderFixtures()`.

### 5.1 `SchemaGenerator`

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

/** @internal */
final class SchemaGenerator
{
    /**
     * Renders the `definitions.<block>` fragment for one native class.
     *
     * @param  class-string  $class      the native Laravel class to project
     * @param  string  $block           the manifest block key (`router`, `app`, …)
     * @return array<string, mixed>     the JSON-decodable definition object (not encoded — the caller encodes)
     *
     * @throws ReflectionException      when $class does not exist (native failure, Rule 3.3)
     */
    public static function render(string $class, string $block): array;

    /**
     * Merges a rendered fragment into the parsed manifest.schema.json.
     * Existing per-key `description` strings are preserved; new keys are appended
     * in native declaration order; keys no longer native are left untouched
     * (regeneration never deletes).
     *
     * @param  array<string, mixed>  $schema   parsed manifest.schema.json
     * @param  array<string, mixed>  $fragment from render()
     * @return array<string, mixed>  the updated schema, ready to re-encode
     */
    public static function merge(array $schema, string $block, array $fragment): array;
}
```

Key details, each backed:

- **Reflection only, no filesystem** in `render()` — the command owns I/O; `Api::render()` proved this split testable.
- **Order** = native `getMethods()` declaration order, filtered (§4.1) — matches how `src/Router.php` lists pairs.
- **Draft-07 `$ref` sibling rule** is why union-with-ref values use `anyOf` (§4.3) — the existing `prependMiddlewareToGroup` fragment already uses `anyOf` for this reason.
- **`merge()` never rewrites curated prose**: when the existing fragment already has a key, its `description` wins. This is what makes regeneration safe to re-run.
- **Encoding**: `json_encode(…, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)` then `preg_replace_callback('/^( +)/m', static fn ($m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json)` — `JSON_PRETTY_PRINT` indents in exact multiples of 4, so halving reproduces the repo's 2-space style byte-for-byte.
- **`$schema` header, root `properties`, and all other definitions are untouched**; only `definitions.<block>` (and, for a new block, `properties.<block>`) change. `ValidateCommand` resolves the schema by `realpath` — the generator must keep the path stable.

### 5.2 `GenerateSchemaCommand`

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Commands;

use Illuminate\Console\Command;
use JsonException;
use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;

/** @internal */
class GenerateSchemaCommand extends Command
{
    /** @var string */
    protected $signature = 'declaration:generate-schema
        {class : The native Laravel class FQCN, e.g. Illuminate\\Routing\\Router}
        {--out= : Write the merged manifest.schema.json (default: print the fragment)}';

    /** @var string */
    protected $description = 'Generate manifest.schema.json definitions from a native Laravel class';

    public function handle(): int
    {
        // 1. class_exists / interface_exists / enum_exists -> ReflectionException otherwise
        // 2. block key = lcfirst(class basename)            (§4.6)
        // 3. SchemaGenerator::render($class, $block)
        // 4. --out: read manifest.schema.json, merge, re-encode (2-space), write; report added keys
        //    no --out: print the fragment JSON to stdout (default)
    }
}
```

Registration — one line added to the existing list in `src/LaravelDeclarationProvider.php` (which already registers `InstallCommand`, `ValidateCommand`, `MigrateCommand` via `$this->commands([...])`), plus the `declaration:generate-schema` alias convention from `MigrateCommand`.

## 6. Worked example — `Illuminate\Routing\Router`

Command:

```bash
php artisan declaration:generate-schema 'Illuminate\Routing\Router'
```

prints the fragment to the screen; `--out=manifest.schema.json` writes the merged file instead.

### 6.1 Generated fragment (defaults only, before curation)

```json
{
  "description": "Illuminate\\Routing\\Router methods: every key is a method name, its value the argument(s).",
  "type": ["object", "null"],
  "additionalProperties": false,
  "properties": {
    "pattern": {
      "description": "-> pattern($key, $pattern), one call per entry",
      "type": "object",
      "additionalProperties": { "type": "string" }
    },
    "model": {
      "description": "-> model($key, $class), one call per entry",
      "type": "object",
      "additionalProperties": { "type": "string" }
    },
    "bind": {
      "description": "-> bind($key, $binder), one call per entry TODO(bind: $binder)",
      "type": "object",
      "additionalProperties": true
    },
    "middlewareGroup": {
      "description": "-> middlewareGroup($name, $middleware), one call per entry",
      "type": "object",
      "additionalProperties": { "type": "array" }
    },
    "pushMiddlewareToGroup": {
      "description": "-> pushMiddlewareToGroup($group, $middleware), one call per item",
      "type": "object",
      "additionalProperties": { "anyOf": [{ "type": "string" }, { "type": "array" }] }
    },
    "singularResourceParameters": {
      "description": "-> singularResourceParameters($singular) when the key is present",
      "type": "boolean"
    },
    "resourceVerbs": {
      "description": "-> resourceVerbs($verbs), one call with the whole value",
      "type": ["array", "object"]
    },
    "matched": {
      "description": "-> matched($callback) when the key is present TODO(matched: $callback)",
      "type": "string"
    }
  }
}
```

Read against the hand-written fragment in `manifest.schema.json`, this demonstrates exactly where the two layers meet:

- `pattern`/`model`/`pushMiddlewareToGroup`/`singularResourceParameters` come out **already correct** — pure signature shape.
- `bind` gets structure (`object` map) but `true` values + a `TODO` — the `Class@method` pattern is hand-curated afterward.
- `matched` defaults to `setter` (arity-1 rule) — corrected by hand to `append`; the `TODO` marks it.
- `middlewareGroup` gets `{"type":"array"}` without `items` — `items: {type: string}` is hand-curated.
- Curated prose ("A route's own `where` wins.") is **preserved by `merge()`**, never regenerated.

## 7. Test plan

Fixture native classes under `tests/Fixtures/SchemaGenerator/` modeled on `tests/Fixtures/PublicApi/` — one class exercising every derivation rule:

- scalar/union/nullable/variadic/array params; untyped and class-typed params (the `TODO`/`true` path);
- a by-reference param (the skipped path);
- a `@internal` method and a `__construct` (the filtered path);
- inherited methods (proving the declaring-class filter).

Feature tests (`tests/Feature/GenerateSchemaCommandTest.php`), following `InstallCommandTest` (`$this->artisan(...)`) and `PublicApiToolTest` (exact-string assertions):

1. `render()` on the fixture class produces the exact expected fragment (keys in declaration order, §4.2/§4.3 tables).
2. `merge()` preserves existing `description` prose and appends new keys; other definitions untouched (diff the decoded JSON).
3. Command prints the fragment without `--out` (default); writes an idempotent file with `--out` (run twice, second run changes nothing but regenerates structure).
4. Failure mode: unknown class (`ReflectionException`) — fails natively (Rule 3).
5. `composer check` gates: pint, rector, phpstan, **100% coverage** (`composer.json` `check` script), so every branch of `SchemaGenerator` needs a test.

## 8. Execution order

1. `src/Internal/SchemaGenerator.php` — filter (§4.1), kind defaults (§4.2), type map (§4.3), per-key schema (§4.4), stub descriptions (§4.5), `render()`.
2. `SchemaGenerator::merge()` — curation preservation, ordering, 2-space encoding.
3. `src/Internal/Commands/GenerateSchemaCommand.php` + registration in `src/LaravelDeclarationProvider.php`.
4. Fixtures + Feature tests (§7).

## 9. Acceptance checklist

- [ ] Every generated key matches a **native method** name character-for-character (Rule 1).
- [ ] Types/order/optionality come from the native signature; docblocks never drive structure (Rule 2).
- [ ] The generator invents nothing: unknown types are `true` + `TODO`, skipped methods are reported (Rules 1.4, 3).
- [ ] Regeneration never loses curated `description` prose.
- [ ] Output is byte-compatible with the repo's 2-space JSON style; `$schema` header and unrelated definitions untouched.
- [ ] Stateless and `@internal`; pure `render()` core, I/O only in the command (Rule 4).
- [ ] `composer check` passes.

## 10. Sources

- [STYLE.md](../STYLE.md) — Rules 1–9, Rule 7 kind table, Canonical Skeleton, checklist.
- [manifest.schema.json](../manifest.schema.json) — target artifact; draft-07; shared definitions; curated `description` style; `anyOf` union precedent.
- [src/Internal/Mcp/Tools/Api.php](../src/Internal/Mcp/Tools/Api.php) — reflection filter, signature rendering, default-value serialization, pure-static render + fixture-test pattern.
- [src/Router.php](../src/Router.php) — declaration anatomy; proof that attribute kind is not signature-derivable (`matched` vs `resourceVerbs`).
- [src/Providers/RouterDeclarationServiceProvider.php](../src/Providers/RouterDeclarationServiceProvider.php), [src/Providers/KernelDeclarationServiceProvider.php](../src/Providers/KernelDeclarationServiceProvider.php) — runtime meaning of each attribute kind (basis of §4.2).
- [src/Manifest.php](../src/Manifest.php) — block-key property names; basis of the `lcfirst(basename)` derivation (§4.6).
- [src/Internal/Commands/ValidateCommand.php](../src/Internal/Commands/ValidateCommand.php), [src/Internal/Commands/MigrateCommand.php](../src/Internal/Commands/MigrateCommand.php), [src/LaravelDeclarationProvider.php](../src/LaravelDeclarationProvider.php) — command pattern, aliases, registration.
- [docs/implementation/components/component-index.md](implementation/components/component-index.md) — block↔class map; proof alias blocks exist beyond the derived key.
- [tests/Feature/PublicApiToolTest.php](../tests/Feature/PublicApiToolTest.php), [tests/Fixtures/PublicApi/](../tests/Fixtures/PublicApi/), [tests/Feature/InstallCommandTest.php](../tests/Feature/InstallCommandTest.php) — renderer and command test conventions.
- `docs/repos/zero-to-prod/data-model/src/Describe.php`, `src/Manifest.php` — `Describe::default`/`Describe::nullable` semantics.
- `vendor/laravel/framework/src/Illuminate/Routing/Router.php`, `.../Foundation/Application.php`, `.../Support/Traits/Macroable.php` — reflection targets; declaring-class and inheritance behavior.
- `docs/repos/laravel/docs/` — source for curated `description` prose (never consumed by the generator).
