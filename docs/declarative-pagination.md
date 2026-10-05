# Declarative Pagination — Implementation Plan (Tier 1 §1.1)

> Manifest forms in this document are the pre-engine block shapes; see docs/general-purpose-migration-plan.md §2.1 and README for the current forms.

> **Status: implemented.** This plan closed §1.1 of [declarative-tier1-remaining.md](declarative-tier1-remaining.md): the three missing `AbstractPaginator` preset methods (`useBootstrap()`, `useBootstrapThree()`, `useBootstrapFour()`) onto the existing `pagination:` block (`src/Pagination.php`, `Providers/PaginationDeclarationServiceProvider.php`). Final shape: the presets are wired through a `#[Preset]` **property attribute** + `DataModel::selected()` reflection dispatch (the `Kernel`/`Router` pattern) instead of the `presets` const sketched below — same key list, same order (property declaration order), zero model-side bookkeeping (§3).

Source of truth: `vendor/laravel/framework/src/Illuminate/Pagination` (`laravel/framework` **v13.33.0**), verified by direct source inspection on the date of this document, against package source (`src/`) and the shipped feature test (`tests/Feature/PaginationRegistrationTest.php`). Every claim below is a **claim → package source → vendor source → verdict** comparison per the repo's verification convention.

Goal: map the remaining native preset surface with **key = native method name** (Rule 1), **references through untouched** (Rule 2), **fail where Laravel fails** (Rule 3), **no cross-subsystem orchestration** (Rule 5) — and *simplify* the provider by replacing per-preset `if` blocks with one dynamic-dispatch loop over the native method names.

---

## 1. Verification — the claims and the remainder

### 1.1 Claim comparison (declarative-tier1-remaining.md §1.1 vs. source of truth)

