---
name: schema-generator-overview
task: >-
  Decompose `declarative-schema-generator.md` into vertical, context complete
  units of work; this file is the index and the shared context every unit assumes.
source: docs/declarative-schema-generator.md
units:
  - 01-projection-skeleton.md
  - 02-signature-shapes.md
  - 03-value-types.md
  - 04-merge-and-encoding.md
  - 05-command-write-path.md
  - 06-acceptance-router.md
---

# Schema Generator — Decomposition Overview

A script that generates the **schema** fragment for a YAML **manifest** block directly from a native Laravel class.

- Input: one native Laravel class, e.g. `Illuminate\Routing\Router`.
- Output: the `manifest.schema.json` `definitions.<block>` entry for that class.
- The plan is [declarative-schema-generator.md](../../declarative-schema-generator.md); these units are its vertical decomposition.
- No unit accounts for suspected errors in the hand-written `manifest.schema.json`: the **algorithm is authoritative**; curation is preserved by merge, never re-derived.

## Vocabulary

- `manifest` — the YAML file (`manifest/app.yml`) validated by the **schema**.
- `schema` — `manifest.schema.json`, draft-07 JSON Schema; the only validation layer.
- `native method` — the real Laravel method; source of truth for key casing, signature order, optionality, and types ([STYLE.md](../../../STYLE.md) Rules 1, 2).
- `block` — the top-level manifest key a declaration projects (`router`, `app`, …).
- `fragment` — the generated `definitions.<block>` JSON Schema object.
- `binding` / `setter` / `append` / `append-to` / `prepend-to` — the five attribute kinds that determine how a manifest value is forwarded at runtime (`src/Providers/RouterDeclarationServiceProvider.php` loops).

## The two layers (plan §3)

| Layer | Origin | Fate |
| --- | --- | --- |
| Structure — keys, order, value types, stub descriptions `-> method($a, $b)`, `TODO(...)` markers | reflection | regenerated every run by `render()` |
| Curation — `description` prose, `pattern`s, `items`, kind corrections | hand-authored in `manifest.schema.json` | preserved on merge |

A wrong default is *loud and cheap to fix by hand*: the generated stub description still names the native call, and a `TODO(<method>: $<param>)` marks every spot reflection could not decide.

## Architecture (plan §5)

```
src/Internal/SchemaGenerator.php            # pure static renderer — no I/O
src/Internal/Commands/GenerateSchemaCommand.php  # Artisan wrapper, @internal
```

Mirrors the `Api` split (`src/Internal/Mcp/Tools/Api.php`): pure `render()` core tested headlessly (`tests/Feature/PublicApiToolTest.php` style) + a thin Artisan shell tested via `$this->artisan(...)` (`tests/Feature/InstallCommandTest.php` style).

## Units — vertical slices, in execution order

| Unit | End-to-end behavior delivered | Tests prove | Depends on |
| --- | --- | --- | --- |
| [01-projection-skeleton.md](01-projection-skeleton.md) | `declaration:generate-schema '<class>'` prints a correct fragment for classes with simple setter signatures; unknown class fails natively; skipped methods reported | method filter (§4.1), declaration order, envelope, block key (§4.6), scalar/nullable/unknown value schemas, stub + `TODO` format, command print path, registration | — |
| [02-signature-shapes.md](02-signature-shapes.md) | multi-param and variadic methods project to their runtime forwarding shapes (`binding`, `append-to`, `append`) | kind precedence chain (§4.2), key schemas (§4.4), stub phrases, fallback for undecided signatures | 01 |
| [03-value-types.md](03-value-types.md) | every PHP type a signature can declare maps to a lossless schema or an honest `TODO` | §4.3 table completed: `array`, unions, nullability, `mixed`/`Closure`/class → `true` | 01 |
| [04-merge-and-encoding.md](04-merge-and-encoding.md) | regeneration into an existing curated schema preserves curation; encoding is canonical, 2-space, idempotent | `merge()` preservation rules, new-block wiring, `encode()` byte properties | 01–03 |
| [05-command-write-path.md](05-command-write-path.md) | `--out=<path>` merges the fragment into that schema file in place and reports; second run changes nothing | read → merge → encode → write round trip, idempotency, native failures | 01–04 |
| [06-acceptance-router.md](06-acceptance-router.md) | the single algorithm reproduces the `router` structure from the real vendor class end-to-end | golden key set/order for `Illuminate\Routing\Router`, per-key shapes, curated merge round trip | 01–05 |

## Layering rules

