# Tier 1 API Map — Partially Mapped Gap Inventory

Source of truth: `vendor/laravel/framework/src/Illuminate` (`laravel/framework` v13.33.0), verified by direct reflection and source inspection against package source (`src/`) on the date of this document. This document inventories every `[/] Partially Mapped (Tier 1 API Map)` checklist item from [declarative-framework-api-mapping.md](declarative-framework-api-mapping.md), re-verifies each claim against the current implementation, lists the **missing native methods, column types, and options** with their native signatures, and corrects audit rows that are stale relative to the completed stages recorded in [declarative-request-to-view-roadmap.md](declarative-request-to-view-roadmap.md).

Status legend (from the audit doc): `[x]` Implemented, `[/]` Partially Mapped, `[!]` Indirectly Mapped / Glue-Substituted, `[ ]` Missing / Left To Do.

---

## 1. Verification Summary

Ten subsystems carried the `[/] Partially Mapped` status. Re-verification against `src/` splits them into three buckets:

| # | Subsystem | Manifest key | Audit | Verified today | Outcome |
|---|---|---|---|---|---|
| 1 | Service Container | `app:` | `[/]` | **All flagged gaps closed** | Stale → `[x]` |
| 2 | Router Configuration & Binders | `router:` | `[/]` | Resolved by declarative-router-configuration.md; `patterns()` a decided non-goal | `[x]` |
| 3 | Route Registration | `routes:` | `[/]` | Fully shipped: `uri` noun + dynamic `builders`, plus groups/resources/shortcuts ([declarative-route-registrars.md](declarative-route-registrars.md)) | `[x]` |
| 4 | View Factory & Namespaces | `view:` | `[/]` | Render-time surface dispatched via `DeclaredView` `factory:`; epilogue flushes mapped | `[x]` |
| 5 | View Dispatch Controller (Tier 2) | `DeclaredView` | `[/]` | Inline template dispatch + composer event bridge shipped | Stale → resolved |
| 6 | Form Request Declaration | `requests:` | `[/]` | **All flagged gaps closed**: `shouldFailOnUnknownFields` native-named, rule class-strings container-resolved, the `validator:` factory extensions shipped ([declarative-validator.md](declarative-validator.md)), conditional rules mapped onto `Rule::when()`/`Rule::unless()` | `[x]` |
| 7 | Form Request Seam (Tier 2) | `DeclaredRequest` | `[/]` | `authorize` resolves string refs via `Container::call` | `[/]` (narrower) |
| 8 | Database Schema & Blueprint | `schema:` | `[/]` | Column types/modifiers fully dynamic; table operations remain + 1 defect | `[/]` (narrower) |
| 9 | Eloquent Model Configuration | `models:` | `[/]` | Property/method surface complete; relations/casts-method/scopes/hooks reclassified as decided non-goals (§2.6) | Stale → `[x]` |
| 10 | Dynamic Model Synthesis (Tier 2) | `DeclaredModel` | `[/]` | No synthesis autoloader in source; Phase 2 doc/test/`DeclaredModel` deliverables shipped | `[/]` (narrower) |

Stale rows outside the `[/]` set, corrected here for the record:

- **`[!] Eloquent Query Builder` (`queries:`)**: The 11 synthetic attribute classes (`src/Attributes/*` query classes) were eliminated. `src/Query.php` now dynamically dispatches clauses onto `Builder`/`Relation` with native `BadMethodCallException` propagation (Rule 7 compliant) and uses the nouns `model`/`relation`. Remaining: the `relation` root still reads route parameters directly (Rule 5 tension) and subquery closures / raw expressions (`whereRaw`, `selectRaw`) are strings-only.
- **`[ ] Blade Compiler` (`blade:`)**, **`[ ] Response Factory` (`responses:`)**, **`[ ] Pagination Engine` (`pagination:`)**: Implemented (roadmap Stages 13–15; `src/Blade.php`, `src/Response.php`, `src/Pagination.php`). **`pagination:` narrows to `[/]`**: `defaultView`, `defaultSimpleView`, `useTailwind`, `useBootstrapFive` are mapped; the native `useBootstrap()` alias, `useBootstrapThree()` and `useBootstrapFour()` remain unmapped (§2.8). The roadmap §3.1 claim of "Tailwind, Bootstrap 4/5" overstated coverage and was corrected to "(Tailwind, Bootstrap 5)".
- **`[!] Session Store & Flash Data`**: `FlashAction` glue superseded by the `DeclaredAction` `with` / `withInput` / `withErrors` mapping to `RedirectResponse` (Phase 1, `docs/declarative-action.md`). Today that supersession is **spec-only**: no `src/DeclaredAction.php` exists in source (the earlier attribute-based implementation was reverted in commit `4fc53e2`), so the reclassification takes effect when Phase 1 lands.

---

## 2. Still-Missing Inventory (Context Complete)

Each item lists: the owning **Laravel API**, the **native signature** (v13.33.0), the **current mapping state**, the **missing things**, and the **proposed manifest key** following Design Rule 1 (key = method name).

### 2.1 Router Configuration & Binders — `router:` (`src/Router.php`)

Current mapping: `pattern`, `model`, `bind`, `middlewareGroup`, `aliasMiddleware`, `pushMiddlewareToGroup`, `prependMiddlewareToGroup`, `removeMiddlewareFromGroup`, `singularResourceParameters`, `resourceParameters`, `resourceVerbs`, `matched` (executed by `Providers/RouterDeclarationServiceProvider.php`).