| # | Claim (§1.1 / mapping doc) | Package source | Vendor source (v13.33.0) | Verdict |
|---|---|---|---|---|
| 1 | Missing: `AbstractPaginator::useBootstrapThree()` — signature `useBootstrapThree(): void`, Bootstrap 3 views | `src/Pagination.php` — only `defaultView`, `defaultSimpleView`, `useTailwind`, `useBootstrapFive` declared; no `useBootstrapThree` | `AbstractPaginator.php:638-642` — `public static function useBootstrapThree()` (no params; `@return void` docblock; no native `: void` annotation) sets `static::defaultView('pagination::bootstrap-3')` + `static::defaultSimpleView('pagination::simple-bootstrap-3')` (`:640-641`) | **True** |
| 2 | Missing: `AbstractPaginator::useBootstrapFour()` — signature `useBootstrapFour(): void`, Bootstrap 4 views | same | `AbstractPaginator.php:649-653` — sets `pagination::bootstrap-4` + `pagination::simple-bootstrap-4` (`:651-652`) | **True** |
| 3 | Missing: `AbstractPaginator::useBootstrap()` — "unversioned alias — delegates to `useBootstrapFour()`" | same | `AbstractPaginator.php:628-631` — body is the single statement `static::useBootstrapFour();` (`:630`); docblock says "Bootstrap 4 styling" | **True** |
| 4 | "Each preset is a single boolean `view:`-style key that sets `defaultView`/`defaultSimpleView` in one native call" | `Providers/PaginationDeclarationServiceProvider.php:21-27` — the two mapped presets are already dispatched as `Paginator::useTailwind()` / `Paginator::useBootstrapFive()` guarded by booleans | `AbstractPaginator.php:617-621, 660-664` — every preset (`useTailwind`, `useBootstrapFive`) is exactly two static assignments through the native setters | **True** |
| 5 | Mapped surface already correct (`defaultView`, `defaultSimpleView`, `useTailwind`, `useBootstrapFive`) | `src/Pagination.php:14-32` (nullable strings + bools with `Describe::default => false`), provider `:21-35` | `AbstractPaginator.php:596-599` (`defaultView($view)`), `:607-610` (`defaultSimpleView($view)`), statics initialized to `pagination::tailwind` / `pagination::simple-tailwind` (`:130,137`) | **True** |
| 6 | "The remaining `AbstractPaginator` statics (`resolveCurrentPath`, `currentPageResolver`, `queryStringResolver`, …) are runtime plumbing and stay unmapped" | not present in `src/` (and recorded as decided non-goal, §3 of the gap doc) | `AbstractPaginator.php` — `$currentPathResolver` (`:102`) / `resolveCurrentPath()` (`:496`), `$currentPageResolver` (`:109`) / `currentPageResolver()` (`:538`), `$queryStringResolver` (`:116`) / `queryStringResolver()` (`:564`), `$viewFactoryResolver` (`:123`) / `viewFactoryResolver()` (`:585`) — all request-scoped resolvers, not declaration surface | **True** |
| 7 | Doc line range "`AbstractPaginator.php:628-667`" for the three missing methods | — | `:628` = `useBootstrap()` start; `:660-664` = `useBootstrapFive()` body (closes `:664`; the quoted `:628-667` over-runs two lines into `getIterator()`'s docblock); the span covers all four bootstrap-family methods | **True** (range also includes the already-mapped `useBootstrapFive` — see §1.2; the two-line over-run is harmless) |

### 1.2 The remainder (what this plan closes)

- `src/Pagination.php` — three native preset method names are not declarable: `useBootstrap`, `useBootstrapThree`, `useBootstrapFour`. A user wanting Bootstrap 3/4 today must write imperative `Paginator::useBootstrapThree()` in a custom provider — the exact hand-written PHP the `pagination:` block exists to remove.
- `Providers/PaginationDeclarationServiceProvider.php` — with three more keys the current shape (one `if` per preset) grows to five `if` blocks. Every preset call is `Paginator::<nativeMethod>()`, i.e. the block is already a map from manifest key → native static method name; the if-blocks are hand-unrolled dispatch. The plan replaces them with one loop that dispatches the native method name (§2.1).
- `manifest.schema.json` `definitions.pagination` — must gain the three boolean properties (`additionalProperties: false` would reject them).
- Docs: [declarative-framework-api-mapping.md](declarative-framework-api-mapping.md) Domain 4 bullet (`- [/]`) and the "Pagination Engine" table row (`Partially Mapped`), plus §1.1 of the gap doc itself.

### 1.3 Native preset surface (v13.33.0, verified signatures)

All on `Illuminate\Pagination\AbstractPaginator` (inherited by `Paginator` and `LengthAwarePaginator`; neither redeclares them or the statics):

| Native method | Line | Body (verified) | Effective `defaultView` | Effective `defaultSimpleView` |
|---|---|---|---|---|
| `defaultView($view)` | `:596-599` | `static::$defaultView = $view` | *(explicit)* | *(unchanged)* |
| `defaultSimpleView($view)` | `:607-610` | `static::$defaultSimpleView = $view` | *(unchanged)* | *(explicit)* |
| `useTailwind()` | `:617-621` | `defaultView('pagination::tailwind')` + `defaultSimpleView('pagination::simple-tailwind')` | `pagination::tailwind` | `pagination::simple-tailwind` |
| `useBootstrap()` | `:628-631` | `static::useBootstrapFour()` | `pagination::bootstrap-4` | `pagination::simple-bootstrap-4` |
| `useBootstrapThree()` | `:638-642` | `defaultView('pagination::bootstrap-3')` + `defaultSimpleView('pagination::simple-bootstrap-3')` | `pagination::bootstrap-3` | `pagination::simple-bootstrap-3` |
| `useBootstrapFour()` | `:649-653` | `defaultView('pagination::bootstrap-4')` + `defaultSimpleView('pagination::simple-bootstrap-4')` | `pagination::bootstrap-4` | `pagination::simple-bootstrap-4` |
| `useBootstrapFive()` | `:660-664` | `defaultView('pagination::bootstrap-5')` + `defaultSimpleView('pagination::simple-bootstrap-5')` | `pagination::bootstrap-5` | `pagination::simple-bootstrap-5` |

All referenced views exist as shipped resources (`vendor/laravel/framework/src/Illuminate/Pagination/resources/views/`): `tailwind`, `simple-tailwind`, `bootstrap-3`, `simple-bootstrap-3`, `bootstrap-4`, `simple-bootstrap-4`, `bootstrap-5`, `simple-bootstrap-5` (plus `semantic-ui`, which has **no** preset method in v13.33.0 and is therefore not declarable under Rule 1).

### 1.4 Verified semantics the implementation must preserve

1. **Statics are shared on the `AbstractPaginator` lineage.** `Paginator` and `LengthAwarePaginator` do not redeclare `$defaultView`/`$defaultSimpleView`, so `static::$defaultView = $view` writes the single inherited storage — one dispatch on `Paginator::` configures both paginators. (This is also why the shipped test can read the values with `new ReflectionProperty(Paginator::class, 'defaultView')`.)
2. **`CursorPaginator` reads the `Paginator` statics directly.** `CursorPaginator.php:100` — `static::viewFactory()->make($view ?: Paginator::$defaultSimpleView, …)`. Dispatching on `Paginator` is therefore the complete surface: cursor pagination rendering follows the preset with no `CursorPaginator::` calls. (`CursorPaginator` extends `AbstractCursorPaginator`, which has no `defaultView` statics and no `useBootstrap*` methods — the §1.1 table's scope is exactly right.)
3. **Last call wins; explicit keys beat presets.** Each preset overwrites both statics, so native semantics are plain overwrite. The shipped provider applies presets *before* explicit `defaultView`/`defaultSimpleView`, and the shipped test pins explicit-views-win. Both orderings are preserved below; between presets, declaration order decides (property declaration order → `selected()` dispatch order, §3.2).
4. **Presets default to `false`, not to "applied".** Omitting a key must not touch the statics (`Describe::default => false` + the truthiness guard) — the block is declarative *configuration*, and only declared presets apply.

### 1.5 Dynamic dispatch passes the repo's phpstan level 9

The shipped dispatch (`Paginator::{$method}()` with `$method: string` from `DataModel::selected()` and `$Manifest->pagination->{$method}`) passes `composer check` (phpstan level 9, only the `property.uninitializedReadonly` ignore) — the exact shape `KernelDeclarationServiceProvider` and `RouterDeclarationServiceProvider` already ship (`$KernelContract->{$method}(...)`, `$Router->{$method}(...)`). Runtime: last-call-wins holds, `useBootstrap()` lands on bootstrap-4, and reflection reads the inherited statics.

---

## 2. Design

### 2.1 The mapping is the loop (Rule 1 + dynamic dispatch)

The manifest keys **are** the native `AbstractPaginator` method names — `useTailwind`, `useBootstrap`, `useBootstrapThree`, `useBootstrapFour`, `useBootstrapFive` — so the provider's job reduces to: *for each truthy preset key, call the native static method of that name*. The repo's attribute pattern (used by `Kernel` and `Router`) is the grounded shape: a `#[Preset]` marker attribute (`src/Attributes/Attributes/Preset.php`, `#[Attribute(Attribute::TARGET_PROPERTY)] final class Preset {}`) stacked on each preset property, and `Pagination::selected(Preset::class)` — the `DataModel::selected()` helper — returns the property names in declaration order. One `foreach` with `Paginator::{$method}()` replaces five hand-unrolled `if` blocks. Adding a future preset is then: one field carrying the attribute — no new provider branch, no separate const to keep in sync.

Dispatch target is the concrete `Illuminate\Pagination\Paginator` — the class the shipped provider and mapping doc name as the system of record. By §1.4(1)/(2) this single target covers `LengthAwarePaginator` and `CursorPaginator` rendering; nothing else is touched (Rule 5).

### 2.2 Order and override semantics (Rule 3)

- Presets apply in `#[Preset]` property declaration order; because each preset is a native overwrite of both statics, the **last truthy preset wins** — the same "last call wins" a user gets calling the methods imperatively. No special-casing, no conflict errors.
- Explicit `defaultView`/`defaultSimpleView` apply **after** the presets, so an explicit view beats any preset — the shipped behavior the existing test pins.

### 2.3 Failure posture (Rule 3)

No `method_exists` guard around the dispatch: every `#[Preset]` property is a code-level declaration whose name is the verified native method (§1.3). A mistyped property name should crash loudly at boot, not silently skip — the same posture as the rest of the package's dispatch surfaces (`Kernel`/`Router` dispatch unguarded through `selected()`, `Query::dispatchMethod` propagates Laravel's own `BadMethodCallException`).

### 2.4 Backward compatibility

Purely additive: three new `bool` properties with `Describe::default => false`, one new marker attribute, three schema booleans. Existing manifests keep their exact meaning (presets still apply when truthy; explicit views still win; omitted keys stay unapplied). `bin/bc-check.sh` (Roave) flags removals only; `bin/require-check.sh` sees no new dependencies (`Paginator` is already imported in the provider).

### 2.5 Alternatives considered

- **Keep one `if` per preset (current shape, +3 blocks).** Rejected: five copies of the same guard/dispatch is hand-unrolled dispatch that drifts; the loop is shorter, coverage-neutral (`--min=100` needs one branch set, not five), and self-extending.
- **Single `use:` map key (`use: {useBootstrapThree: true}`)** — one key whose value is a method-name → bool map. Rejected: `use` is a PHP reserved word, so no const/property can carry the manifest key's name (every other block names its const after the key — Rule 1); the bool-per-native-name fields stay the flatter shape. (Changing the shipped yml schema — including the two already-mapped keys `useTailwind`, `useBootstrapFive` — is acceptable per this plan's brief; flatness, not BC, is the blocker.)
- **Derive the preset list from `get_object_vars()`/`toArray()` filtering booleans.** Rejected: an accidental non-preset bool field (e.g. a future epilogue boolean like `View::$flushFinderCache`) would be dispatched — the explicit `#[Preset]` attribute is the auditable contract (the same selection role `#[Setter]`/`#[Append]`/`#[AppendTo]` play on `Kernel`/`Router`).
- **Dispatch on `AbstractPaginator::` instead of `Paginator::`.** Rejected: the mapping doc and shipped provider name `Paginator` as the surface; `AbstractPaginator` is abstract and its statics are equally reachable via `Paginator::`.