1. Each unit is a complete unit of behavior: implement it, then its tests pass, then move on.
2. Each unit keeps every previous unit's tests green. Fixtures are per-unit (`tests/Fixtures/SchemaGenerator/<Unit>.php`); a later unit must not change how an earlier unit's fixture classifies. (E.g. unit 02 adds kind rows *before* the setter row in the precedence chain; unit 01's fixture contains only 1-param setters plus a 3-param method that stays in the fallback forever.)
3. Every branch added by a unit is covered by that unit's tests — `composer check` enforces `--min=100` coverage over `src/`.

## Reconciliations with the plan (verified against reality)

The plan's rules (§4.1–§4.6) are the algorithm. Where the plan's illustrations meet vendor reality, the rule wins and the deviation is recorded:

1. **Trait methods pass the declaring-class filter** — plan §2 claims `Macroable::macro()` is excluded; PHP's `ReflectionMethod::getDeclaringClass()` reports the *using* class, so `macro`/`mixin`/`hasMacro`/`macroCall` project (verified: `Router::macro` declares `Illuminate\Routing\Router`). Parent methods are still excluded (`Application::bind` declares `Illuminate\Container\Container`). Unit 01 encodes the PHP-true behavior and proves it.
2. **Plan §6.1 assumed docblock types** — the real vendor signatures are mostly untyped (e.g. `pattern($key, $pattern)`, `matched($callback)`). Since §4.3 forbids docblock parsing (Rule 2.5), the algorithm emits `true`/description-only + `TODO` there, not the §6.1 strings. Unit 06 carries the full delta table. §6.1 is illustrative; §4.3/§4.2 are the algorithm.
3. **Encoding needs `JSON_UNESCAPED_UNICODE`** — `manifest.schema.json` contains 11 non-ASCII characters (em-dashes, `≈`); without the flag the first write would escape them. Unit 04 adds the flag with this evidence.
4. **Encoding is canonical, not byte-identical to today's file** — the current file keeps short arrays inline (prettier-style: `"type": ["object", "null"]`); PHP's `JSON_PRETTY_PRINT` expands every array. The plan's halving algorithm reproduces *its own* style byte-for-byte and is idempotent, verified by running it over the real file. Unit 04 documents this; the first `--out` write of an existing file reformats it once.
5. **Unmatched signatures need a deterministic fallback** — plan §4.2's four rows do not cover e.g. `model($key, $class, ?Closure $callback = null)` (3 params). The completing rule (unit 02): an undecided key gets a description-only schema + `TODO(<method>: <all params>)` — the same "invent nothing" principle as §4.3's unknown-type row.
6. **Merge is additive-only** — plan §5.1: existing descriptions are preserved, new keys appended, stale keys untouched. Implementation (unit 04): for an existing block, the whole curated envelope and every existing key object are left untouched; only missing keys are appended. This satisfies "regeneration never deletes" exactly and keeps every curated refinement (`pattern`, `items`, kind-corrected shapes) alive deterministically. Fresh structure remains visible through the default print mode.

## Conventions

- Fixtures: `tests/Fixtures/SchemaGenerator/`, namespace `ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator` (composer autoload-dev maps `Tests\` → `tests/`; PSR-4, one class per file, `declare(strict_types=1)` — modeled on `tests/Fixtures/PublicApi/`).
- Headless tests: `tests/Feature/SchemaGeneratorTest.php` — exact-array / exact-JSON assertions on the pure statics.
- Command tests: `tests/Feature/GenerateSchemaCommandTest.php` — `$this->artisan(...)` (testbench), temp files under `sys_get_temp_dir()`.
- Command names: signature `declaration:generate-schema`, alias `laravel-declaration:generate-schema` (`MigrateCommand` convention).
- Gate: `composer check` at the end of every unit (pint, rector, phpstan level 9, 100% coverage).

## Sources

- [docs/declarative-schema-generator.md](../../declarative-schema-generator.md) — the plan; §4 is the derivation specification each unit implements.
- [STYLE.md](../../../STYLE.md) — Rules 1–9 cited by the plan; Rule 2.5 (types from the signature), Rule 3.3 (native failures), Rule 6.1–6.2 (opt-in keys).
- [manifest.schema.json](../../../manifest.schema.json) — target artifact (draft-07, 2-space, `definitions`, curated prose style).
- [src/Internal/Mcp/Tools/Api.php](../../../src/Internal/Mcp/Tools/Api.php) — the reflection-filter and pure-static-render patterns copied here.
- [src/Providers/RouterDeclarationServiceProvider.php](../../../src/Providers/RouterDeclarationServiceProvider.php) — the five dispatch loops that justify §4.2's kind defaults.
- [src/Internal/Commands/ValidateCommand.php](../../../src/Internal/Commands/ValidateCommand.php), [src/Internal/Commands/MigrateCommand.php](../../../src/Internal/Commands/MigrateCommand.php), [src/LaravelDeclarationProvider.php](../../../src/LaravelDeclarationProvider.php) — command pattern, aliases, registration point.
- [tests/Feature/PublicApiToolTest.php](../../../tests/Feature/PublicApiToolTest.php), [tests/Fixtures/PublicApi/](../../../tests/Fixtures/PublicApi/), [tests/Feature/InstallCommandTest.php](../../../tests/Feature/InstallCommandTest.php) — test conventions.
- `vendor/laravel/framework/src/Illuminate/Routing/Router.php`, `.../Foundation/Application.php`, `.../Support/Traits/Macroable.php` — reflection targets.