Missing native `Illuminate\Routing\Router` methods:

| Native method | Signature | Purpose | Proposed key |
|---|---|---|---|
| `Router::patterns()` | `patterns(array $patterns): void` | Batch form of `pattern()`; registers multiple global regex patterns in one call | `router.patterns` — decided non-goal (declarative-router.md §2.6) |
| `Router::middlewareGroup()` | `middlewareGroup(string $name, array $middleware): Router` (`$this`) | Register a reusable middleware group at the router level (syncs `Kernel::setMiddlewareGroups`) | `router.middlewareGroup`  — mapped by declarative-router-configuration.md |
| `Router::aliasMiddleware()` | `aliasMiddleware(string $name, string $class): Router` (`$this`) | Register a route middleware alias at the router level (syncs `Kernel::setMiddlewareAliases`) | `router.aliasMiddleware`  — mapped by declarative-router-configuration.md |
| `Router::pushMiddlewareToGroup()` | `pushMiddlewareToGroup(string $group, string $middleware): Router` (`$this`) | Append a middleware to an existing group without redefining it | `router.pushMiddlewareToGroup`  — mapped by declarative-router-configuration.md |
| `Router::prependMiddlewareToGroup()` | `prependMiddlewareToGroup(string $group, string $middleware): Router` (`$this`) | Prepend a middleware to an existing group | `router.prependMiddlewareToGroup`  — mapped by declarative-router-configuration.md |
| `Router::removeMiddlewareFromGroup()` | `removeMiddlewareFromGroup(string $group, string $middleware): Router` (`$this`) | Remove a middleware from a group | `router.removeMiddlewareFromGroup`  — mapped by declarative-router-configuration.md |
| `Router::singularResourceParameters()` | `singularResourceParameters(bool $singular = true): void` | Force singular resource parameter names (`{post}` vs `{posts}`) | `router.singularResourceParameters`  — mapped by declarative-router-configuration.md |
| `Router::resourceParameters()` | `resourceParameters(array $parameters = []): void` | Override resource parameter names globally | `router.resourceParameters`  — mapped by declarative-router-configuration.md |
| `Router::resourceVerbs()` | `resourceVerbs(array $verbs = []): array|null` | Localize resource route verbs (create/edit); getter/setter hybrid | `router.resourceVerbs`  — mapped by declarative-router-configuration.md |
| `Router::matched()` | `matched(string|callable $callback): void` | Register a route-matched event listener on `Illuminate\Routing\Events\RouteMatched` (single callback — no `$events` argument; one call per list item per Rule 2) | `router.matched` (list of callbacks)  — mapped by declarative-router-configuration.md |

Context: `kernel.setMiddlewareGroups` / `kernel.setMiddlewareAliases` already map the `Kernel` setters, which keep the router's registries in sync; the router-level methods remain the native seam for group *mutation* (`push`/`prepend`/`remove`) and resource parameter globalization. `patterns()` is the plural batch of the already-mapped `pattern()`. Signatures re-verified against v13.33.0 (`Router.php:1021-1410`): `matched()` takes a single `$callback` (no `$events` parameter), the group-mutation trio returns `$this` (not `array`), and no `prepend` argument exists on `pushMiddlewareToGroup()`.

**Resolution**: [declarative-router-configuration.md](declarative-router-configuration.md) — nine of the ten proposed keys are mapped onto the `router:` block with attribute-selected dynamic dispatch (`#[Binding]` / `#[Setter]` / `#[AppendTo]` / `#[PrependTo]` / `#[Append]`; no per-key provider code), including the `kernel:` precedence model; `patterns()` is reaffirmed as a decided non-goal ([declarative-router.md](declarative-router.md) §2.6). This §2.1 is closed and §1 row 2 is reclassified `[/]` → `[x]`.

### 2.2 Route Registration — `routes:` (`src/Route.php`, `Providers/RoutesDeclarationServiceProvider.php`)

Current mapping: native `uri` noun; `methods`; `action`; dynamic `builders` dispatch (`RoutesDeclarationServiceProvider::applyBuilder()`) covering every public `Illuminate\Routing\Route` fluent method — including the previously missing `whereAlpha`, `whereAlphaNumeric`, `whereNumber`, `whereUlid`, `whereUuid`, `whereIn`, `secure`/`httpsOnly`, `httpOnly`, `bindingFields` (via `setBindingFields`), `missing`, `block`/`withoutBlocking`/`locksFor`/`waitsFor`, `can`, `metadata` (via `setMetadata`), `fallback`, `withTrashed`, `scopeBindings`, `withoutScopedBindings`, `enforcesScopedBindings`, `preventsScopedBindings`, `withoutMiddleware`, `when`/`unless` (`__get` proxies).

Missing native `Illuminate\Routing\Router` registration surfaces (Route-builder-level dispatch cannot express these; they create route collections):