---

## 3. Code — complete examples

### 3.1 `src/Attributes/Attributes/Preset.php` (new, the repo's marker-attribute shape)

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Preset {}
```

### 3.2 `src/Pagination.php` (full replacement)

Property declaration order is the dispatch order (`DataModel::selected()` reflects in declaration order) — the order the `presets` const sketch had.

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Preset;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Pagination
{
    use DataModel;

    public const string defaultView = 'defaultView';

    #[Describe([Describe::nullable => true])]
    public ?string $defaultView;

    public const string defaultSimpleView = 'defaultSimpleView';

    #[Describe([Describe::nullable => true])]
    public ?string $defaultSimpleView;

    /** Native preset: `AbstractPaginator::useTailwind()` */
    #[Preset, Describe([Describe::default => false])]
    public bool $useTailwind;

    /** Native preset: `AbstractPaginator::useBootstrap()` — alias for `useBootstrapFour()`. */
    #[Preset, Describe([Describe::default => false])]
    public bool $useBootstrap;

    /** Native preset: `AbstractPaginator::useBootstrapThree()`. */
    #[Preset, Describe([Describe::default => false])]
    public bool $useBootstrapThree;

    /** Native preset: `AbstractPaginator::useBootstrapFour()`. */
    #[Preset, Describe([Describe::default => false])]
    public bool $useBootstrapFour;

    /** Native preset: `AbstractPaginator::useBootstrapFive()`. */
    #[Preset, Describe([Describe::default => false])]
    public bool $useBootstrapFive;
}
```

