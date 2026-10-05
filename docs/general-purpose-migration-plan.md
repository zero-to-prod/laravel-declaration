# General-Purpose Manifest Migration Plan

This package is evolving from *a Laravel-specific declarative manifest* to *a general-purpose
manifest engine*: deterministic tooling generates a manifest schema from an **arbitrary list of
classes**, the generated schema validates the declarations in the manifest, and a **single
runtime engine** maps the manifest onto the underlying classes those declarations name. Every
bespoke, per-component code path in the current implementation violates that end state and is
listed and retired here.

This plan **supersedes** the per-component scaffolding flow in
[declarative-manifest-schema-generator.md](declarative-manifest-schema-generator.md) and
**completes** the whole-file flow in
[generate-manifest-schema-plan.md](generate-manifest-schema-plan.md) — its run order (§3) and
re-merge gate (§5) are adopted wholesale in Phase 2. It also supersedes one rule of that plan
(§4.1, definition key = FQCN verbatim) for the reason given in §4.4.

No code is implemented by this document except the code written inside the phases — each phase
is code complete against the current source tree.

## 0. State

| Artifact | Lines | Role today |
| --- | --- | --- |
| [src/Internal/SchemaGenerator.php](../src/Internal/SchemaGenerator.php) | 548 | PHP-Parser engine: `render()` projects one class's declarable methods into a definition fragment; `merge()` is append-only; `encode()` is the 2-space encoder; `key()` infers one of four kinds (`binding`/`appendTo`/`append`/`setter`, else `undecided`) |
| [src/Internal/Commands/GenerateManifestSchemaCommand.php](../src/Internal/Commands/GenerateManifestSchemaCommand.php) | 102 | `declaration:generate-manifest-schema {classes*} {--out=}` — append-only merge into an **existing** `manifest.schema.json`; pre-flight per class; `--report`/`--check` absent |
| [manifest.schema.json](../manifest.schema.json) | — | Draft-07, 2-space. Root `properties` = 18 blocks. `definitions` = 35 entries: 5 FQCN-keyed class projections, 18 curated-named class projections, 12 shared primitives (`classString`, `reference`, `phpFile`, `closure`, `closureList`, `concrete`, `binding`, `bindingIf`, `stringOrList`, `extension`, `config`, + aliases) |
| [src/Manifest.php](../src/Manifest.php) | 126 | Hand-authored root wiring: one `const` + nullable/`Collection` property per block; `Describe` casts; the typed mirror the whole runtime reads |
| [src/App.php](../src/App.php) … [src/TableDefinition.php](../src/TableDefinition.php) (23 files) | ~1,600 | Hand-written declaration classes mirroring native Laravel classes 1:1, `final readonly` + `DataModel` + dispatch attributes |
| [src/Providers/*DeclarationServiceProvider.php](../src/Providers) (13 files) | ~1,270 | One bespoke provider per component: hand-rolled per-key loops, per-method special cases, duplicated reference/path/callable conventions |
| [src/Attributes/Attributes/](../src/Attributes/Attributes) (36 files) | — | Dispatch taxonomy (`Key`, `Binding`, `Setter`, `Append`, `AppendTo`, `PrependTo`, `Conditional`, `Path`, `Preset`, `ClassDefault`) + a dead taxonomy nobody consumes (`ColumnModifier`, `ColumnType`, `Composer`, `ForeignKeyModifier`, `Hook`, `Location`, `Redirect`, `TableConstraint`, `TableOption`, `ViewNamespace`) |
| [src/Internal/BlueprintMethodKind.php](../src/Internal/BlueprintMethodKind.php), [Guards.php](../src/Internal/Guards.php), [GuardKind.php](../src/Internal/GuardKind.php), [ActionGuard.php](../src/Internal/ActionGuard.php), [Guard.php](../src/Internal/Guard.php) | ~430 | A second, parallel bespoke mapping engine for the `schema` block: `@return`-doc-comment classification, hardcoded return-kind map, guard taxonomy, lifecycle blocklist |
| [src/BlueprintAction.php](../src/BlueprintAction.php), [TableDefinition.php](../src/TableDefinition.php) | 250 | Blueprint argument matching (named/positional), modifier validation, apply() |
| [src/Internal/Commands/MigrateCommand.php](../src/Internal/Commands/MigrateCommand.php) | 116 | `declaration:migrate` — fixed lifecycle order, bespoke guard loop |
| [src/Internal/Commands/ValidateCommand.php](../src/Internal/Commands/ValidateCommand.php) | 59 | `declaration:validate` — YAML → `BaseConstraint::arrayToObjectRecursive` → `Validator` against `manifest.schema.json` |
| [src/DeclaredRequest.php](../src/DeclaredRequest.php), [DeclaredModel.php](../src/DeclaredModel.php), [DeclaredQuery.php](../src/DeclaredQuery.php), [DeclaredView.php](../src/DeclaredView.php) | ~680 | Tier-2 seams consuming the typed `Manifest` collections |
| [composer.json](../composer.json) | — | `composer check` = lint, rector-lint, phpstan, 100% coverage, **bc-check** |

## 1. The end state — three invariants

The end state is a general-purpose tool with exactly three invariants. Every phase is judged
against them; every violation in §3 violates at least one.

### G1 — Deterministic generation from an arbitrary class list

`declaration:generate-manifest-schema` takes an **arbitrary list of classes** (any PHP package,
not just Laravel) plus an explicit **block map** (ordered `block name → class` + per-block
options) and deterministically produces the **whole** `manifest.schema.json`: root `properties`
wiring, per-block definitions, per-key call metadata. It never requires a pre-existing schema
file, never depends on hand-curated class knowledge in code, and is byte-identical on a no-op
run.

### G2 — The schema is the single source of truth

The generated schema validates the manifest **and** carries the machine-readable call metadata
(`x-manifest` keywords — legal, ignored by validators) the runtime maps with: per key, *how* the
value becomes calls (shape, method, argument mapping, resolution vocabulary, guards,
continuations). There is no second encoding of dispatch semantics anywhere — not in attributes,
not in providers, not in a typed mirror class. Curation (prose, patterns, shapes the signature
cannot express) is preserved by the generator's re-merge gate, still inside the schema.

### G3 — One runtime engine; components are configuration

A single generic **mapper** dispatches every block: resolve the block's target from the
container, walk the manifest keys, dispatch each per the schema's call metadata. Adding a
component = adding a block-map entry (configuration). Nothing per-component exists in code: no
per-component provider, no per-component declaration class, no per-component dispatch special
cases. What a signature cannot determine (prepend-vs-append order, fluent chains, recursion,
guard policy) is **declared in the block map** — data, never code paths.

## 2. Grounding (what each file proves)

| Source | What it proves for this migration |
| --- | --- |
| [src/Internal/SchemaGenerator.php](../src/Internal/SchemaGenerator.php) | The projection engine exists and is general in principle: source-order, trait-inclusive flattening verified 65/65 against `Router` reflection; `declarable()` gates (public, non-magic, non-`@internal`, non-byRef, ≥1 param); `key()` derives shapes from signatures; `encode()` is deterministic. What is missing: per-key call metadata, whole-file generation, root wiring, block maps. |
| [src/Internal/Commands/GenerateManifestSchemaCommand.php](../src/Internal/Commands/GenerateManifestSchemaCommand.php) | Fails when the schema does not exist (line 36) — the generator cannot bootstrap. Append-only `merge()` cannot regenerate a definition. No `--report`/`--check`. The I/O-injection closure pattern (`$source`) is the right seam and stays. |
| [manifest.schema.json](../manifest.schema.json) | 18 curated-named class projections are hand-written JSON duplicating surfaces the engine can generate (`blade`, `responses`, `pagination`, `db`, `kernel`, `schema`, `routes`, `route*`, `provider`, `request`, `model`, `query`, `tableDefinition`). 12 shared primitives are the *resolution vocabulary* and are legitimately hand-written. |
| [src/Manifest.php](../src/Manifest.php) | The root wiring is hand-authored (`const` + property per block); `providers`/`requests`/`models`/`queries` are `mapOf` collections keyed by hand-chosen item keys (`class`, `name`). The typed mirror is a second representation of data the schema already describes. |
| [src/Providers/RouterDeclarationServiceProvider.php](../src/Providers/RouterDeclarationServiceProvider.php) | The five `selected(...)` loops are the runtime meaning of the dispatch attributes — the semantics the schema lacks. Uniform for this component *only because* the attributes were written for it. |
| [src/Providers/AppDeclarationServiceProvider.php](../src/Providers/AppDeclarationServiceProvider.php) | 192 lines of bespoke: list-vs-map branching per key, `when/needs/give` chains, `instance()` instantiation semantics, `.php`-file `reference()`/`fileValue()`, `absolute()`, `wrapExtender`/`wrapCallback`, invented null-item LogicException. |
| [src/Providers/KernelDeclarationServiceProvider.php](../src/Providers/KernelDeclarationServiceProvider.php) | Hand-rolled dispatch for one attribute-less key (`whenRequestLifecycleIsLongerThan`), `CarbonInterval` threshold parsing (invented acceptance), duration-handler wrapping, a third copy of `reference()`/`fileValue()`/`absolute()`. |
| [src/Providers/ViewDeclarationServiceProvider.php](../src/Providers/ViewDeclarationServiceProvider.php) | Comma-splitting of view lists (invented DSL, Rule 2.6/2.8/3.1 violation), `absolute()` copy, `creator`/`composer` duplicated loops. |
| [src/Providers/BladeDeclarationServiceProvider.php](../src/Providers/BladeDeclarationServiceProvider.php) | Per-key loops with inline `basePath()` prefixing, `stringable`/`if` wrapping — a fourth copy of the path/reference conventions. |
| [src/Providers/RoutesDeclarationServiceProvider.php](../src/Providers/RoutesDeclarationServiceProvider.php) | The worst violation: `TWO_ARGUMENT_PENDING_SETTERS` const, `applyBuilder()`'s six hardcoded special cases (`missing`, `metadata`, `bindingFields`, `can`, `block`, spread-vs-single reflection probing), `wrapMissingHandler` (fifth path/reference copy). |
| [src/Providers/ValidatorDeclarationServiceProvider.php](../src/Providers/ValidatorDeclarationServiceProvider.php), [Gate…](../src/Providers/GateDeclarationServiceProvider.php), [Response…](../src/Providers/ResponseDeclarationServiceProvider.php), [Pagination…](../src/Providers/PaginationDeclarationServiceProvider.php), [Database…](../src/Providers/DatabaseDeclarationServiceProvider.php), [Providers…](../src/Providers/ProvidersDeclarationServiceProvider.php), [Config…](../src/Providers/ConfigDeclarationServiceProvider.php) | Seven more bespoke loops, each trivially expressible as the general shapes: registries = `binding`, `policy`/`define` = `binding`, `macro` = `binding` + callable wrap, `Preset` flags = `flag` + static call, `listen` = `binding_each`, `register` = items with a fixed `call`. |
| [src/Attributes/Attributes/](../src/Attributes/Attributes) | 36 attribute files; 10 are dead (zero consumers — verified by grep: `ColumnModifier`, `ColumnType`, `Composer`, `ForeignKeyModifier`, `Hook`, `Location`, `Redirect`, `TableConstraint`, `TableOption`, `ViewNamespace`), 4 are single-component (`Path`→App paths, `Conditional`→App `*If`, `Preset`→Pagination, `ClassDefault`→Model), 10 are the dispatch set (`Key`, `Binding`, `Setter`, `Append`, `AppendTo`, `PrependTo` + guard family), and the guard attributes exist only for the Blueprint engine. |
| [src/Internal/BlueprintMethodKind.php](../src/Internal/BlueprintMethodKind.php) | Classification by regex over `@return` doc comments + hardcoded `RETURN_KINDS` map + `LIFECYCLE` blocklist — vendor doc-comment parsing as a bespoke pipeline beside the AST pipeline. |
| [src/Internal/Guards.php](../src/Internal/Guards.php) | `CANONICAL_COLUMNS` domain table + argument extraction — deterministic policy expressed as an attribute-on-enum-case machinery. |
| [src/BlueprintAction.php](../src/BlueprintAction.php) | Named/positional parameter matching (`fromDefinition`), modifier validation against `@method`-annotated classes — a general "map keys = parameter names, extras = modifiers" capability living outside the engine. |
| [src/Internal/Commands/MigrateCommand.php](../src/Internal/Commands/MigrateCommand.php) | Fixed lifecycle order (drop → rename → create → alter) is legitimate orchestration (STYLE Rule 5) — but its guard loop and action application are bespoke. |
| [src/DeclaredRequest.php](../src/DeclaredRequest.php) | Seams read typed declarations (`app(Manifest::class)->requests->get($name)`); `authorize`'s map form mirrors native `Gate` method names — general data already schema-described. |
| [src/Internal/DataModel.php](../src/Internal/DataModel.php) | The typed-object layer: `DataModel` + `Describe` casts + `selected()` — the runtime's second validation/mapping layer (STYLE Rules 3.4, 6.4 say the schema is the only one). |
| [src/LaravelDeclarationProvider.php](../src/LaravelDeclarationProvider.php), [DefaultProviders.php](../src/DefaultProviders.php), [config/laravel-declaration.php](../config/laravel-declaration.php) | Provider list curation + `merge/replace/except` — the extension surface assumes per-component providers exist. |
| [docs/implementation/components/component-index.md](../docs/implementation/components/component-index.md) | The complete block → vendor class map (~45 components) — the grounding for the default block map, currently a doc not configuration. |
| [docs/generate-manifest-schema-plan.md](generate-manifest-schema-plan.md) | The whole-file run order (§3): generate-fragment-first, re-merge curated keys gated on native declarability, drift report, idempotence fast path, failure surface. Adopted verbatim as the engine's `generate()` contract in Phase 2, with definition keying superseded (§4.4 of this plan). |
| [docs/implementation/schema_generator/00-overview.md](implementation/schema_generator/00-overview.md) | The two-layer invariant this plan extends: structure regenerated by `render()`, curation preserved by merge. Phase 2 keeps it; the runtime layers below it are what this plan removes. |

## 3. Violation inventory

Every bespoke implementation that violates the general case, with evidence and the general
replacement. **V-numbers are referenced by the phases.**

| # | Violation | Evidence | Violates | General replacement | Phase |
| --- | --- | --- | --- | --- | --- |
| **V1** | The generator cannot bootstrap a schema: it fails when `manifest.schema.json` is absent, and `merge()` is append-only so it can never produce a whole file for a fresh class list | [GenerateManifestSchemaCommand.php](../src/Internal/Commands/GenerateManifestSchemaCommand.php) lines 36–41; [SchemaGenerator.php](../src/Internal/SchemaGenerator.php) `merge()` | **G1** | `SchemaGenerator::generate(BlockMap, ?prior)` produces the whole file from the block map; the re-merge gate (generate-manifest-schema-plan §5) preserves curated prose/metadata; the command loses the exists-check | 2 |
| **V2** | Root `properties` wiring and block vocabulary are hand-authored in `Manifest.php` and `component-index.md` — code/docs, not configuration; a new arbitrary class list has no wiring path | [Manifest.php](../src/Manifest.php) (whole file); [component-index.md](implementation/components/component-index.md) | **G1**, **G3** | An explicit ordered **block map** (`config('laravel-declaration.blocks')` / `--map=` file) is the generator input; the generator writes root wiring and embeds resolved block metadata as root `x-manifest` | 2 |
| **V3** | Dispatch semantics are encoded twice — value shapes in the schema, dispatch semantics in attributes on hand-written declaration classes — and never meet | [SchemaGenerator.php](../src/Internal/SchemaGenerator.php) `key()` vs [src/Router.php](../src/Router.php) `#[Key, Binding]` etc. | **G2** | One encoding: per-key `x-manifest-call` metadata generated into the schema (shape, method, argument mapping, resolution); attributes deleted | 1, 5 |
| **V4** | Thirteen bespoke service providers, one per component — each hand-rolls timing, per-key loops, and value transformations; adding a component requires writing a provider | the 13 files under [src/Providers/](../src/Providers) | **G3** | One generic `ManifestServiceProvider` + one `Mapper`; per-block timing/options are block-map data; all 13 deleted | 4 |
| **V5** | Per-method special cases hardcoded in `RoutesDeclarationServiceProvider`: `TWO_ARGUMENT_PENDING_SETTERS`, `missing`/`metadata`/`bindingFields`/`can`/`block` match arms, spread-vs-single reflection probing, `wrapMissingHandler` | [RoutesDeclarationServiceProvider.php](../src/Providers/RoutesDeclarationServiceProvider.php) lines 24–28, 100–160, 198–209 | **G3** | General shapes: `call_list` + derived continuation (native return type = object → extras ride the return value); string-handler resolution = the one Resolver; no method-name constants | 2, 4 |
| **V6** | The `.php`-file reference / `basePath` / callable-wrap conventions are implemented five times: `reference()`+`fileValue()`+`absolute()` (App), (Kernel), `wrapMissingHandler` (Routes), inline `basePath()` prefixing (Blade), `absolute()` (View) | [AppDeclarationServiceProvider.php](../src/Providers/AppDeclarationServiceProvider.php) lines 118–155; [KernelDeclarationServiceProvider.php](../src/Providers/KernelDeclarationServiceProvider.php) lines 87–114; [RoutesDeclarationServiceProvider.php](../src/Providers/RoutesDeclarationServiceProvider.php) 198–209; [BladeDeclarationServiceProvider.php](../src/Providers/BladeDeclarationServiceProvider.php) 19–40; [ViewDeclarationServiceProvider.php](../src/Providers/ViewDeclarationServiceProvider.php) 76–79 | **G2**, **G3** | One `Resolver` with a closed vocabulary (`classString`, `reference`, `phpFile`, `closure`, `callable`, `path`, `passthrough`) — the runtime counterpart of the schema's 12 shared primitives | 3 |
| **V7** | The `schema` block is a second bespoke mapping engine: `BlueprintMethodKind` classifies by regex over `@return` doc comments, hardcoded `RETURN_KINDS`/`LIFECYCLE`, a parallel guard attribute taxonomy, bespoke named/positional matching in `BlueprintAction::fromDefinition` | [BlueprintMethodKind.php](../src/Internal/BlueprintMethodKind.php) `classify()`; [Guards.php](../src/Internal/Guards.php) `CANONICAL_COLUMNS`; [BlueprintAction.php](../src/BlueprintAction.php) `fromDefinition()` | **G2**, **G3** | The engine's general shapes (construct-continuation onto `Blueprint`, `call_list` items, named-parameter matching generalized); guards become `x-manifest-guard` metadata from a data-driven policy; the bespoke files deleted | 6 |
| **V8** | A typed mirror layer (`Manifest` + 23 declaration classes + `DataModel` + `Describe` casts) re-parses and re-shapes YAML that the schema already validates — a second validation layer and a second hand-maintained projection | [Manifest.php](../src/Manifest.php); [src/Internal/DataModel.php](../src/Internal/DataModel.php); the 23 declaration classes | **G2**, STYLE 3.4/6.4 | Raw manifest data + schema validation only; `ManifestStore` (raw accessor) for seams; all declaration classes, `Manifest`, and the `data-model` dependencies deleted | 5 |
| **V9** | Invented value DSLs that violate pass-through (STYLE 2.6/2.8, 3.1): comma-split view lists (View), `CarbonInterval` threshold strings (Kernel), invented null-item LogicException (App list bindings), invented skip of `.php` closures on `*If` list items (App), `App::when` `{needs, give}` as bespoke code | [ViewDeclarationServiceProvider.php](../src/Providers/ViewDeclarationServiceProvider.php) 56–66; [KernelDeclarationServiceProvider.php](../src/Providers/KernelDeclarationServiceProvider.php) 64–77; [AppDeclarationServiceProvider.php](../src/Providers/AppDeclarationServiceProvider.php) 61–90 | **G2**, STYLE 2/3 | Pass-through dispatch; the `chain` shape (schema metadata) covers `when/needs/give`; the removed normalizations are documented as breaking | 4 |
| **V10** | Component-specific attributes: 10 dead files (zero consumers), 4 single-component (`Path`, `Conditional`, `Preset`, `ClassDefault`), plus the dispatch set whose semantics move into schema metadata | [src/Attributes/Attributes/](../src/Attributes/Attributes); grep census in §2 | **G2**, **G3** | Deleted in two cuts: the dead 10 in Phase 4, the rest with the declaration classes in Phase 5 (guard attributes with the guard registry in Phase 6) | 4, 5, 6 |
| **V11** | Curated-named definitions for class surfaces are hand-written JSON duplicating the engine's generatable output and drift from vendor (e.g. `blade`'s 8 keys vs BladeCompiler's full declarable surface; `db`'s 2 keys vs `DatabaseManager`'s) | [manifest.schema.json](../manifest.schema.json) `definitions`: `blade`, `responses`, `pagination`, `db`, `kernel`, `schema`, `routes`, `route*`, `tableDefinition` | **G1** | Generated per-block definitions (keyed by block name, class in `x-manifest-block`), curated keys preserved by the re-merge gate; items-block defs (`request`, `model`, `query`, `provider`) remain seam vocabulary — items mode stores, it does not project | 2 |
| **V12** | Runtime dispatch cannot be derived from the schema and re-derives from reflection lazily inside `MigrateCommand`'s guard loop and `BlueprintAction` — the *shape decision* lives in two places at two different times (generation-time parser vs runtime reflection) | [MigrateCommand.php](../src/Internal/Commands/MigrateCommand.php) `alter()`/`passes()`; [BlueprintAction.php](../src/BlueprintAction.php) `apply()` | **G2** | One shape vocabulary shared by generator and mapper (Phase 1's classifier over a normalized `Signature`); the mapper consumes schema metadata and refuses `undecided` | 1, 4, 6 |

## 4. Target contracts

The closed vocabularies the general engine is built from. Everything in this section is data or
metadata — there are no per-component code paths in the end state.

### 4.1 Call shapes (closed set)

Derived deterministically from the native method's **normalized signature** (§5 Phase 1) at
generation time, written as per-key `x-manifest-call` metadata; the mapper consumes them
verbatim. `prepend` order, chains, recursion, guards, and flag keys are not signature-derivable:
they are attached by curation re-merge or by the block map's `shapes` overrides.

| Shape | Signature precondition (derivable) | Manifest value | Runtime call |
| --- | --- | --- | --- |
| `setter` | 1 non-variadic param | single value (or `null` = no call) | `method($value)` once when non-null |
| `call` | not classifier-derived — attached by the items-mode `item.call` or a block-map `shapes` override | map of parameter name → argument | `method(name: value, …)` once, missing params default per Laravel |
| `call_list` | 2+ params not claimed by the `binding` branches (first param non-scalar-keyable, or 3+ params) | list of item maps | one `method(...)` per item; item map keys = parameter names |
| `binding` | exactly 2 params, first scalar-keyable (`string|int`), second not `string|array` | map `{key: value}` — or list of items = one-arg self-binding calls | `method($key, $value)` per entry |
| `binding_each` | exactly 2 params, first scalar-keyable, second `string|array` union | map `{key: value\|list}` | `method($key, $item)` per item of `(array) $value` |
| `prepend_each` | = `binding_each` (order is not signature-derivable) | map `{key: value\|list}` | same, items in reverse |
| `append` | 1 variadic param | list | `method($item)` per item |
| `flag` | 0 params (excluded by the generator's gate — undecidable query-vs-mutation) | `true` | `method()` once |
| `chain` | not derivable (fluent factories) | entry key = first step's argument; value = map of the remaining steps' parameter names | `$target->step1($key)->step2($a)->step3($b)` |
| `undecided` | anything else | `true` + `TODO(…)` description | **not dispatchable** — mapper throws `LogicException`; schema author resolves it |

Extra keys beyond the called method's parameters (item maps with more keys than parameters):
when the native method's **declared return type is an object** (derivable), the remaining keys
dispatch onto the returned object (**continuation**) using the continuation class's own
definition; otherwise they are schema-invalid. `group`-style recursion: the block map declares
`recurse` — the item's reserved key holds nested block data and the engine synthesizes the
`Closure` argument (last parameter) as `fn (Target $t) => mapper->block($nestedBlock, $data)`.

### 4.2 Resolution vocabulary (closed set)

The runtime counterpart of the schema's shared primitives. One `Resolver`, consumed by the
mapper wherever a manifest value references a PHP symbol or file. Generated keys get a default
vocabulary from the parameter type (`Closure` type → `callable`; class type → `class`;
`string` → `passthrough`); curated keys and block-map `shapes` may override per key.

| Vocabulary | Applies to schema `$ref` | Runtime behavior |
| --- | --- | --- |
| `passthrough` | plain `type: string` | untouched |
| `class` | `classString` | string passed through (the container resolves at call time) |
| `reference` | `reference` | string passed through (`Class@method`, `Class::method`, function) |
| `callable` | `closure` | string `Class@method`/`Class::method` → wrapped `fn (…$args) => $container->call($ref, $args)`; `.php` path → the file's returned value (required once per process), asserted `Closure` |
| `file` | `phpFile` | `.php` path required once, return value used |
| `path` | — | relative paths resolved under `basePath()`; absolute used as-is |
| `concrete` | `concrete` | `~` → `null` (self-binding); `classString`/`reference` passthrough; `phpFile` → `file` |

### 4.3 Block modes, timing, and the block map (closed set)

```php
// config('laravel-declaration.blocks') — ordered; order = dispatch order; the
// generator's input; embedded resolved into the schema root as `x-manifest`.
[
    'app' => ['class' => 'Illuminate\Foundation\Application', 'timing' => 'registered'],
    'config' => ['mode' => 'config', 'timing' => 'register'],
    'kernel' => ['class' => 'Illuminate\Foundation\Http\Kernel', 'timing' => 'after-resolving:Illuminate\Contracts\Http\Kernel'],
    'router' => ['class' => 'Illuminate\Routing\Router'],
    'routes' => ['class' => 'Illuminate\Routing\Router', 'shapes' => [
        'addRoute' => ['shape' => 'call_list'], 'group' => ['shape' => 'call_list', 'recurse' => 'routes'],
        'resource' => ['shape' => 'call_list'], 'apiResource' => ['shape' => 'call_list'],
        'singleton' => ['shape' => 'call_list'], 'apiSingleton' => ['shape' => 'call_list'],
        'view' => ['shape' => 'call_list'], 'redirect' => ['shape' => 'call_list'], 'permanentRedirect' => ['shape' => 'call_list'],
    ]],
    'view' => ['class' => 'Illuminate\View\Factory', 'timing' => 'after-resolving:view'],
    'blade' => ['class' => 'Illuminate\View\Compilers\BladeCompiler', 'timing' => 'after-resolving:blade.compiler'],
    'responses' => ['class' => 'Illuminate\Routing\ResponseFactory'],
    'pagination' => ['class' => 'Illuminate\Pagination\Paginator', 'static' => true],
    'db' => ['class' => 'Illuminate\Database\DatabaseManager'],
    'providers' => ['class' => 'Illuminate\Foundation\Application', 'mode' => 'items',
        'item' => ['key' => 'class', 'call' => ['method' => 'register', 'args' => ['class' => 'provider', 'force' => 'force']]]],
    'requests' => ['mode' => 'items', 'item' => ['key' => 'name', 'store' => true]],
    'validator' => ['class' => 'Illuminate\Validation\Factory', 'timing' => 'after-resolving:validator'],
    'gate' => ['class' => 'Illuminate\Contracts\Auth\Access\Gate', 'timing' => 'after-resolving:Illuminate\Contracts\Auth\Access\Gate'],
    'models' => ['mode' => 'items', 'item' => ['key' => 'class', 'store' => true]],
    'queries' => ['mode' => 'items', 'item' => ['key' => 'name', 'store' => true]],
    'schema' => ['class' => 'Illuminate\Database\Schema\Builder', 'mode' => 'command', 'shapes' => [
        'create' => ['shape' => 'construct', 'class' => 'Illuminate\Database\Schema\Blueprint', 'key' => 'table'],
        'table' => ['shape' => 'construct', 'class' => 'Illuminate\Database\Schema\Blueprint', 'key' => 'table'],
        'rename' => ['shape' => 'call_list'], 'drop' => ['shape' => 'append'], 'dropIfExists' => ['shape' => 'append'],
    ], 'guards' => 'blueprint'],
    'extra' => ['mode' => 'passthrough'],
]
```

| Field | Values (closed set) | Meaning |
| --- | --- | --- |
| `class` | FQCN | the block's target |
| `mode` | `class` (default) · `items` · `config` · `command` · `passthrough` | `class` = dispatch keys onto the instance; `items` = per-item identity + fixed `call` or `store` (seam-consumed data); `config` = `Config::set` with per-entry key prefixing; `command` = dispatched by a command, never at boot; `passthrough` = never dispatched |
| `timing` | `boot` (default) · `register` · `after-resolving:<binding>` · `registered` · `booting` · `booted` | when the mapper runs the block |
| `static` | bool | call statically |
| `shapes` | map of key → shape override (same object as `x-manifest-call`) | shapes the signature cannot decide |
| `item` | `{key, call?, store?}` | items-mode identity + fixed-call or stored-for-seams |
| `continuations` | list of FQCNs | classes whose definitions are generated for continuation dispatch |
| `guards` | policy name (`blueprint`) | attach guard metadata via the named policy |

`config` is the one invented key transform the engine keeps (per-entry `Config::set` key
prefixing): Laravel's config repository is dot-flat by design and the prefixing is Tier-2 seam
orchestration (framework-mapping doc, Tier 2), documented as such.

### 4.4 Definition keying — superseding generate-manifest-schema-plan §4.1

Definitions are keyed by **block name**, with the target class recorded in each definition's
`x-manifest-block` metadata. The earlier plan keyed definitions by FQCN verbatim; that collides
the moment two blocks project one class with different shape policies — and this repo already
needs exactly that: `router` (map-shaped keys) and `routes` (list-shaped keys) both project
`Illuminate\Routing\Router`. Block-name keying is the general rule; the FQCN appears verbatim in
`x-manifest-block.class`. Shared primitives (`classString`, `reference`, `phpFile`, `closure`,
`closureList`, `concrete`, `binding`, `bindingIf`, `stringOrList`, `extension`) stay as
definitions referenced by `$ref` — they are vocabulary, not blocks, and never root-wired.

## 5. Migration phases

Order matters: each phase leaves `composer check` green and the package functionally intact.
Files marked **create** are written complete in this section; files marked **modify** get their
surgical change shown; files marked **delete** list their survivors.

### Phase 1 — Normalized signatures + the shape classifier + call metadata

**Goal (kills V3, V12's split-decision):** one derivation of "how a manifest value becomes
calls", shared structurally by the generator (today) and the mapper (Phase 4). Per-key
`x-manifest-call` metadata appears in generated definitions. No command or runtime change.

**Create** `src/Internal/Manifest/SignatureParam.php`:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Manifest;

/** @internal One normalized parameter — identical whether extracted by parser or reflection. */
final readonly class SignatureParam
{
    public function __construct(
        public string $name,
        public bool $byRef,
        public bool $variadic,
        /** string|int|float|bool|array|null — null = untyped, mixed, callable, or a class/DNF type (honestly unknown) */
        public ?string $builtin,
        public bool $nullable,
        public bool $stringArrayUnion,
    ) {}
}
```

**Create** `src/Internal/Manifest/Signature.php`:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Manifest;

/** @internal One normalized method signature — the single input the shape classifier consumes. */
final readonly class Signature
{
    /**
     * @param  list<SignatureParam>  $params
     */
    public function __construct(
        public string $name,
        public bool $public,
        public bool $variadic,
        public array $params,
    ) {}
}
```

**Create** `src/Internal/Manifest/SignatureFactory.php` — the PHP-Parser backend, lifted from
`SchemaGenerator`'s existing `selectClass()`/`flatten()`/`declarable()` output:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Manifest;

use Closure;
use PhpParser\Error;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\UnionType;
use RuntimeException;

/** @internal Extracts normalized signatures from PHP source — the generation-time backend. */
final class SignatureFactory
{
    /**
     * @param  string  $class  the FQCN to select
     * @param  Closure(string): (string|null)  $source  file contents per FQCN, null when unreadable
     * @return list<Signature> declarable + skipped methods, in reflection order
     *
     * @throws Error the source is not valid PHP
     * @throws RuntimeException no readable source, or the class is not declared in it
     */
    public static function methods(string $class, Closure $source): array
    {
        // One pipeline + flatten run (the engine's existing §1.1–§2.4 machinery), then:
        return array_map(self::of(...), SchemaGenerator::methodsOf($class, $source));
    }

    /** @param  ClassMethod  $method */
    public static function of(ClassMethod $method): Signature
    {
        $params = [];

        foreach ($method->params as $param) {
            $params[] = new SignatureParam(
                name: self::name($param),
                byRef: $param->byRef,
                variadic: $param->variadic,
                builtin: self::builtin($param->type),
                nullable: $param->type instanceof \PhpParser\Node\NullableType,
                stringArrayUnion: self::stringArrayUnion($param->type),
            );
        }

        return new Signature($method->name->toString(), $method->isPublic(), $method->isVariadic(), $params);
    }

    private static function builtin(?object $type): ?string
    {
        if (! $type instanceof Identifier) {
            return null; // untyped / class / DNF / nullable — the wrapper records nullability separately
        }

        return match ($type->toString()) {
            'string', 'int', 'float', 'bool', 'array' => $type->toString(),
            default => null,
        };
    }

    private static function stringArrayUnion(?object $type): bool
    {
        if (! $type instanceof UnionType) {
            return false;
        }

        $names = array_map(
            static fn (object $member): string => $member instanceof Identifier ? $member->toString() : '',
            $type->types,
        );

        sort($names); // order-agnostic: array|string ≡ string|array

        return $names === ['array', 'string'];
    }

    private static function name(\PhpParser\Node\Param $param): string
    {
        assert($param->var instanceof \PhpParser\Node\Expr\Variable); // parser guarantee for method params
        assert(is_string($param->var->name));

        return $param->var->name;
    }
}
```

**Create** `src/Internal/Manifest/CallShape.php` and `src/Internal/Manifest/Call.php`:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Manifest;

/** @internal The closed set of call shapes (§4.1). */
enum CallShape: string
{
    case Setter = 'setter';
    case Call = 'call';
    case CallList = 'call_list';
    case Binding = 'binding';
    case BindingEach = 'binding_each';
    case PrependEach = 'prepend_each';
    case Append = 'append';
    case Flag = 'flag';
    case Chain = 'chain';
    case Undecided = 'undecided';
}
```

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Manifest;

/** @internal The per-key call metadata carried in the schema as `x-manifest-call`. */
final readonly class Call
{
    /**
     * @param  list<string>|null  $params    call/call_list: the method's parameter names in signature order — the mapper's argument order
     * @param  list<string>|null  $steps     chain: the fluent step order (first step is `$method`)
     * @param  list<string>|null  $resolve   resolution vocabulary for the value argument (§4.2)
     * @param  string|null  $recurse   the reserved item key AND nested block name (group-style recursion; the synthesized Closure fills the last parameter)
     */
    public function __construct(
        public CallShape $shape,
        public string $method,
        public ?array $params = null,
        public ?array $steps = null,
        public ?array $resolve = null,
        public ?string $recurse = null,
    ) {}

    /** @return array<string, mixed> */
    public function toMeta(): array
    {
        return array_filter([
            'shape' => $this->shape->value,
            'method' => $this->method,
            'params' => $this->params,
            'steps' => $this->steps,
            'resolve' => $this->resolve,
            'recurse' => $this->recurse,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param  array<string, mixed>  $meta */
    public static function fromMeta(array $meta): self
    {
        return new self(
            CallShape::from((string) $meta['shape']),
            (string) ($meta['method'] ?? ''),
            isset($meta['params']) ? array_values((array) $meta['params']) : null,
            isset($meta['steps']) ? array_values((array) $meta['steps']) : null,
            isset($meta['resolve']) ? array_values((array) $meta['resolve']) : null,
            isset($meta['recurse']) ? (string) $meta['recurse'] : null,
        );
    }
}
```

**Create** `src/Internal/Manifest/ShapeClassifier.php`:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Manifest;

/** @internal The one deterministic shape derivation both the generator and the mapper rely on. */
final class ShapeClassifier
{
    public static function classify(Signature $signature): Call
    {
        $params = $signature->params;

        if ($params === []) {
            return new Call(CallShape::Flag, $signature->name); // gate excludes zero-param from generation; curation may attach
        }

        if ($signature->variadic && count($params) === 1) {
            return new Call(CallShape::Append, $signature->name);
        }

        if (count($params) === 1) {
            return new Call(CallShape::Setter, $signature->name);
        }

        $names = array_map(static fn (SignatureParam $param): string => $param->name, $params);

        if (self::scalarKey($params[0])) {
            if (count($params) === 2 && self::stringArrayUnion($params[1])) {
                return new Call(CallShape::BindingEach, $signature->name);
            }

            if (count($params) === 2) {
                return new Call(CallShape::Binding, $signature->name);
            }
        }

        if (count($params) >= 2) {
            return new Call(CallShape::CallList, $signature->name, params: $names);
        }

        return new Call(CallShape::Undecided, $signature->name);
    }

    private static function scalarKey(SignatureParam $param): bool
    {
        if ($param->builtin === null) {
            return true; // untyped counts (vendor keys are untyped; §6.1 precedent)
        }

        if ($param->nullable) {
            return false;
        }

        return in_array($param->builtin, ['string', 'int'], true);
    }

    private static function stringArrayUnion(SignatureParam $param): bool
    {
        return $param->stringArrayUnion;
    }
}
```

**Modify** `src/Internal/SchemaGenerator.php`:

- `declarable()` and `flatten()` are refactored into `SchemaGenerator::methodsOf(string $class,
  Closure $source): array<string, ClassMethod>` (the flatten result accessor the whole-file plan
  left as open item 2) — `render()`/`skipped()` become thin views over it.
- `key(ClassMethod)` is replaced: `$call = ShapeClassifier::classify(SignatureFactory::of($method))`;
  the value schema is derived from `SignatureParam`s (the existing `paramSchema()` rewritten to
  consume `SignatureParam`); the returned key object gains `'x-manifest-call' => $call->toMeta()`
  before `description`, so the metadata rides every generated key. `undecided` keys keep `true` +
  `TODO(…)` and their metadata says `shape: undecided`.
- `Call`/`ShapeClassifier` are the shape authority; the mapper (Phase 4) refuses to dispatch
  anything whose metadata is missing or `undecided` — the schema stays the single source of truth.

**Tests** (`tests/Feature/SchemaGeneratorTest.php` extended, new
`tests/Feature/ShapeClassifierTest.php`):

- `tests/Fixtures/Manifest/Arbitrary.php` — an **arbitrary, non-Laravel** fixture class
  exercising every shape precondition: 1-param, 1-variadic, 2-param scalar-key, 2-param
  scalar-key + `string|array`, 3-param, untyped, byRef, zero-param, `@internal`, union-with-null,
  DNF, and a class-typed param (for the `callable`/`class` resolution defaults).
- Structural reflection parity: a test-only `ReflectionSignature` backend maps
  `ReflectionMethod`s to `Signature` and asserts `ShapeClassifier::classify()` agrees with the
  parser backend for every fixture method and for the five generated FQCN blocks.
- Every generated key carries `x-manifest-call`; `undecided` keys are the only `TODO(` keys.

**Gate:** `composer check` (lint, rector-lint, phpstan, 100% coverage, bc-check still expected to
flag the new keywords — Phase 7 retires it).

### Phase 2 — Whole-file generation from an explicit block map

**Goal (kills V1, V2, V10, V11):** the generator produces the whole schema from an explicit
ordered block map — arbitrary classes included — with the re-merge gate and drift report from
generate-manifest-schema-plan §3/§5/§6, definitions keyed by block name (§4.4), and root
`x-manifest` embedding. `Manifest.php` survives until Phase 5; the command is rebuilt.

**Create** `src/Internal/Manifest/Block.php`:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Manifest;

/** @internal One ordered block-map entry — the configuration that names a component. */
final readonly class Block
{
    /**
     * @param  array<string, array<string, mixed>>|null  $shapes   per-key shape overrides (x-manifest-call objects)
     * @param  array{key: string, call?: array{method: string, args: array<string, string>}, store?: true}|null  $item
     * @param  list<class-string>|null  $continuations
     * @param  string|null  $guards  the guard policy name (e.g. `blueprint`)
     */
    public function __construct(
        public string $name,
        public ?string $class = null,
        public string $mode = 'class',
        public string $timing = 'boot',
        public bool $static = false,
        public bool $wired = true,
        public ?array $shapes = null,
        public ?array $item = null,
        public ?array $continuations = null,
        public ?string $guards = null,
    ) {}

    /** @param  array<string, mixed>  $entry */
    public static function from(string $name, array $entry): self
    {
        return new self(
            name: $name,
            class: isset($entry['class']) ? (string) $entry['class'] : null,
            mode: (string) ($entry['mode'] ?? (isset($entry['class']) ? 'class' : 'passthrough')),
            timing: (string) ($entry['timing'] ?? 'boot'),
            static: (bool) ($entry['static'] ?? false),
            wired: (bool) ($entry['wired'] ?? true),
            shapes: isset($entry['shapes']) ? (array) $entry['shapes'] : null,
            item: isset($entry['item']) ? (array) $entry['item'] : null,
            continuations: isset($entry['continuations']) ? array_values((array) $entry['continuations']) : null,
            guards: isset($entry['guards']) ? (string) $entry['guards'] : null,
        );
    }

    /** The bare-classes mode: one unwired, FQCN-named definition per class (definition-only — no root wiring, no x-manifest). */
    public static function bare(string $class): self
    {
        return new self(name: $class, class: $class, wired: false);
    }

    /** @return array<string, mixed> the definition's `x-manifest-block` metadata */
    public function toMeta(): array
    {
        return array_filter([
            'class' => $this->class,
            'mode' => $this->mode === 'class' ? null : $this->mode,
            'timing' => $this->timing === 'boot' ? null : $this->timing,
            'static' => $this->static ?: null,
            'item' => $this->item,
            'guards' => $this->guards,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
```

**Create** `src/Internal/Manifest/BlockMap.php`:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Manifest;

/** @internal The ordered block map — the generator's scope and the mapper's dispatch order. */
final readonly class BlockMap
{
    /** @param  list<Block>  $blocks */
    public function __construct(public array $blocks) {}

    /** @param  array<string, array<string, mixed>>  $entries  ordered map of block name → entry */
    public static function from(array $entries): self
    {
        return new self(array_values(array_map(
            static fn (string $name): Block => Block::from($name, $entries[$name] ?? []),
            array_keys($entries),
        )));
    }

    public function named(string $name): ?Block
    {
        foreach ($this->blocks as $block) {
            if ($block->name === $name) {
                return $block;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function classTargets(): array // every FQCN the map names, blocks + continuations, in order
    {
        $targets = [];

        foreach ($this->blocks as $block) {
            foreach ([$block->class, ...$block->continuations ?? []] as $class) {
                if (is_string($class) && $class !== '' && ! in_array($class, $targets, true)) {
                    $targets[] = $class;
                }
            }
        }

        return $targets;
    }
}
```

**Modify** `src/Internal/SchemaGenerator.php` — add `generate()` (the whole-file engine; the
append-only `merge()` becomes an internal helper of the re-merge gate exactly as
generate-manifest-schema-plan §3 specifies):

```php
/**
 * The whole-file generation: per block, the fragment from render() replaces the
 * definition; curated key objects re-merge on top gated on native declarability;
 * root `properties` wiring + `x-manifest` come from the block map. Byte-identical
 * when nothing changed (the idempotence fast path).
 *
 * @param  array<string, mixed>|null  $prior  the schema as it was (curated prose + metadata survive through the gate)
 * @return array{schema: array<string, mixed>, report: Report}
 */
public static function generate(BlockMap $map, ?array $prior, Closure $source): array
{
    $schema = $prior ?? ['definitions' => []];
    $report = new Report;

    foreach ($map->blocks as $block) {
        $schema = self::block($schema, $block, $source, $report); // per §3 run order: generate → re-merge → report
    }

    foreach ($map->blocks as $block) { // root wiring + x-manifest, derived — never hand-authored
        if (! $block->wired) {
            continue; // bare-classes entries: definition-only, the caller owns the wiring
        }

        $schema['properties'][$block->name] = self::rootWiring($block);
        $schema['x-manifest']['blocks'][$block->name] = $block->toMeta();
    }

    return ['schema' => $schema, 'report' => $report]; // definition order preserved — new definitions append
}
```

`rootWiring()` derives from the mode (never hand-authored):

```php
private static function rootWiring(Block $block): array
{
    return match ($block->mode) {
        'items' => ['type' => 'array', 'items' => ['$ref' => '#/definitions/'.$block->name]],
        'config' => ['$ref' => '#/definitions/config'],
        'passthrough' => ['type' => 'object'],
        default => ['$ref' => '#/definitions/'.$block->name], // class / command
    };
}
```

Per-block body (the plan's run order, keyed by block name per §4.4):

```php
private static function block(array $schema, Block $block, Closure $source, Report $report): array
{
    $definitions = $schema['definitions'] ?? [];
    $prior = is_array($definitions[$block->name] ?? null) ? $definitions[$block->name] : null;

    if ($block->mode === 'class' && $block->class !== null) {
        $fragment = self::render($block->class, $source);
        $fragment['x-manifest-block'] = $block->toMeta();
        $fragment = self::shapes($fragment, $block->shapes); // block-map shape overrides win over derived
        $fragment = self::remerge($fragment, $prior, $block->class, $source, $report); // the §5 gate
        $definitions[$block->name] = $fragment;
    } elseif (is_array($prior)) {
        $report->outOfScope($block->name); // items/config/passthrough/command defs are not projections
    }

    foreach ($block->continuations ?? [] as $class) {
        $definitions[$class] = self::continuation($schema, $class, $source, $block, $report);
    }

    $schema['definitions'] = $definitions;

    return $schema;
}
```

`remerge()` implements the plan's §5 gate verbatim (native-declarable → fragment shape + curated
description byte-for-byte; skipped/protected/curation-only → prior key byte-for-byte; undecided
shape → curated shape wins), extended for `x-manifest-call`: a curated key's metadata survives;
a generated key whose curated predecessor lacked metadata gets the derived one. The drift
report (`Report`) carries the plan's five sections (`native-only`, `curation-only`,
`native-but-not-declarable`, `TODO`, `out of scope`) + `unwired` (definitions without root
wiring).

**Rewrite** `src/Internal/Commands/GenerateManifestSchemaCommand.php`:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Commands;

use Composer\Autoload\ClassLoader;
use Illuminate\Console\Command;
use RuntimeException;
use ZeroToProd\LaravelDeclaration\Internal\Manifest\Block;
use ZeroToProd\LaravelDeclaration\Internal\Manifest\BlockMap;
use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;

/** @internal */
class GenerateManifestSchemaCommand extends Command
{
    /** @var string */
    protected $signature = 'declaration:generate-manifest-schema
        {classes?* : The class FQCNs to project (definitions only — wiring comes from --map)}
        {--map= : Path to an ordered block-map JSON file; entries may name any class, any package}
        {--from= : Read curated prose/metadata from this schema (default: --out when it exists)}
        {--out= : Write target (default: the package root manifest.schema.json; `-` for stdout)}
        {--report : Print the drift report and write nothing}
        {--check : Fail on drift (the CI gate)}';

    /** @var array<int, string> */
    protected $aliases = ['laravel-declaration:generate-manifest-schema'];

    /** @var string */
    protected $description = 'Generate manifest.schema.json from an arbitrary list of classes';

    public function handle(): int
    {
        $out = is_string($this->option('out')) && $this->option('out') !== '-'
            ? $this->option('out') : dirname(__DIR__, 3).'/manifest.schema.json';

        $map = $this->map();
        $from = is_string($this->option('from')) ? $this->option('from') : (is_file($out) ? $out : null);
        $prior = $from !== null ? json_decode((string) file_get_contents($from), true, 512, JSON_THROW_ON_ERROR) : null;

        if (! $this->preflight($map->classTargets())) { // every class resolvable BEFORE any mutation
            return self::FAILURE;
        }

        $source = static fn (string $fqcn): ?string => ($path = self::locate($fqcn)) === null ? null : (string) file_get_contents($path);

        $result = SchemaGenerator::generate($map, $prior, $source);

        if ($this->option('report') || $result['report']->empty() === false) {
            $result['report']->print(fn (string $line): string => $this->components->{$line[0]}(substr($line, 2)));
        }

        if ($this->option('check') && ! $result['report']->empty()) {
            return self::FAILURE; // drift exists — regenerate and commit
        }

        if ($this->option('report')) {
            return self::SUCCESS; // --report never writes
        }

        $encoded = SchemaGenerator::encode($result['schema']);

        if ($out !== '-' && $prior !== null && $encoded === (string) file_get_contents($out)) {
            $this->components->info('No changes.'); // the no-op fast path — mtime untouched

            return self::SUCCESS;
        }

        if ($out === '-' || file_put_contents($out, $encoded) !== false) {
            if ($out === '-') {
                fwrite(STDOUT, $encoded);
            }

            return self::SUCCESS;
        }

        throw new RuntimeException("Failed to write schema to `$out`.");
    }

    private function map(): BlockMap
    {
        if (is_string($this->option('map'))) {
            return BlockMap::from((array) json_decode((string) file_get_contents($this->option('map')), true, 512, JSON_THROW_ON_ERROR));
        }

        $blocks = (array) config('laravel-declaration.blocks', []);

        if ($blocks !== []) {
            return BlockMap::from($blocks); // the shipped default map
        }

        return new BlockMap(array_map(Block::bare(...), (array) $this->argument('classes'))); // bare-classes mode
    }

    /**
     * @param  list<string>  $classes
     * @return bool false when any class is unresolvable — the run fails BEFORE any mutation
     */
    private function preflight(array $classes): bool
    {
        $ok = true;

        foreach ($classes as $class) {
            if (self::locate($class) === null) {
                $this->components->error("No source file for $class"); // ValidateCommand missing-manifest precedent
                $ok = false;
            }
        }

        return $ok;
    }

    public static function locate(string $fqcn): ?string
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $path = $loader->findFile($fqcn);

            if (is_string($path) && is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
```

(The invariant the two code paths above honor: *fail before any mutation* — the current
command's pre-flight contract, kept.)

**Modify** `config/laravel-declaration.php` — add the `blocks` default (the §4.3 map, verbatim)
and keep `manifest`. The `providers` key's per-component list is retired in Phase 4.

**Tests** (`tests/Feature/GenerateManifestSchemaCommandTest.php` rewritten):

- Whole-file bootstrap: no prior schema — an arbitrary class list (Laravel + the new fixture
  class) + a map file produces a complete, root-wired schema; run twice, second run is a no-op
  (mtime unchanged).
- Curation preservation: run against a copy of the shipped schema with hand-edited prose/pattern
  metadata → prose and `x-manifest-call` preserved through regeneration (the re-merge gate).
- The two-definitions-one-class case: `router` + `routes` both project `Router` with distinct
  shape policies; both definitions generated; both root-wired.
- `--report` prints the five + `unwired` sections and writes nothing; `--check` fails on drift.
- Pre-flight: the first unresolvable class fails the run before any mutation.
- `composer check` gates every branch.

### Phase 3 — One resolver layer

**Goal (kills V6):** one runtime vocabulary for PHP-symbol and path references, replacing the
five scattered implementations. No behavior change yet — Phase 4 consumes it.

**Create** `src/Internal/Mapping/Resolver.php`:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Mapping;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use LogicException;

/** @internal The one reference layer — the runtime counterpart of the schema's shared primitives (§4.2). */
final class Resolver
{
    /** @var array<string, mixed> */
    private array $files = [];

    public function __construct(private readonly Container $Container) {}

    public function resolve(mixed $value, string $vocabulary): mixed
    {
        return match ($vocabulary) {
            'passthrough', 'class', 'reference' => $value,
            'callable' => $this->callable($value),
            'file' => $this->file($value),
            'path' => $this->path($value),
            'concrete' => $this->concrete($value),
            default => throw new LogicException("Unknown resolution vocabulary [$vocabulary]."),
        };
    }

    public function callable(mixed $value): mixed
    {
        if (is_string($value) && str_ends_with($value, '.php')) {
            return $this->file($value); // the file returns the Closure
        }

        if (is_string($value) && ! str_contains($value, '@') && ! str_contains($value, '::') && function_exists($value)) {
            return $value; // a namespaced function resolves directly
        }

        return $value; // Class@method / Class::method / invokable — Laravel's Container::call accepts these
    }

    public function wrappedCallable(mixed $value): Closure // for APIs that need a Closure, not a reference
    {
        return function (...$arguments) use ($value): mixed {
            $callable = $this->callable($value);

            return $callable instanceof Closure
                ? $callable(...$arguments)
                : $this->Container->call($callable, $this->named($arguments));
        };
    }

    public function file(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $path = $this->path($value);

        $value = ($this->files[$path] ??= require $path); // required once per process

        if (! $value instanceof Closure) {
            throw new LogicException("The reference [$path] must return a Closure, ".get_debug_type($value).' returned.');
        }

        return $value;
    }

    public function path(mixed $value): string
    {
        return is_string($value) && ! Str::startsWith($value, ['/', '\\'])
            ? $this->Container->make('path.base').DIRECTORY_SEPARATOR.$value // basePath()
            : (string) $value;
    }

    public function concrete(mixed $value): mixed // the `concrete` shared definition's semantics
    {
        if ($value === '~') {
            return null; // self-binding
        }

        if (is_string($value) && str_ends_with($value, '.php')) {
            return $this->file($value);
        }

        return $value;
    }

    /** @param  array<mixed>  $arguments */
    private function named(array $arguments): array
    {
        return array_combine(array_map(strval(...), array_keys($arguments)), $arguments);
    }
}
```

(Exact basePath binding id (`path.base`) verified against the app's path bindings at
implementation; the convention is one method, one copy.)

**Tests** `tests/Feature/ResolverTest.php`: each vocabulary branch, the once-per-process file
cache, the Closure assert, `~` self-binding, relative/absolute paths.

### Phase 4 — The general mapper + one generic provider

**Goal (kills V4, V5, V9, the provider half of V10):** one mapper dispatches every block from
the schema's metadata; one generic provider replaces the thirteen; the dead attribute taxonomy
is deleted. `Manifest.php` and declaration classes still exist (Phase 5) — the mapper reads the
**schema**, not them, so this is a pure swap.

**Create** `src/Internal/Mapping/Mapper.php`:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Mapping;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use LogicException;

/** @internal The one runtime engine — dispatches manifest blocks per the schema's call metadata (§4.1). */
final class Mapper
{
    /** @param  array<string, mixed>  $schema  the decoded manifest.schema.json */
    public function __construct(
        private readonly Application $Container,
        private readonly array $schema,
        private readonly Resolver $Resolver,
    ) {}

    /**
     * @param  string  $name  the block name — the schema's `x-manifest.blocks` key
     * @param  mixed  $data  the manifest's raw, schema-validated block data
     */
    public function block(string $name, mixed $data): void
    {
        $block = $this->blocks()[$name] ?? null;

        if (! is_array($block) || $this->absent($data)) {
            return; // opt-in: absent blocks do nothing (STYLE 6.2)
        }

        match ((string) ($block['mode'] ?? 'class')) {
            'config' => $this->configBlock((array) $data),
            'passthrough', 'command' => null, // command blocks dispatch through the command that owns them (§4.3)
            'items' => $this->items($block, (array) $data),
            default => $this->classBlock($block, (array) $data),
        };
    }

    /** @param  array<string, mixed>  $block */
    private function classBlock(array $block, array $data): void
    {
        $class = (string) $block['class'];
        $timing = (string) ($block['timing'] ?? 'boot');
        $apply = fn (object $Target): void => $this->keys($class, $block, $data, $Target);

        match (true) {
            in_array($timing, ['register', 'boot'], true) => $apply($this->Container->make($class)),
            in_array($timing, ['registered', 'booting', 'booted'], true) => $this->Container->make($class)->{$timing}($apply),
            str_starts_with($timing, 'after-resolving:') => $this->Container->afterResolving(
                substr($timing, strlen('after-resolving:')),
                fn (object $Resolved): void => $apply($Resolved),
            ),
            default => throw new LogicException("Unknown block timing [$timing]."),
        };
    }

    /** @param  array<string, mixed>  $data */
    private function configBlock(array $data): void
    {
        foreach ($data as $file => $values) {
            if (! is_array($values)) {
                throw new LogicException("The `config.$file` entry must be a map of config keys.");
            }

            Config::set(Arr::prependKeysWith($values, "$file.")); // the one kept key transform (§4.3)
        }
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $data
     */
    private function items(array $block, array $data): void
    {
        $item = (array) ($block['item'] ?? []);
        $call = is_array($item['call'] ?? null) ? (array) $item['call'] : null;

        if ($call === null) {
            return; // store mode: the items are seam-consumed data — nothing to dispatch (§4.3)
        }

        $Target = $this->Container->make((string) $block['class']);
        $method = (string) $call['method'];
        $itemKeys = array_keys((array) ($call['args'] ?? [])); // item key → parameter name, in call order

        foreach (array_values($data) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $this->invoke($Target, $method, array_map(static fn (string $itemKey): mixed => $entry[$itemKey] ?? null, $itemKeys));
        }
    }

    /**
     * @param  string  $class  the DEFINITION key (block name, or continuation FQCN) — not necessarily the target class
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $data
     */
    private function keys(string $class, array $block, array $data, object $Target): void
    {
        foreach ($data as $key => $value) {
            $this->dispatch($class, $block, (string) $key, $value, $Target);
        }
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function dispatch(string $class, array $block, string $key, mixed $value, object $Target): void
    {
        $call = Call::fromMeta($this->meta($class, $key));
        $static = (bool) ($block['static'] ?? false);

        if ($call->shape === CallShape::Chain) {
            $this->chainDispatch($Target, $call, (array) $value, $key); // the one non-uniform shape

            return;
        }

        $calls = match ($call->shape) {
            CallShape::Setter => $value === null ? [] : [['args' => [$this->resolve($value, $call)]]],
            CallShape::Call => [$this->namedCall($value, $call)],
            CallShape::CallList => $this->listCalls($value, $call),
            CallShape::Binding => $this->entryCalls($value, $call),
            CallShape::BindingEach, CallShape::PrependEach => $this->eachCalls($value, $call),
            CallShape::Append => $this->itemCalls($value, $call),
            CallShape::Flag => $value === true ? [['args' => []]] : throw new InvalidArgumentException("Key [$key] accepts `true` only."),
            CallShape::Undecided => throw new LogicException(
                "Undecided shape for [$key] on [$class] — regenerate the schema or declare shapes.$key in the block map."
            ),
        };

        foreach ($calls as $candidate) {
            $Returned = $this->invoke($Target, $candidate['method'], $candidate['args'], $static);
            $this->continuation($candidate['extra'] ?? [], $Returned); // extras ride the returned object (§4.1)
        }
    }

    /** @return array<string, mixed> the definition's properties[$key]['x-manifest-call'] */
    private function meta(string $class, string $key): array
    {
        $definition = $this->schema['definitions'][$class] ?? null;
        $keySchema = is_array($definition) ? ($definition['properties'][$key] ?? null) : null;
        $meta = is_array($keySchema) ? ($keySchema['x-manifest-call'] ?? null) : null;

        return is_array($meta) ? $meta : throw new LogicException(
            "No call metadata for [$key] on [$class] — regenerate the schema or declare shapes.$key in the block map."
        );
    }

    /**
     * Item map → argument set. Item map keys are parameter names (or positions); extras are continuations.
     * This is BlueprintAction::fromDefinition's matching, generalized (V7).
     *
     * @return array{method: string, args: list<mixed>, extra: array<string, mixed>}
     */
    private function namedCall(mixed $value, Call $call): array
    {
        $names = $call->params ?? [];
        $args = [];
        $extra = [];

        foreach ((array) $value as $name => $argument) {
            if ($call->recurse !== null && (string) $name === $call->recurse) {
                $args[count($names) - 1] = fn (object $Target): mixed => $this->block($call->recurse, $argument); // the synthesized Closure fills the LAST parameter (§4.1)

                continue;
            }

            $position = is_int($name)
                ? $name                                                       // positional list item
                : array_search((string) $name, $names, true);                 // parameter-name key

            if ($position === false) {
                $extra[(string) $name] = $argument;

                continue;
            }

            $args[$position] = $this->resolve($argument, $call);
        }

        ksort($args);

        return ['method' => $call->method, 'args' => array_values($args), 'extra' => $extra];
    }

    /** @return list<array{method: string, args: list<mixed>, extra: array<string, mixed>}> */
    private function listCalls(mixed $value, Call $call): array
    {
        $calls = [];

        foreach (is_array($value) ? array_values($value) : [$value] as $item) {
            $calls[] = is_array($item)
                ? $this->namedCall($item, $call)   // item map: keys = parameter names, extras = continuation
                : ['method' => $call->method, 'args' => [$this->resolve($item, $call)], 'extra' => []]; // single value
        }

        return $calls;
    }

    /** @return list<array{method: string, args: list<mixed>, extra: array<string, mixed>}> */
    private function entryCalls(mixed $value, Call $call): array
    {
        if (is_array($value) && $value !== [] && array_is_list($value)) {
            return array_map( // the shared `binding` def's list form: one-arg self-binding calls (V9's invented null-item throw is gone)
                fn (mixed $item): array => ['method' => $call->method, 'args' => [$this->resolve($item, $call)], 'extra' => []],
                $value,
            );
        }

        $calls = [];

        foreach ((array) $value as $key => $argument) {
            $calls[] = ['method' => $call->method, 'args' => [$key, $this->resolve($argument, $call)], 'extra' => []];
        }

        return $calls;
    }

    /** @return list<array{method: string, args: list<mixed>, extra: array<string, mixed>}> one call per item of (array) value */
    private function eachCalls(mixed $value, Call $call): array
    {
        $reverse = $call->shape === CallShape::PrependEach; // prepend: declaration order lands at the target head
        $calls = [];

        foreach ((array) $value as $key => $argument) {
            foreach ($reverse ? array_reverse((array) $argument) : (array) $argument as $item) {
                $calls[] = ['method' => $call->method, 'args' => [$key, $this->resolve($item, $call)], 'extra' => []];
            }
        }

        return $calls;
    }

    /** @return list<array{method: string, args: list<mixed>, extra: array<string, mixed>}> */
    private function itemCalls(mixed $value, Call $call): array
    {
        return array_map(
            fn (mixed $item): array => ['method' => $call->method, 'args' => [$this->resolve($item, $call)], 'extra' => []],
            array_values((array) $value),
        );
    }

    /** The one chain today: app.when — entry key = when($abstract); {needs, give} = the remaining steps. */
    private function chainDispatch(object $Target, Call $call, array $value, string $key): void
    {
        $steps = array_values((array) ($call->steps ?? []));

        if ($steps === []) {
            throw new LogicException("Chain shape for [$key] requires a step list.");
        }

        foreach ($value as $abstract => $arguments) {
            $chained = $Target;

            foreach ($steps as $index => $step) {
                $method = $index === 0 ? $call->method : (string) $step;
                $argument = $index === 0 ? $abstract : ($arguments[$step] ?? null);

                $chained = $chained->{$method}($this->resolve($argument, $call));
            }
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function continuation(array $extra, mixed $Returned): void
    {
        if ($extra === [] || ! is_object($Returned)) {
            return;
        }

        $this->keys($Returned::class, ['class' => $Returned::class], $extra, $Returned); // the FQCN-keyed continuation definition (§4.4)
    }

    private function invoke(object $Target, string $method, array $arguments, bool $static = false): mixed
    {
        return $static
            ? $Target::{$method}(...$arguments)
            : $Target->{$method}(...$arguments); // native failures propagate (STYLE 3.3)
    }

    private function resolve(mixed $value, Call $call): mixed
    {
        return $this->Resolver->resolve($value, $call->resolve[0] ?? 'passthrough');
    }

    /** @return array<string, array<string, mixed>> */
    private function blocks(): array
    {
        return (array) ($this->schema['x-manifest']['blocks'] ?? []); // the schema is the single source of truth (G2)
    }

    private function absent(mixed $data): bool
    {
        return $data === null || $data === false || $data === [] || $data === '';
    }
}
```

The contract each helper honors is §4.1 exactly: argument mapping (map key → first argument;
value/remaining keys → remaining parameters by name, positional lists in signature order),
resolution per the key's `resolve` vocabulary, guards (Phase 6) evaluated before continuation
keys, and continuation only when the native return type is an object. Every branch above is
pinned by the tests in Phase 4's test list.

The implementation notes are deliberate: the doc fixes the **contract** per shape (§4.1) and the
dispatch function's branching above; the remaining private helpers are mechanical over that
contract. Every branch's behavior is pinned by the tests below.

**Create** `src/Providers/ManifestServiceProvider.php` — the one provider:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Internal\ManifestStore;
use ZeroToProd\LaravelDeclaration\Internal\Mapping\Mapper;
use ZeroToProd\LaravelDeclaration\Internal\Mapping\Resolver;

/** @internal The one provider — every block dispatches through the mapper, in block-map order (V4). */
class ManifestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ManifestStore::class);
        $this->app->singleton(Resolver::class, fn ($app): Resolver => new Resolver($app));
        $this->app->singleton(Mapper::class, fn ($app): Mapper => new Mapper($app, $this->schema(), $app->make(Resolver::class)));

        $Store = $this->app->make(ManifestStore::class);
        $Mapper = $this->app->make(Mapper::class);

        foreach ($this->blocks() as $name => $block) { // blocks that must apply during register() (the config mode)
            if (($block['timing'] ?? 'boot') === 'register') {
                $Mapper->block((string) $name, $Store->block((string) $name));
            }
        }
    }

    public function boot(): void
    {
        $Store = $this->app->make(ManifestStore::class);
        $Mapper = $this->app->make(Mapper::class);

        foreach ($this->blocks() as $name => $block) { // block-map order = dispatch order (deterministic, §4.3)
            if (($block['timing'] ?? 'boot') === 'register') {
                continue; // already applied
            }

            $Mapper->block((string) $name, $Store->block((string) $name));
            // register / boot apply inline; registered / booting / booted / after-resolving:<binding>
            // synthesize their callback inside Mapper::classBlock and fire later.
        }
    }

    /** @return array<string, array<string, mixed>> the ordered block map — the schema's x-manifest, mirrored in config */
    private function blocks(): array
    {
        return (array) config('laravel-declaration.blocks', []);
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return (array) json_decode((string) file_get_contents(dirname(__DIR__, 2).'/manifest.schema.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
```

The complete timing contract: `register`-timed blocks apply in `register()`; every other block
applies in `boot()` in block-map order, with `registered`/`booting`/`booted`/`after-resolving:<binding>`
synthesized inside `Mapper::classBlock` (the closure it registers performs the key dispatch).

**Modify** `src/DefaultProviders.php` — the list collapses to the one generic provider;
`merge/replace/except` keep their signatures (they now operate on that one entry — the extension
surface remains, per-component entries are gone).

**Modify** `src/LaravelDeclarationProvider.php` — register `ManifestServiceProvider` (the
block-map default replaces the `providers` config list; the config key remains for
replacement/extension of the one entry).

**Delete** the 13 provider files (their bespoke paths are covered by the retirement table):

| Retired bespoke path | General mechanism that replaces it |
| --- | --- |
| `Router…` five `selected(...)` loops | shapes `binding`/`binding_each`/`prepend_each`/`append`/`setter` from metadata |
| `Kernel…` loops + `whenRequestLifecycleIsLongerThan` hand roll | `setter` + the value's curated shape; duration-handler wrap = `callable` resolution |
| `Kernel…` `CarbonInterval` acceptance | removed (V9) — declare a numeric threshold or resolve the reference yourself |
| `View…` comma-splitting | removed (V9) — one call per entry, key passed through (Laravel's `composer` accepts arrays; multi-view composition is the manifest author's data, e.g. separate entries per view) |
| `View…`/`Blade…`/`App…`/`Kernel…` `absolute()`/`basePath()` | Resolver `path` |
| `App…` `reference()`/`fileValue()`/`wrapCallback()`/`wrapExtender()` | Resolver `callable`/`wrappedCallable` |
| `App…` `when/needs/give` | the `chain` shape (curated metadata on `app.when`) |
| `App…` list-binding null-item throw | removed (V9) — list items call with one argument (Laravel's self-binding) |
| `App…` `*If` Closure skip | removed (V9) — Laravel's `bindIf` semantics govern |
| `Routes…` `TWO_ARGUMENT_PENDING_SETTERS` + match arms + probing | `call_list` + derived continuation (native return type = object → extras ride the return value) |
| `Routes…` `wrapMissingHandler` | Resolver `callable` |
| `Validator…` registry loop + `arguments()` | `binding` + the value's `{extension, message}` shape from the shared `extension` definition |
| `Gate…`/`Response…`/`Database…`/`Providers…` loops | `binding` / `binding` + wrap / `binding_each` / items + fixed `call` |
| `Pagination…` `Preset` booleans | `flag` (curated keys, re-merged) + `static` block metadata |
| `Config…` dot-prefixing | the `config` block mode (the one kept key transform, documented) |

**Delete** the ten dead attribute files (V10 first cut — zero consumers, verified):
`ColumnModifier.php`, `ColumnType.php`, `Composer.php`, `ForeignKeyModifier.php`, `Hook.php`,
`Location.php`, `Redirect.php`, `TableConstraint.php`, `TableOption.php`, `ViewNamespace.php`.

**Tests**: one feature test per block asserting the manifest block dispatches identically to the
old provider (the existing `tests/Feature/*RegistrationTest.php` suite is the oracle — retarget
it); new tests for the mapper's shape branches, timing, continuation, recursion, `static`,
`chain`, `undecided` refusal, and the Resolver vocabularies; `composer check` every branch.

### Phase 5 — Delete the typed mirror

**Goal (kills V8):** raw manifest data + schema validation only. `Manifest.php`, the 23
declaration classes, the `DataModel` trait, and the `data-model` dependencies die; the seams and
commands read a raw `ManifestStore`.

**Create** `src/Internal/ManifestStore.php`:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

/** @internal The raw, schema-validated manifest data — no typed mirror (STYLE 3.4/6.4: the schema is the only validation layer). */
final class ManifestStore
{
    /** @param  array<string, mixed>  $data  the decoded manifest YAML */
    public function __construct(private readonly array $data = []) {}

    public function block(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    /** @return array<string, array<string, mixed>> the items-mode block's items, keyed by the block's `item.key` */
    public function items(string $block, string $key): array
    {
        return array_combine(
            array_map(static fn (array $item): string => (string) $item[$key], array_values($this->block($block) ?? [])),
            array_values($this->block($block) ?? []),
        );
    }

    /** @return array<string, mixed>|null */
    public function item(string $block, string $key): ?array
    {
        return $this->items($block, $key)[$key] ?? null;
    }

    public function has(string $block): bool
    {
        return array_key_exists($block, $this->data);
    }
}
```

**Delete** `src/Manifest.php`, `src/App.php`, `src/Router.php`, `src/Kernel.php`, `src/View.php`,
`src/Blade.php`, `src/Response.php`, `src/Pagination.php`, `src/Validator.php`, `src/Gate.php`,
`src/Database.php`, `src/Provider.php`, `src/Model.php`, `src/Query.php`, `src/Request.php`,
`src/Routes.php`, `src/Route.php`, `src/RouteGroup.php`, `src/RouteResource.php`,
`src/RouteView.php`, `src/RouteRedirect.php`, `src/RoutePermanentRedirect.php`,
`src/Schema.php`, `src/TableDefinition.php`, `src/Internal/DataModel.php` (the DataModel users
die together with it — `BlueprintAction` is DataModel-free and survives until Phase 6), and the
attribute files whose semantics moved into the schema's call metadata (`Key`, `Binding`,
`Setter`, `Append`, `AppendTo`, `PrependTo`, `Conditional`, `Path`, `Preset`, `ClassDefault`).
The guard family (`NoGuards`, `None`, `Guards`, `Guard`, `GuardKind`, `ActionGuard`, and the six
guard attribute classes) dies in Phase 6 with the guard registry.

**Modify** the seams to consume raw items (each seam keeps its orchestration; only the data
source changes):

| Seam | Old (typed) | New (raw) |
| --- | --- | --- |
| [DeclaredRequest.php](../src/DeclaredRequest.php) | `$this->container->make(Manifest::class)->requests->get($name)` → `Request` object | `app(ManifestStore::class)->item('requests', $name)` → raw array; keys read with `?? null` defaults per the definition's `Describe::default`/`nullable` semantics |
| [DeclaredModel.php](../src/DeclaredModel.php) | `app(Manifest::class)->models->get($class ?? static::class)` → `Model` object | `app(ManifestStore::class)->item('models', $class ?? static::class)` → raw array; `properties()` becomes the raw array minus the reserved `class` key |
| [DeclaredQuery.php](../src/DeclaredQuery.php) | `app(Manifest::class)->queries->get($name)` → `Query` object | `app(ManifestStore::class)->item('queries', $name)` → raw array (`name`, `model`, `relation`, clauses as the remaining keys) |
| [DeclaredView.php](../src/DeclaredView.php) | `app(Manifest::class)` reads (`$Manifest->queries->has($value)`, view-data) | `app(ManifestStore::class)` — `queries->has($value)` becomes `->item('queries', $value) !== null`; view-data blocks read raw arrays |

**Modify** [src/LaravelDeclarationProvider.php](../src/LaravelDeclarationProvider.php):
`resolveManifest()` returns `new ManifestStore(Yaml::parseFile($filename) ?? [])` bound as
`ManifestStore::class`; commands take `ManifestStore` from the container. The `manifest` config
key and YAML parsing stay.

**Modify** [src/Internal/Commands/MigrateCommand.php](../src/Internal/Commands/MigrateCommand.php):
`handle(ManifestStore $manifest)`; `$manifest->block('schema')` is the raw schema data; the
fixed lifecycle order stays. Interim (until Phase 6): table bodies validate + construct
`BlueprintAction::fromDefinition($method, $value)` per entry directly from the raw arrays —
`TableDefinition` is gone with the typed layer; guard evaluation is still the Phase-5 bespoke
loop and moves into the engine in Phase 6.

**Modify** [composer.json](../composer.json): remove `zero-to-prod/data-model` and
`zero-to-prod/data-model-helper` from `require`.

**Tests**: `ManifestFactoryTest` → `ManifestStoreTest`; each seam's feature suite retargeted to
raw arrays with the same assertions (the suite is the oracle); composer-require-checker re-run.

### Phase 6 — The schema subsystem onto the engine

**Goal (kills V7):** `BlueprintMethodKind`, `Guards`, `GuardKind`, `Guard`, `ActionGuard`, and
`BlueprintAction`'s bespoke classification collapse into the engine: one **construct**
continuation shape onto `Blueprint` (already in §4.1), one **guard registry** with named
predicates, and one **data-driven policy** attaching guard metadata. `TableDefinition` folds
into raw `call_list` dispatch; `MigrateCommand` keeps its fixed order and drives through the
mapper.

**Create** `src/Internal/Mapping/Guards/Predicate.php`:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Mapping\Guards;

use Illuminate\Database\Schema\Builder;

/** @internal A named, registered, reusable guard predicate — the general conditional-execution mechanism. */
interface Predicate
{
    /** @param  string|list<string>|null  $target */
    public function passes(Builder $Builder, string $table, string|array|null $target): bool;
}
```

**Create** the six predicates by **renaming** the existing guard attribute classes' logic into
plain predicates (no attributes): `ColumnExists`, `ColumnMissing`, `IndexExists`, `IndexMissing`,
`ForeignKeyExists`, `ForeignKeyMissing` (each a one-method class moving the current `Guard`
subclass bodies), and `src/Internal/Mapping/Guards/Registry.php`:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Mapping\Guards;

use Illuminate\Database\Schema\Builder;
use LogicException;

/** @internal Named predicates, keyed by the `x-manifest-guard.kind` vocabulary. */
final class Registry
{
    private const array PREDICATES = [
        'column-exists' => ColumnExists::class,
        'column-missing' => ColumnMissing::class,
        'index-exists' => IndexExists::class,
        'index-missing' => IndexMissing::class,
        'foreignKey-exists' => ForeignKeyExists::class,
        'foreignKey-missing' => ForeignKeyMissing::class,
    ];

    /**
     * @param  string|list<string>|null  $target
     */
    public function passes(Builder $Builder, string $table, string $kind, string|array|null $target): bool
    {
        if ($kind === 'none') {
            return true;
        }

        $predicate = self::PREDICATES[$kind] ?? throw new LogicException("Unknown guard kind [$kind].");

        return (new $predicate)->passes($Builder, $table, $target);
    }
}
```

**Create** `src/Internal/Mapping/Guards/BlueprintPolicy.php` — the `CANONICAL_COLUMNS` table and
the derivation rules as pure data + one deterministic function (moved from `Guards.php` and
`BlueprintMethodKind::LIFECYCLE`, no regex doc-comment parsing — the return kind comes from the
parser backend's `Signature` instead):

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Mapping\Guards;

use ZeroToProd\LaravelDeclaration\Internal\Manifest\Signature;

/** @internal The Blueprint guard policy — domain data (canonical columns) + deterministic derivation (V7). */
final class BlueprintPolicy
{
    /** Conventional column targets for zero-argument factories and droppers — moved verbatim from Guards::CANONICAL_COLUMNS. */
    private const array CANONICAL_COLUMNS = [
        'id' => 'id',
        'timestamps' => 'created_at',
        'timestampsTz' => 'created_at',
        'nullableTimestamps' => 'created_at',
        'nullableTimestampsTz' => 'created_at',
        'datetimes' => 'created_at',
        'rememberToken' => 'remember_token',
        'softDeletes' => 'deleted_at',
        'softDeletesTz' => 'deleted_at',
        'softDeletesDatetime' => 'deleted_at',
        'dropTimestamps' => 'created_at',
        'dropTimestampsTz' => 'created_at',
        'dropSoftDeletes' => 'deleted_at',
        'dropSoftDeletesTz' => 'deleted_at',
        'dropRememberToken' => 'remember_token',
    ];

    /** Moved verbatim from BlueprintMethodKind::LIFECYCLE — never declarable inside a table body. */
    private const array LIFECYCLE = [
        'build', 'toSql', 'create', 'drop', 'dropIfExists', 'rename', 'after',
        'addFluentCommands', 'addAlterCommands', 'macro', 'mixin', 'flushMacros',
    ];

    /**
     * The guards for one Blueprint method key, derived from its normalized signature — no
     * doc-comment regex: the return kind comes from the parser backend's `Signature` (Phase 1).
     *
     * - lifecycle methods are never declarable (the generator excludes the key)
     * - zero-param canonical factories/droppers become policy-declared `flag` keys with a
     *   column guard (the only zero-param flags the schema admits for this continuation)
     * - `drop*`/`rename*` methods guard exists on their string arguments
     * - everything else guards missing on its canonical or named column target
     *
     * @return list<array{kind: string, param?: string, target?: string}> the `x-manifest-guard` metadata
     */
    public static function for(string $method, Signature $signature): array
    {
        if (in_array($method, self::LIFECYCLE, true)) {
            return [];
        }

        $guards = [];

        if ($signature->params === []) { // policy-declared flag: canonical factory or dropper
            return isset(self::CANONICAL_COLUMNS[$method])
                ? [['kind' => str_starts_with($method, 'drop') ? 'column-exists' : 'column-missing', 'target' => self::CANONICAL_COLUMNS[$method]]]
                : [];
        }

        $params = $signature->params;

        if (str_starts_with($method, 'rename') && count($params) === 2) { // from → exists, to → missing
            return [
                ['kind' => 'column-exists', 'param' => $params[0]->name],
                ['kind' => 'column-missing', 'param' => $params[1]->name],
            ];
        }

        foreach ($params as $position => $param) {
            if ($param->builtin === 'string') {
                $guards[] = ['kind' => str_starts_with($method, 'drop') ? 'column-exists' : 'column-missing', 'param' => $param->name];

                break; // the first string parameter names the guard target (ColumnGuards' argument() rule)
            }
        }

        return $guards;
    }

    /** @return list<string> the lifecycle methods — the generator's exclusion gate for this continuation */
    public static function lifecycle(): array
    {
        return self::LIFECYCLE;
    }
}
```

**Modify** the generator (Phase 2's `block()`): for the `schema` block (`guards: blueprint`),
per Blueprint-continuation key attach `x-manifest-guard` from the policy; lifecycle keys are
excluded from the generated `tableDefinition`.

**Modify** the mapper: before dispatching a continuation key carrying `x-manifest-guard`,
evaluate `Registry::passes()` (AND semantics — all guards must pass) with the guard target
resolved from the action's arguments by parameter name (the `Guards::argument()` logic,
generalized).

**Modify** `TableDefinition`/`BlueprintAction` merge into the engine: the schema block's
`tableDefinition` values dispatch as `call_list` items — item map keys = the Blueprint method's
parameter names, extra keys = Fluent modifiers validated against the continuation definition —
so `BlueprintAction::fromDefinition`'s named/positional matching becomes the engine's general
parameter matching and the file is deleted.

**Modify** `MigrateCommand`: the fixed order (drop → rename → create → alter) stays (legitimate
orchestration, STYLE Rule 5); guard evaluation and action dispatch route through the mapper.

**Delete** `src/Internal/BlueprintMethodKind.php`, `src/Internal/Guards.php`,
`src/Internal/GuardKind.php`, `src/Internal/ActionGuard.php`, `src/Internal/Guard.php`,
`src/BlueprintAction.php`, `src/TableDefinition.php`, and the guard attribute files
(`ColumnGuards`, `CommandGuards`, `ConvenienceGuards`, `ForeignKeyGuards`, `IndexGuards`,
`NoGuards`, `ColumnExists`, `ColumnMissing`, `IndexExists`, `IndexMissing`, `ForeignKeyExists`,
`ForeignKeyMissing`, `None`).

**Tests**: guard predicates (six, over testbench's sqlite fixture tables — the existing
`SchemaRegistrationTest` suite is the oracle), policy derivation (`drop*` → exists, add →
missing, canonical columns, morph targets, lifecycle blocklist), construct-continuation
dispatch, the AND guard semantics, and `MigrateCommand`'s order — all through the engine.

### Phase 7 — Alignment

**Goal:** the end state is the shipped state — docs, config, tooling, gates.

- [composer.json](../composer.json): `bc-check` removed from `check` and the script deleted
  (bin/bc-check.sh) — this release is deliberately breaking; `bc-check` is the wrong gate for it
  and would fail the whole plan otherwise. `description`/`suggest` updated for the general
  engine.
- [config/laravel-declaration.php](../config/laravel-declaration.php): `blocks` default map is
  the published configuration; `providers` key removed; the InstallCommand/Installer template
  writes the new config.
- [STYLE.md](../STYLE.md): the dispatch attribute vocabulary section is replaced by the shape
  vocabulary (§4.1) + block map (§4.3) — the definitions list (`binding`, `setter`, `append`…)
  now names the **schema metadata shapes**, and `selected(...)`'s rule 7.4 is replaced by the
  mapper dispatch rule.
- README + the `declarative-*.md` docs: the runtime section replaced by the mapper/block-map
  story; the curated-definitions story replaced by the generator + re-merge gate.
- MCP tools: [Api.php](../src/Internal/Mcp/Tools/Api.php) is already general (pure reflection) —
  no change; the `Install`/`Readme` tools pick up the new config keys.
- `composer check` green end-to-end: pint, rector-lint, phpstan (level 9), 100% coverage,
  require-check (the `data-model` requirement is gone).

## 6. Failure surface

| # | Failure | Raised when |
| --- | --- | --- |
| 1 | `RuntimeException`/command failure `No source file for $class` | pre-flight — before any mutation |
| 2 | `RuntimeException` `$class is not declared in its resolved source` | pre-flight |
| 3 | `PhpParser\Error` | pre-flight |
| 4 | `LogicException` `Undecided shape for `$key` on `$class` …` | the mapper dispatching an undecided/missing-metadata key |
| 5 | `LogicException` `Unknown guard kind [x]` / `Unknown resolution vocabulary [x]` | the guard registry / resolver meeting unknown vocabulary |
| 6 | `LogicException` from a `.php` reference not returning a `Closure` | the Resolver (the old per-provider messages) |
| 7 | Native Laravel failures (`BadMethodCallException`, argument errors) | where Laravel fails — never pre-empted by package code (STYLE 3.3) |

## 7. Tests (the whole plan)

| # | Suite | Asserts |
| --- | --- | --- |
| 1 | `ShapeClassifierTest` + `ReflectionSignature` parity harness | the shape derivation is identical for the parser backend and reflection; every shape precondition; the `undecided` fallback |
| 2 | `GenerateManifestSchemaCommandTest` (rewritten) | whole-file bootstrap from an arbitrary class list; idempotence (mtime); curation preservation; `router`+`routes` two-definitions-one-class; `--report`/`--check`; pre-flight |
| 3 | `ResolverTest` | each vocabulary branch; once-per-process file cache; `~` self-binding; relative/absolute paths |
| 4 | `MapperTest` + the retargeted `*RegistrationTest` suites | every shape dispatch; timing; static; chain; continuation (return + construct); recursion; guard evaluation; `undecided` refusal |
| 5 | `ManifestStoreTest` + retargeted seam suites | raw items access; seams consuming arrays with identical behavior |
| 6 | `SchemaGeneratorTest` | per-key `x-manifest-call`; re-merge gate's five sections; definition keying by block name |
| 7 | Guard suites | the six predicates; the Blueprint policy; lifecycle exclusion |
| 8 | `composer check` | 100% coverage, phpstan level 9, pint, rector-lint, require-check |

## 8. Out of scope

- **The `config` block's key-prefixing transform** — kept, as the one documented key transform
  (Tier-2 seam orchestration over Laravel's dot-flat repository).
- **`declaration:migrate`'s lifecycle order** — fixed order is legitimate orchestration.
- **The Tier-2 seams** (`DeclaredRequest`, `DeclaredModel`, `DeclaredQuery`, `DeclaredView`) —
  they keep their orchestration; only their data source changes (Phase 5).
- **The MCP server** — already reflection-general.
- **Additional components (csrf, precognition, url, rate_limiter, …)** from
  component-index.md — the block map makes each an entry, not code; Tier-1 inventory work
  continues separately after the migration.

## 9. Open items

| # | Item | Resolution path |
| --- | --- | --- |
| 1 | The `--map` file format (inline JSON) vs config — both are supported; the shipped default lives in config | Phase 2 |
| 2 | `basePath()` binding id and the `path` vocabulary's exact behavior under `route:cache`/`config:cache` | Phase 3, verified against the existing Path acceptance tests |
| 3 | The `chain` shape's step metadata for `when/needs/give` (the only chain today) | Phase 4, curated metadata on `app.when` |
| 4 | Whether continuation derivation (native return type = object) should also cover `construct` (the closure parameter) — currently `construct` is block-map declared (`shapes.create`) | Phase 6 |
| 5 | The `Report`'s exact print format (sections, per-class grouping) | Phase 2, following generate-manifest-schema-plan §6 |
| 6 | The item-mode `call.args` naming (providers block: item key `class` → param `provider`) | Phase 4 |