| Native method | Signature | Purpose | Proposed key |
|---|---|---|---|
| `Router::group()` | `group(array $attributes, Closure|Router|string $routes): Router` | Shared route attributes (`prefix`, `middleware`, `domain`, `name`, `where`, `namespace`) across nested declarations | `routes.groups` (list of groups with nested `routes`) |
| `Router::resource()` | `resource(string $name, string $controller, array $options = []): PendingResourceRegistration` | 7 RESTful routes (`index`, `create`, `store`, `show`, `edit`, `update`, `destroy`) with `only`/`except`/`names`/`parameters`/`shallow`/`middleware` options | `routes.resources` |
| `Router::resources()` | `resources(array $resources, array $options = []): void` | Batch resource registration | `routes.resources` (map form) |
| `Router::apiResource()` | `apiResource(string $name, string $controller, array $options = []): PendingResourceRegistration` | Resource minus `create`/`edit` | `routes.apiResources` |
| `Router::apiResources()` | `apiResources(array $resources, array $options = []): void` | Batch API resource registration | `routes.apiResources` (map form) |
| `Router::singleton()` | `singleton(string $name, string $controller, array $options = []): PendingSingletonResourceRegistration` | Singleton resource routes (`show`, `edit`, `update`) with `only`/`except`/`creatable`/`deletable` | `routes.singletons` |
| `Router::singletons()` | `singletons(array $singletons, array $options = []): void` | Batch singleton registration | `routes.singletons` (map form) |
| `Router::apiSingleton()` | `apiSingleton(string $name, string $controller, array $options = []): PendingSingletonResourceRegistration` | API singleton variant | `routes.apiSingletons` |
| `Router::apiSingletons()` | `apiSingletons(array $singletons, array $options = []): void` | Batch API singleton registration | `routes.apiSingletons` (map form) |
| `Router::view()` | `view(string $uri, string $view, array $data = [], int|array $status = 200, array $headers = []): Route` | Native static-view route shortcut (bypasses `DeclaredView` for pure static content; dispatches `ViewController` with `setDefaults`, where an array `$status` carries the headers) | `routes.view` |
| `Router::redirect()` | `redirect(string $uri, string $destination, int $status = 302): Route` | Native redirect route shortcut | `routes.redirect` |
| `Router::permanentRedirect()` | `permanentRedirect(string $uri, string $destination): Route` | 301 redirect shortcut | `routes.permanentRedirect` |
| Verb dispatch keys | `Router::get()`, `post()`, `put()`, `patch()`, `delete()`, `options()`, `any()`, `match()` | Idiomatic verb-key route declaration (cosmetic: `methods:` already covers verbs natively) | optional `routes.verb` dispatch |

Context: until `Router::view()` / `Router::redirect()` are mapped, static content and simple redirects route through heavy seam controllers (`DeclaredView`, `DeclaredAction`) — the audit's "Native Route Shortcuts" `[ ]` row. Resource routes are prerequisites for `router.resourceParameters` / `router.singularResourceParameters` (§2.1).

**Resolution**: [declarative-route-registrars.md](declarative-route-registrars.md) — the `routes:` block is a map of native `Router` registration method names (`addRoute`, `group`, `resource`, `apiResource`, `singleton`, `apiSingleton`, `view`, `redirect`, `permanentRedirect`), dispatched dynamically with the post-call seam following the return type (`Route` → builders, `Pending*Registration` → options, group → nested `routes`). This corrects the proposed keys of the table above per the `Factory::composers()` precedent (§2.3): the batch forms (`resources()`, `apiResources()`, `singletons()`, `apiSingletons()`) are **not** given keys — one call per singular entry covers them per Rule 2 — and the verb dispatch keys are a decided non-goal (§2.4 note 1 of that document). On implementation, this §2.2 closes and §1 row 3 reclassifies `[/]` → `[x]`.

### 2.3 View Factory — `view:` (`src/View.php`, `Providers/ViewDeclarationServiceProvider.php`)

Current mapping: `addLocation`, `prependLocation`, `addNamespace`, `prependNamespace`, `replaceNamespace`, `addExtension`, `share`, `composer`, `creator`, plus the no-argument epilogue keys `flushFinderCache`/`flushState`. **Resolved**: the audit's signature-inversion claim is fixed — the manifest declares `composer: {views: callback}` and the provider dispatches `$Factory->composer($viewsList, $callback)` in the native argument order. The render-time factory methods below are **resolved** by [declarative-view-factory.md](declarative-view-factory.md): `first`/`make` were already dispatched by `setDefaults.view` (string → `Factory::make()`, list → `Factory::first()` through `ResponseFactory::view()`), and the full render-time surface (`file`, `renderEach`, `renderWhen`, `renderUnless`, plus every future `Factory` method) dispatches through one `setDefaults.factory` entry — `$Factory->{$method}(...$arguments)`, zero per-method code (§2 there). `flushFinderCache`/`flushState` are the `view:` epilogue booleans (§2.5 there). `exists()` stays reachable-only (`$__env->exists()` / `app('view')->exists()`); a declarative `factory.exists` fails loudly at render — a `bool` is not renderable.

Historical gap table (each row resolved above):