Each preset overwrites both default-view statics, so the last truthy key wins; explicit `defaultView` / `defaultSimpleView` apply after and override presets (§2.2).

### 3.3 `src/Providers/PaginationDeclarationServiceProvider.php` (full replacement)

The `Kernel`/`Router` dispatch shape verbatim: `selected(<Attribute>::class)` → truthy/null guard → dynamic call.

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Preset;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Pagination;

/** @internal */
class PaginationDeclarationServiceProvider extends ServiceProvider
{
    public function boot(?Manifest $Manifest = null): void
    {
        if (! $Manifest?->pagination instanceof Pagination) {
            return;
        }

        // Attribute-selected dispatch (Rule 1): every `#[Preset]` property is
        // the native `AbstractPaginator` method name — `selected()` returns the
        // properties in declaration order. Dispatching on `Paginator` covers
        // LengthAwarePaginator (shared statics) and CursorPaginator rendering
        // (reads Paginator::$defaultSimpleView).
        foreach (Pagination::selected(Preset::class) as $method) {
            if ($Manifest->pagination->{$method}) {
                Paginator::{$method}();   // ≙ Paginator::useTailwind() / useBootstrap() / useBootstrapThree() / useBootstrapFour() / useBootstrapFive()
            }
        }

        if ($Manifest->pagination->defaultView !== null) {
            Paginator::defaultView($Manifest->pagination->defaultView);
        }

        if ($Manifest->pagination->defaultSimpleView !== null) {
            Paginator::defaultSimpleView($Manifest->pagination->defaultSimpleView);
        }
    }
}
```

No changes to `src/Manifest.php` (the `pagination` property already exists), `src/DefaultProviders.php` (the provider is already registered, position 7), or `src/LaravelDeclarationProvider.php`.

### 3.4 Manifest example (what becomes declarable)

```yaml
# yaml-language-server: $schema=./manifest.schema.json

pagination:
  useBootstrapThree: true        # ≙ Paginator::useBootstrapThree()  → pagination::bootstrap-3
  # useBootstrap: true           # ≙ Paginator::useBootstrap() → useBootstrapFour()
  # useBootstrapFour: true       # ≙ Paginator::useBootstrapFour()  → pagination::bootstrap-4
  # useBootstrapFive: true       # ≙ Paginator::useBootstrapFive()  → pagination::bootstrap-5
  # useTailwind: true            # ≙ Paginator::useTailwind()       → pagination::tailwind
  # defaultView: pagination::custom              # applied after presets — wins
  # defaultSimpleView: pagination::simple-custom # applied after presets — wins
```

---

## 4. `manifest.schema.json` — `definitions.pagination` (full replacement)

```json
"pagination": {
    "description": "Illuminate\\Pagination\\Paginator configuration.",
    "type": [
        "object",
        "null"
    ],
    "additionalProperties": false,
    "properties": {
        "defaultView": {
            "type": [
                "string",
                "null"
            ]
        },
        "defaultSimpleView": {
            "type": [
                "string",
                "null"
            ]
        },
        "useTailwind": {
            "type": "boolean"
        },
        "useBootstrap": {
            "type": "boolean"
        },
        "useBootstrapThree": {
            "type": "boolean"
        },
        "useBootstrapFour": {
            "type": "boolean"
        },
        "useBootstrapFive": {
            "type": "boolean"
        }
    }
}
```

---

## 5. Tests — `tests/Feature/PaginationRegistrationTest.php` (full replacement)

Statics are process-global (§1.4(1)), so the suite resets them to the native defaults in a `beforeEach` and uses the `TestCase::manifest()` helper (temp YAML, auto-cleaned in `tearDown()`) — the same `withConfig` lifecycle as the shipped test, which inlines its own `tempnam`/`unlink` instead of the helper. Reflection reads on inherited statics mirror the shipped assertions; the helper names `defaultView()`/`defaultSimpleView()` collide with nothing else in `tests/` (verified).

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Feature;

use Illuminate\Pagination\Paginator;
use ReflectionProperty;
use ZeroToProd\LaravelDeclaration\Manifest;

beforeEach(function (): void {
    // Preset statics are process-global (AbstractPaginator lineage) — restore
    // the native defaults so assertions are order-independent.
    Paginator::defaultView('pagination::tailwind');
    Paginator::defaultSimpleView('pagination::simple-tailwind');
});

it('returns early when manifest has no pagination block', function (): void {
    $manifest = Manifest::from([]);
    expect($manifest->pagination)->toBeNull();

    $this->withConfig(['laravel-declaration.manifest' => $this->manifest('app: {}')]);

    expect($this->defaultView())->toBe('pagination::tailwind')
        ->and($this->defaultSimpleView())->toBe('pagination::simple-tailwind');
});

it('configures paginator styles and views from manifest', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        pagination:
          useTailwind: true
          useBootstrapFive: true
          defaultView: pagination::custom
          defaultSimpleView: pagination::simple-custom
        YAML)]);

    // Explicit views apply after presets (§2.2) — explicit wins.
    expect($this->defaultView())->toBe('pagination::custom')
        ->and($this->defaultSimpleView())->toBe('pagination::simple-custom');
});

it('maps useBootstrapThree to the bootstrap-3 views', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        pagination:
          useBootstrapThree: true
        YAML)]);

    expect($this->defaultView())->toBe('pagination::bootstrap-3')
        ->and($this->defaultSimpleView())->toBe('pagination::simple-bootstrap-3');
});

it('maps useBootstrapFour to the bootstrap-4 views', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        pagination:
          useBootstrapFour: true
        YAML)]);

    expect($this->defaultView())->toBe('pagination::bootstrap-4')
        ->and($this->defaultSimpleView())->toBe('pagination::simple-bootstrap-4');
});

it('maps the useBootstrap alias to the bootstrap-4 views', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        pagination:
          useBootstrap: true
        YAML)]);

    // useBootstrap() delegates to useBootstrapFour() natively (AbstractPaginator.php:630).
    expect($this->defaultView())->toBe('pagination::bootstrap-4')
        ->and($this->defaultSimpleView())->toBe('pagination::simple-bootstrap-4');
});

it('applies presets in declaration order — the last truthy preset wins', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        pagination:
          useTailwind: true
          useBootstrapThree: true
        YAML)]);

    expect($this->defaultView())->toBe('pagination::bootstrap-3')
        ->and($this->defaultSimpleView())->toBe('pagination::simple-bootstrap-3');
});

it('leaves the default views when every preset is omitted or false', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        pagination:
          useBootstrap: false
          useBootstrapThree: false
          useBootstrapFour: false
          useBootstrapFive: false
          useTailwind: false
        YAML)]);

    expect($this->defaultView())->toBe('pagination::tailwind')
        ->and($this->defaultSimpleView())->toBe('pagination::simple-tailwind');
});

/** Read the inherited `AbstractPaginator` statics through `Paginator` (§1.4(1)). */
function defaultView(): string
{
    return (string) new ReflectionProperty(Paginator::class, 'defaultView')->getValue();
}

/** @see defaultView() */
function defaultSimpleView(): string
{
    return (string) new ReflectionProperty(Paginator::class, 'defaultSimpleView')->getValue();
}
```