| Native method | Signature | Purpose | Proposed key / tier |
|---|---|---|---|
| `Factory::exists()` | `exists(string $view): bool` | View existence guard (query-time, used by templates and `View::exists()` helper) | reachable at query time (`$__env->exists()`); declarative guard is `first` |
| `Factory::first()` | `first(array $views, Arrayable|array $data = [], array $mergeData = []): View` | Render the first existing view from a fallback chain | `setDefaults.view` (list) / `setDefaults.factory.first` |
| `Factory::make()` | `make(Arrayable|array|string $view, array $data = [], array $mergeData = []): View` | Runtime view instance creation (render seam for `DeclaredView`) | `setDefaults.view` (string) / `setDefaults.factory.make` |
| `Factory::file()` | `file(string $path, array $data = [], array $mergeData = []): View` | Render an absolute template path (bypasses finder) | `setDefaults.factory.file` |
| `Factory::renderEach()` | `renderEach(string $view, array $data, string $iterator, string $empty = 'raw|')` | Render a view per collection item (`raw|`-prefixed `$empty` renders a raw string) | `setDefaults.factory.renderEach` |
| `Factory::flushFinderCache()` | `flushFinderCache(): void` | Clear `FileViewFinder` cache after declaring namespaces at runtime | `view.flushFinderCache: true` |
| `Factory::flushState()` | `flushState(): void` | Reset sections/loops/stacks shared state (test isolation) | `view.flushState: true` |

Context: the render-time calls are dispatched per request by `DeclaredView`'s `factory:` seam ([declarative-view-factory.md](declarative-view-factory.md) §1.1), not registered at boot; `flushFinderCache`/`flushState` run in the provider's epilogue after the block applies. `Factory::composers()` (batch `callback: views` form) is already covered by the per-entry `composer` map per Rule 2.

### 2.4 Form Request Declaration — `requests:` (`src/Request.php`, `src/DeclaredRequest.php`)

Current mapping: `rules`, `messages`, `attributes`, `authorize` (bool or `Class@method` reference resolved through `Container::call`), `validationData`, `prepareForValidation`, `passedValidation`, `withValidator`, `after`, `validator`, `failedValidation`, `failedAuthorization`, `redirect`, `redirectRoute`, `redirectAction`, `errorBag`, `stopOnFirstFailure`, `shouldFailOnUnknownFields`. **Resolved**: the invented `failOnUnknownFields` noun was replaced by native `shouldFailOnUnknownFields()`. **Resolved**: custom `Illuminate\Contracts\Validation\ValidationRule` objects are dispatchable — `DeclaredRequest::rule()` container-makes any rule class-string present in a rules array.

Missing:

| Native API | Signature | Purpose | Proposed key |
|---|---|---|---|
| `Illuminate\Validation\Factory::extend()` | `extend(string $rule, Closure|string $extension, ?string $message = null): void` | Register a custom implicit-less validation rule factory-wide | `validator.extend` |
| `Illuminate\Validation\Factory::extendImplicit()` | `extendImplicit(string $rule, Closure|string $extension, ?string $message = null): void` | Register a rule that runs even on empty fields | `validator.extendImplicit` |
| `Illuminate\Validation\Factory::extendDependent()` | `extendDependent(string $rule, Closure|string $extension, ?string $message = null): void` | Register a rule whose replacer depends on the field | `validator.extendDependent` |
| `Illuminate\Validation\Factory::replacer()` | `replacer(string $rule, Closure|string $replacer): void` | Register custom message placeholder replacement | `validator.replacer` |
| Conditional rules | `Illuminate\Validation\ConditionalRules` (`when`/`unless` closures) | Data-dependent rule arrays (`sometimes`, `excludeWhen`) — closures exceed the strings-only manifest contract; string refs resolve through `Container::call` | `requests.rules.*.when/unless` |

Context: the four `validator:` factory extensions are Tier 1 (`[ ]` row in the audit); they belong in a `ValidatorsDeclarationServiceProvider` executed before any `DeclaredRequest` validates. Without them, custom regex/semantic rules require PHP service providers.