Notes:

- Function names in a Pest file are file-scoped helpers here; if the repo convention prefers closures, inline `new ReflectionProperty(...)->getValue()` per assertion (the shipped test's pattern). Either shape satisfies `--min=100` (the dynamic dispatch is one branch set, covered once by the preset tests; every false-side guard is covered by the negative tests).
- `Manifest::from([])` still exercises the DataModel defaults (new bools default `false`); the YAML tests exercise the full `boot()` path through `LaravelDeclarationProvider` → `PaginationDeclarationServiceProvider`.
- No fixture file under `tests/Fixtures/manifest/` is required (no routes/middleware involved); the `gate.yml` fixture-file pattern remains available if a shared example manifest is wanted later.

---

## 6. Documentation sync

1. [declarative-framework-api-mapping.md](declarative-framework-api-mapping.md):
   - Domain 4 bullet (line 146; mirrored by the checklist bullet at line 32): `- [/] **Pagination View Resolvers & Styling** …` → `- [x]` with "(all five presets and both default views mapped)".
   - "Pagination Engine" table row (line 241): Status `Partially Mapped` → `Mapped`; (also correct the row's `AbstractPaginator.php:628-667` range to `:628-664`); **Gap** → `none.` with the mapped list `defaultView`, `defaultSimpleView`, `useTailwind`, `useBootstrap`, `useBootstrapThree`, `useBootstrapFour`, `useBootstrapFive`.
2. [declarative-tier1-remaining.md](declarative-tier1-remaining.md) §1.1: mark closed with a pointer to this plan (keep the runtime-statics note — it is repeated as a decided non-goal in §3).
3. `manifest.schema.json` is consumed by `Internal/Commands/ValidateCommand` — the schema update (§4) must land in the same commit as the keys.

---

## 7. Acceptance checklist

- [x] `composer fix` before `check` — pint/rector green over the attribute-stacked properties and the `{$method}` dispatch.
- [ ] `composer check` — lint (pint), rector-lint, phpstan level 9 (`--min=100` coverage, bc-check) green. §1.5 covers the dynamic dispatch shape.
- [ ] `composer mcp list` — no MCP tool changes expected (the server reads the schema, which gains three booleans).
- [ ] Docs updated per §6; gap doc §1.1 closed.
- [ ] Manual smoke: `manifest/app.yml` with `pagination: {useBootstrapThree: true}` renders `pagination::bootstrap-3` links.