**Resolution**: [declarative-validator.md](declarative-validator.md) — the `validator:` block maps the four `Factory` registry methods with references passed through untouched (Laravel's own `callClassBasedExtension`/`callClassBasedReplacer` dispatch), and conditional rules are entries keyed with the native `Rule::when()`/`Rule::unless()` method names resolved inside `DeclaredRequest::rule()`. This §2.4 closes and §1 row 6 reclassifies `[/]` → `[x]`.

### 2.5 Database Schema & Blueprint — `schema:` (`src/Schema.php`, `src/TableDefinition.php`, `src/ColumnDefinitionModel.php`, `Internal/Commands/MigrateCommand.php`)

Current mapping (verified): **all** `Blueprint` column type methods dispatch dynamically via `method_exists(Blueprint::class, $columnType)` — the audit's "hardcoded static whitelists" are resolved; column modifiers, foreign-key modifiers (`*OnDelete`, `*OnUpdate`, `references`, `on`, `deferrable`, `initiallyImmediate`), `constrained` variants (bool / string / list / `{table, column, indexName}` map), table options (`engine`, `charset`, `collation`, `temporary`, `comment` — plus `property_exists`/`method_exists` dispatch in `apply()`), and index declarations (`primary`, `unique`, `index`, `fullText`, `spatialIndex`, plus dynamic dispatch in the explicit `indexes:` form) are mapped. `MigrateCommand` executes idempotent creation guarded by `Schema::hasTable()`.

Missing native `Illuminate\Database\Schema\Builder` (Schema Facade) operations — **the `schema:` DataModel declares creation only**:

| Native method | Signature | Purpose | Proposed key |
|---|---|---|---|
| `Schema::table()` | `table(string $table, Closure $callback): void` | Alter an existing table (run `Blueprint` in alter mode) | `schema.alter.tables.<table>` |
| `Schema::rename()` | `rename(string $from, string $to): void` | Rename a table | `schema.rename` |
| `Schema::drop()` | `drop(string $table): void` | Drop a table | `schema.drop` |
| `Schema::dropIfExists()` | `dropIfExists(string $table): void` | Idempotent drop | `schema.dropIfExists` |

Missing native `Illuminate\Database\Schema\Blueprint` **alter verbs** (needed inside `schema.alter` blueprints):

| Native method | Signature | Purpose |
|---|---|---|
| `Blueprint::dropColumn()` | `dropColumn(string|array $columns): void` | Remove columns |
| `Blueprint::renameColumn()` | `renameColumn(string $from, string $to): void` | Rename a column |
| `Blueprint::dropPrimary()` / `dropUnique()` / `dropIndex()` / `dropFullText()` / `dropSpatialIndex()` / `dropVectorIndex()` | `drop*(string|array $index): void` | Drop indexes by name/columns |
| `Blueprint::dropForeign()` | `dropForeign(string|array $foreign): void` | Drop a foreign key constraint |
| `Blueprint::dropConstrainedForeignId()` | `dropConstrainedForeignId(string $column): void` | Drop a constrained `foreignId` column and its constraint |
| `Blueprint::dropForeignIdFor()` / `dropConstrainedForeignIdFor()` | `dropForeignIdFor(Model|string $model, ?string $column = null): void` | Drop FK by model reference |
| `Blueprint::renameIndex()` | `renameIndex(string $from, string $to): void` | Rename an index |
| `Blueprint::dropTimestamps()` / `dropTimestampsTz()` / `dropSoftDeletes()` / `dropSoftDeletesTz()` / `dropRememberToken()` / `dropMorphs()` | convenience droppers | Remove conventional column sets |
| `Blueprint::removeColumn()` | `removeColumn(string $column): void` | Remove a column from the blueprint state |
| `ColumnDefinition::change()` | `change(): void` (modifier) | Alter an existing column's definition (via `schema.alter`) |
| `Blueprint::addColumn()` / `rawColumn()` | `addColumn(string $type, string $name, array $parameters = [])` / `rawColumn(string $column, string $definition): ColumnDefinition` | Escape hatch for driver-specific column definitions |

Missing column-type surface (flat legacy form): the static index-key list in `TableDefinition::from()` (`['primary', 'unique', 'index', 'fullText', 'spatialIndex']`) omits **`vectorIndex`** and **`rawIndex`**; declared flat, they fall through to the column branch (`method_exists(Blueprint::class, $keyStr)`) and are mis-handled as columns (their `IndexDefinition` results are not `ColumnDefinition`, so modifiers are silently dropped). The explicit `indexes:` form dispatches them correctly. Fix: extend the flat-form index list (or replace it with `method_exists` + return-type dispatch).

**Defect (violates Rule 7)**: standalone `Blueprint::foreign()` columns. `Blueprint::foreign($columns, $name)` returns `ForeignKeyDefinition`, which **extends `Illuminate\Support\Fluent`, not `ColumnDefinition`** (verified in `vendor/.../Schema/ForeignKeyDefinition.php:17`). `ColumnDefinitionModel::apply()` only applies FK modifiers when `constrained !== null && $column instanceof ForeignIdColumnDefinition`, and applies remaining modifiers only `$column instanceof ColumnDefinition`. Therefore a declaration like `foreign: {user_id: {references: users, on: id, cascadeOnDelete: true}}` **silently discards every modifier** instead of failing. Fix: add a `ForeignKeyDefinition` modifier branch to `apply()` (or throw when FK modifiers are declared on a non-FK column).

### 2.6 Eloquent Model Configuration — `models:` (`src/Model.php`, `src/DeclaredModel.php`)

Current mapping (verified): `class`, `connection`, `table`, `primaryKey`, `keyType`, `incrementing`, `timestamps`, `dateFormat`, `attributes`, `casts`, `fillable`, `guarded`, `hidden`, `visible`, `appends`, `with`, `withCount`, `touches`, `refreshes`, `perPage`, `dispatchesEvents`, `observables`, `observe`, `addGlobalScope` (class-string list), `getRouteKeyName` — applied by `DeclaredModel::__construct()` property injection plus `resolveObserveAttributes()`, `resolveGlobalScopeAttributes()`, `isIgnoringTouch()` overrides.

**Reclassified as decided non-goals.** The completed `models:` specification ([declarative-model.md](declarative-model.md) §2.6, grounded in v13.33.0) keeps this behavior in the model class body, so the surface proposed below is **not missing** — it is gated out by decision. The Tier 1 `models:` surface is **complete**: every declared `Model` property (§1.2 of that doc) plus the three out-of-instance method keys (`observe`, `addGlobalScope`, `getRouteKeyName`) is mapped, and `DeclaredModel::__construct()` injects them before `bootIfNotBooted()`:

| Proposed key (withdrawn) | Native API | Resolution |
|---|---|---|
| ~~`models.relations.<name>`~~ | `hasOne()` / `hasMany()` / `belongsTo()` / `belongsToMany()` / morphs / through | A relation is a method in the class: `guessBelongsToRelation()` misnames Closure-defined relations (`{closure:…}` foreign keys, declarative-model.md §1.4.7), the pivot/`ofMany` chain syntax is a DSL, and relation methods carry phpstan generics (`BelongsTo<Airline, $this>`) |
| ~~`models.casts` method dispatch~~ | `protected function casts(): array` | Laravel merges `casts()` over `$casts` in `initializeHasAttributes()`, so the `casts` property key already composes with a class-body `casts()` method (verified in `DeclaredModelTest`: `['delayed' => 'boolean', 'options' => 'array', 'departed_at' => 'datetime']`) |
| ~~`models.attributes.<name>.Attribute`~~ | `Illuminate\Database\Eloquent\Casts\Attribute::make(get: ?Closure $get = null, set: ?Closure $set = null)` | Accessors/mutators are class methods; `appends`/`with` name them from the declaration |
| ~~`models.scopes.<name>`~~ | `scope<Naming>(Builder $query, ...): void` | Local scopes are class methods (`#[Scope]`); dynamic request arguments stay in them per [declarative-query.md](declarative-query.md) |
| ~~`models.booted`~~ | `booting()` / `booted(): void` | Static per-class hooks with Closure bodies; `observe` already covers event registration declaratively |
| ~~`models.prunable`~~ | `prunable(): Builder` + `Model::pruneAll()` | Requires the `Prunable`/`MassPrunable` trait in the class |

Custom cast **classes** remain usable as string cast values (`'completed' => App\Casts\Boolean::class` assigns through the `casts` property); the audit's sub-gap narrows to nothing further in Tier 1.

**Phase 2 consequence:** dynamic model synthesis (§2.7) inherits only the declared property/method keys — relational methods, accessors/mutators, local scopes, `casts()`, `booted()` and `prunable()` require PHP on disk. The roadmap Phase 2 "full feature parity" claim was scoped down accordingly.

### 2.7 Dynamic Model Synthesis — `DeclaredModel` (Tier 2, Phase 2 Active Scope)

Verified: no `spl_autoload_register` / `class_alias` / synthesis code exists in `src/` — `LaravelDeclarationProvider::register()` only binds `Manifest` and registers the declared providers. `DeclaredModel` is abstract; every model must exist as a PHP file on disk. **Partial Phase 2 delivery:** `src/DeclaredModel.php`, [declarative-model.md](declarative-model.md), `tests/Feature/DeclaredModelTest.php` (fixture `tests/Fixtures/manifest/models.yml`) are shipped; the autoloader hook is not.

Missing:

1. **Runtime class synthesis autoloader** — when `Manifest::$models` contains a class-string absent from disk, synthesize a subclass of `DeclaredModel` (Phase 2 deliverable in the roadmap; hook point `src/LaravelDeclarationProvider::register()`).
2. **Scope of synthesis** — with relations/scopes/accessors reclassified as non-goals (§2.6), synthesized classes can carry the declared property/method keys only (`table`, `fillable`, `casts`, `timestamps`, route binding, `DeclaredQuery` execution); relational behavior requires a hand-written class body.
3. **`connection`/`table` collision guards** — synthesized class names must not collide with disk classes; fail with `LogicException` per Rule 7 if a manifest class-string matches an existing file with a conflicting parent.

### 2.8 Pagination Engine — `pagination:` (`src/Pagination.php`, `Providers/PaginationDeclarationServiceProvider.php`)

Current mapping: `defaultView`, `defaultSimpleView`, `useTailwind`, `useBootstrapFive`. The roadmap §3.1 claim of "Tailwind, Bootstrap 4/5" was overstated — **Bootstrap 4 is not mapped**. Missing native `Illuminate\Pagination\AbstractPaginator` static styling methods (v13.33.0, verified at `AbstractPaginator.php:628-667`):

| Native method | Signature | Purpose | Proposed key |
|---|---|---|---|
| `AbstractPaginator::useBootstrapThree()` | `useBootstrapThree(): void` | Bootstrap 3 pagination views (`pagination::bootstrap-3`) | `pagination.useBootstrapThree` |
| `AbstractPaginator::useBootstrapFour()` | `useBootstrapFour(): void` | Bootstrap 4 pagination views (`pagination::bootstrap-4`) | `pagination.useBootstrapFour` |
| `AbstractPaginator::useBootstrap()` | `useBootstrap(): void` — alias delegating to `useBootstrapFour()` | Unversioned Bootstrap styling preset | `pagination.useBootstrap` |

Context: each preset sets `defaultView`/`defaultSimpleView` in one call. These are the only unmapped members of the `Paginator` styling family; the remaining statics (`resolveCurrentPath`, `currentPageResolver`, `queryStringResolver`, …) are runtime plumbing a manifest should not declare.

---

## 3. Corrections to [declarative-framework-api-mapping.md](declarative-framework-api-mapping.md)

For the record, these audit rows are stale relative to current source and should be re-classified:

1. **Service Container** — `[/]` → `[x]`: `tag`, `when`/`needs`/`give` (contextual bindings), `resolving`, `afterResolving`, `useBootstrapPath`, `useConfigPath`, `useEnvironmentPath` are declared on `src/App.php` and executed by `Providers/AppDeclarationServiceProvider.php`.
2. **Route Registration** — `[/]` → resolved: native `uri` noun replaced `path`; the 16 static `#[Builder]` properties were replaced by dynamic `builders` dispatch; `whereAlpha`/`whereNumber`/`whereIn`/`secure`/`httpOnly`/`bindingFields` are reachable; the Router-level creation surfaces (groups, resource registrars, native shortcuts) are shipped via the `routes:` registrar map ([declarative-route-registrars.md](declarative-route-registrars.md)).
3. **View Factory** — `[/]` → `[x]`: the `composer`/`creator` manifest signature matches the native `composer($views, $callback)` order, the render-time factory methods dispatch through `DeclaredView`'s `setDefaults.factory` ([declarative-view-factory.md](declarative-view-factory.md) §2), and `flushFinderCache`/`flushState` are provider epilogue keys (§2.5 there).
4. **View Dispatch Controller** — `[/]` → resolved: `DeclaredView` dispatches inline templates via `Blade::render()` (`deleteCachedView` honored), bridges composer lifecycle via the `composing: {routeName}` event, and returns through `ResponseFactory::make()`. Inline templates intentionally have no view identity, so named-view composers do not fire — by design, documented in [declarative-inline-template.md](declarative-inline-template.md).
5. **Form Request Declaration** — `[/]` → `[x]`: `shouldFailOnUnknownFields` is native-named; rule class-strings are container-resolved; the `validator:` Tier 1 factory and conditional rules are shipped ([declarative-validator.md](declarative-validator.md)).
6. **Form Request Seam** — `[/]` narrows: `authorize()` resolves string references through `Container::call` (policy checks are expressible as `Class@method` refs); the native `Gate::policy()` binding remains unmapped under the missing `gate:` Tier 1 row.
7. **Eloquent Query Builder** — `[!]` row largely superseded: the 11 synthetic attribute classes were eliminated; `src/Query.php` dispatches clauses dynamically with native exceptions (no silent `continue;`).
8. **Eloquent Model Configuration** — `[/]` → `[x]`: the completed `models:` specification ([declarative-model.md](declarative-model.md)) maps every declared `Model` property plus `observe`/`addGlobalScope`/`getRouteKeyName`; the previously proposed relations / `casts()`-method / scopes / hooks surface is reclassified as decided non-goals (§2.6).
9. **Pagination Engine** — `[ ]` row narrows to `[/]`: `defaultView`/`defaultSimpleView`/`useTailwind`/`useBootstrapFive` are mapped (`src/Pagination.php`); Bootstrap 3/4 and the `useBootstrap()` alias remain (§2.8).

---

## 4. Remediation Ordering

Ordered to close Rule 7 violations first, then unblock Phase 2:

1. **`schema:` ForeignKeyDefinition modifier branch + flat-form index list** (§2.5 defect) — silent modifier discard violates Rule 7.
2. **`schema:` table operations** (`alter`, `rename`, `drop`, `dropIfExists`) — completes the Stage 6 lifecycle.
3. ~~**`router:` batch/group/alias methods** (§2.1) and **`routes:` groups/resources/shortcuts** (§2.2)~~ — **done**: the `router:` attribute-selected dispatch ([declarative-router-configuration.md](declarative-router-configuration.md)) and the `routes:` registrar map ([declarative-route-registrars.md](declarative-route-registrars.md)) ship `router.resourceParameters`/`router.singularResourceParameters` prerequisites and end seam-controller routing for static content.
4. ~~**`models.relations`** (§2.6)~~ — **dropped**: relations stay PHP in the model class per [declarative-model.md](declarative-model.md) §2.6; Phase 2 synthesis scope narrows to the declared keys (§2.7).
5. ~~**`validator:` factory extensions** (§2.4)~~ — **done**: the `validator:` Tier 1 block and `requests.rules` conditional rules ship ([declarative-validator.md](declarative-validator.md)); closes the last `requests:` gap.
6. ~~**`view:` render-time factory methods** (§2.3) via `DeclaredView` `setDefaults` (`first`, `file`, `renderEach`)~~ — **done**: one dynamic dispatch, `setDefaults.factory` ([declarative-view-factory.md](declarative-view-factory.md)); `first`/`make` were already reachable through `setDefaults.view`.
7. **`pagination:` Bootstrap presets** (§2.8) — `useBootstrapThree`/`useBootstrapFour`/`useBootstrap` close the styling gap left by the roadmap's Bootstrap 4/5 claim.
---

## 5. Line-by-Line Verification Pass Against the Roadmap

Each claim in [declarative-request-to-view-roadmap.md](declarative-request-to-view-roadmap.md) was re-verified against `src/` and vendor v13.33.0. Results:

**Roadmap claims confirmed (no changes required to the roadmap):**

- Stages 1–18 (`app`, `config`, `providers`, `kernel`, `db`, `schema`, `router.pattern`, `router.model`/`router.bind`, `routes`, `requests`/`metadata.request`, `queries`, `view`, `blade`, `pagination`, `responses`, `DeclaredView`, `setDefaults.template`, `models`) — every mapped key exists in the corresponding `src/*.php` DataModel and provider (`Manifest.php` declares all ten subsystem properties).
- §1.1: `ImplicitRouteBinding::resolveForRoute()` uses `$route->signatureParameters(['subClass' => UrlRoutable::class])` (`vendor/.../Routing/ImplicitRouteBinding.php:29`); `Router::substituteBindings()` resolves through `$this->binders[$key]` via `performBinding()`, populated by `Router::bind($key, $binder)` / `Router::model($key, $class, ?Closure $callback = null)` (`Router.php:1170,1185`).
- §1.2: `DeclaredView` verified — defaults/`setDefaults` merge with route-parameter precedence (`array_merge($resolvedData, $routeParameters)`), `DeclaredQuery::run()` for query handles, `app()->call()` for `Class@method` callables, `Blade::render()` with `deleteCachedView`, `ResponseFactory::make($content, $status, $headers)`, and `ViewController::__invoke()` delegation for `setDefaults.view` (`src/DeclaredView.php`).
- §1.4: `MigrateCommand` guards creation with `Schema::hasTable()` and creates via `SchemaBuilder::create()` (`src/Internal/Commands/MigrateCommand.php`).
- Phase 1 status: `docs/declarative-action.md` exists; no `src/DeclaredAction.php`, no `tests/Fixtures/manifest/action.yml`, no `tests/Feature/DeclaredActionTest.php` — "spec-only" is accurate. `RedirectResponse::withInput(?array $input = null)` and `withErrors($provider, $key = 'default')` exist as mapped (`Http/RedirectResponse.php:60,117`).
- Phase 2 status: no `spl_autoload_register` / `class_alias` / dynamic synthesis anywhere in `src/`; `LaravelDeclarationProvider::register()` only binds `Manifest` and registers declared providers. `src/DeclaredModel.php`, `docs/declarative-model.md`, `tests/Feature/DeclaredModelTest.php`, `tests/Fixtures/manifest/models.yml` shipped as claimed.
- Phase 3 status: `tests/Fixtures/manifest/end-to-end.yml` + `tests/Feature/EndToEndRequestToViewTest.php` exist (read side); no `todo-app.yml` / `TodoAppIntegrationTest.php` (write side pending).
- Phase 4 status: no `src/DeclaredJson.php` — future scope accurate.
- §2.6 model key list matches `src/Model.php` (24 keys incl. `refreshes`, which exists on `Illuminate\Database\Eloquent\Model` in v13.33.0) and `src/DeclaredModel.php` (`__construct()` injection, `resolveObserveAttributes()`, `resolveGlobalScopeAttributes()`, `isIgnoringTouch()`, `getRouteKeyName()`).

**Signature corrections applied to this inventory during the pass (vendor v13.33.0):**

1. §2.1 — `Router::matched()` is `matched($callback)` (single `string|callable`, listens on `RouteMatched`), not the `(string|array $events, Closure|string $callback)` form previously listed; `middlewareGroup()`/`aliasMiddleware()` return `$this`; `pushMiddlewareToGroup($group, $middleware)` has **no** `$prepend` parameter and the group-mutation trio returns `$this` (not `array`); `resourceVerbs()` is a getter/setter hybrid returning `array|null` (`Router.php:1021,1043,1078,1094,1112,1132,1407`).
2. §2.2 — every resource/singleton registrar takes `array $options = []` (`resource`, `resources`, `apiResource`, `apiResources`, `singleton`, `apiSingleton`, `Router.php:318-452`); the previously omitted batch forms `Router::singletons()` and `Router::apiSingletons()` were added to the table; `Router::view()` carries `$status` (int, or array-as-headers) and `$headers` and internally dispatches `ViewController` with `setDefaults` (`Router.php:287-297`).
3. §2.3 — `Factory::first()` `$data` is `Arrayable|array` and returns `View` (`Factory.php:148`); `Factory::renderEach()` default is `$empty = 'raw|'`, not `'raw|view|callable'` (`Factory.php:228`); `Factory::composers(array $composers)` confirmed present in `Concerns/ManagesEvents.php:35` (`callback: views` form).
4. §2.5 — `Blueprint::rawColumn(string $column, string $definition)` takes two arguments, not a single SQL string (`Blueprint.php:1757`); all listed alter verbs, `Schema::table/rename/drop/dropIfExists`, the flat-form `vectorIndex`/`rawIndex` omission, and the `ForeignKeyDefinition extends Fluent` modifier-discard defect were re-confirmed unchanged.
5. §2.4 and §2.8 — verified unchanged: `Validation\Factory::extend/extendImplicit/extendDependent/replacer` signatures match (`Validation/Factory.php:195-245`, `ConditionalRules` exists), and `AbstractPaginator::useBootstrapThree/useBootstrapFour/useBootstrapFive/useBootstrap` (alias → `useBootstrapFour`) match (`AbstractPaginator.php:628-667`). §2.4 has since **closed** ([declarative-validator.md](declarative-validator.md)).
6. **Coverage regression (current `composer check` state)** — the roadmap §3.1 "100% test coverage" claim is stale: `composer check` fails with 99.7% total coverage. Uncovered lines are the guard early-returns `ProvidersDeclarationServiceProvider.php:16` (no `Manifest` bound) and `RoutesDeclarationServiceProvider.php:21` (no `Manifest`/`Router` bound) — both reached only when the `providers:`/`routes:` subsystems boot without a bound `Manifest`. The failure reproduces on the clean tree (docs-only changes excluded via `git stash`), so it is a test regression, not a doc artifact. Remediation: a feature test that boots the providers without a bound `Manifest` to re-cover both guard lines and restore the Definition-of-Done §6 100% gate.
