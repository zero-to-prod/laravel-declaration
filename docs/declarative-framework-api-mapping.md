# Declarative Framework API Mapping & Gap Analysis — Architecture Audit, Tier Separation & Inline Template Roadmap

Source of truth: `vendor/laravel/framework/src/Illuminate` (`laravel/framework` v13.33.0), official Laravel documentation repository (`docs/repos/laravel/docs/*.md`), and package specifications (`docs/declarative-*.md`).

This document provides an exhaustive architectural audit of the Laravel framework API against the declaration engine (`laravel-declaration`). It formalizes the strict boundary between the **Laravel API Map (Tier 1)** and **Declarative Seams / Glue Code (Tier 2)**, identifies custom implementation and glue code shortcuts caused by missing framework mappings, catalogs framework subsystems across 12 architectural domains, and defines the authoritative progression toward `docs/declarative-inline-template.md`.

---

## Architectural Axiom: Framework API Mapping First, Glue/Composition Second

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                        TIER 1: PURE LARAVEL FRAMEWORK API MAP                          │
│  - 1:1 declarative projection of native Laravel classes, contracts, and registries.    │
│  - Rule 1: Key = method name (or property) on the target class. No invented verbs.    │
│  - Rule 2: Pass references and scalar arguments through untouched.                     │
│  - Rule 3: Fail where Laravel fails (no custom DSL, native exception behavior).        │
│  - Rule 4: Stateless and cache-safe (fully compatible with route:cache & config:cache).│
│  - Rule 5: Zero cross-subsystem orchestration; pure registration and configuration.    │
└───────────────────────────────────────────┬────────────────────────────────────────────┘
                                            │ feeds into
                                            ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                     TIER 2: DECLARATIVE SEAMS & GLUE CODE                              │
│  - Thin integration controllers and orchestrators connecting runtime workflows.        │
│  - Wires inbound HTTP requests, route parameters, input validation, queries,           │
│    database writes, domain events, and outgoing view/response rendering.               │
│  - Rule 6: Seams ONLY orchestrate mapped Tier 1 APIs; they NEVER invent or substitute   │
│            for unmapped framework capabilities.                                        │
│  - Rule 7: Strictly one seam class per Laravel foundation base class                   │
│            (DeclaredView extends ViewController, DeclaredAction extends Controller,    │
│             DeclaredRequest extends FormRequest, DeclaredModel extends Model).         │
│  - Rule 8: Zero bespoke attribute DSLs in glue code. If a capability exists in         │
│            Laravel, map it in Tier 1 rather than inventing synthetic attributes.        │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

**Architectural Principle**: *Do not solve gaps with additional glue when a clean declarative representation of an existing Laravel concept would simplify or eliminate that glue. Every framework capability must be modeled in Tier 1 before Tier 2 glue code orchestrates it.*

---

## Framework API Implementation Checklist Inventory

Status designations:
- `[x] Implemented (Tier 1 API Map)`: Fully mapped via DataModel, manifest key, service provider loop, and feature tests in package source (`src/`).
- `[/] Partially Mapped (Tier 1 API Map)`: Represented in manifest/source, but missing critical native methods, column types, or options.
- `[!] Indirectly Mapped / Glue-Substituted (Tier 2 Seam Shortcut)`: Implemented via custom procedural glue or synthetic attribute classes rather than a pure Tier 1 declarative mapping.
- `[ ] Missing / Left To Do (Tier 1 API Map)`: Native Laravel class/registry requiring direct 1:1 declarative mapping.

### Domain 1: Core Architecture, Container & Configuration
- [/] **Service Container** (`Illuminate\Container\Container`, `Illuminate\Foundation\Application`) — `app:` (missing `tag`, `contextual`, `resolving`, `afterResolving`, and paths: `useBootstrapPath`, `useConfigPath`, `useEnvironmentPath`)
- [x] **Configuration Repository** (`Illuminate\Config\Repository`) — `config:`
- [!] **Service Providers** (`Illuminate\Support\ServiceProvider`) — `providers:` (contains dead declarative keys `bindings`, `singletons`, `factories`, `name`, `description` ignored by provider registration)

### Domain 2: HTTP Kernel & Middleware Pipeline
- [x] **HTTP Kernel & Middleware Pipeline** (`Illuminate\Foundation\Http\Kernel`, `Illuminate\Routing\Pipeline`) — `kernel:`
- [ ] **CSRF Verification & Route Exclusions** (`Illuminate\Foundation\Http\Middleware\ValidateCsrfToken`) — `csrf:`
- [ ] **HTTP Precognition** (`Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests`) — `precognition:`

### Domain 3: HTTP Routing, Pipeline, URLs & Throttling
- [x] **Router Configuration & Binders** (`Illuminate\Routing\Router`) — `router:` (Mapped: `pattern`, `model`, `bind`, `middlewareGroup`, `aliasMiddleware`, `pushMiddlewareToGroup`, `prependMiddlewareToGroup`, `removeMiddlewareFromGroup`, `singularResourceParameters`, `resourceParameters`, `resourceVerbs`, `matched` (`patterns` a decided non-goal))
- [/] **Route Registration** (`Illuminate\Routing\Router`, `Illuminate\Routing\Route`) — `routes:` (uses invented noun `path` instead of `uri`, 16 static `#[Builder]` properties instead of dynamic dispatch, missing route groups, HTTP verb dynamic dispatch, resource routes, fallbacks, and constraint helpers: `whereAlpha`, `whereNumber`, `whereIn`, `secure`)
- [ ] **Native Route Shortcuts (`view` / `redirect`)** (`Illuminate\Routing\Router::view()`, `Router::redirect()`) — `routes.view`, `routes.redirect`
- [ ] **URL Generation & Signed URLs** (`Illuminate\Routing\UrlGenerator`) — `url:`
- [ ] **Rate Limiter** (`Illuminate\Cache\RateLimiter`) — `rate_limiter:`

### Domain 4: View Layer, Blade Engine & Presentation
- [/] **View Factory & Namespaces** (`Illuminate\View\Factory`, `Illuminate\View\FileViewFinder`) — `view:` (inverts native `composer`/`creator` argument order `$views, $callback` into `$callback => $views`; missing `exists`, `file`, `make`, `renderEach`, `flushFinderCache`)
- [ ] **Blade Compiler & Directives** (`Illuminate\View\Compilers\BladeCompiler`) — `blade:`
- [ ] **Anonymous Components & Namespaces** (`Illuminate\View\Compilers\BladeCompiler`, `Illuminate\View\Component`) — `blade.components:`
- [ ] **Pagination View Resolvers & Styling** (`Illuminate\Pagination\Paginator`, `Illuminate\Pagination\LengthAwarePaginator`) — `pagination:`
- [/] **View Dispatch Controller** (`Illuminate\Routing\ViewController`) — `DeclaredView` (lacks inline template dynamic dispatch and view composer event bridging)

### Domain 5: Request Lifecycle, Input Resolution & Validation
- [/] **Form Request Declaration** (`Illuminate\Foundation\Http\FormRequest`) — `requests:` (uses invented property `failOnUnknownFields` instead of native `shouldFailOnUnknownFields()`; lacks dynamic validation rule factory dispatch)
- [ ] **Validation Factory & Custom Rules** (`Illuminate\Validation\Factory`, `Illuminate\Contracts\Validation\ValidationRule`) — `validator:`
- [/] **Form Request Seam** (`Illuminate\Foundation\Http\FormRequest`) — `DeclaredRequest` (lacks policy-based authorization hooks)

### Domain 6: Response Generation, Redirects & Transport
- [ ] **Response Factory & Macros** (`Illuminate\Contracts\Routing\ResponseFactory`, `Illuminate\Routing\ResponseFactory`) — `responses:`
- [ ] **Redirector & Redirect Responses** (`Illuminate\Routing\Redirector`, `Illuminate\Http\RedirectResponse`) — `redirect:`
- [ ] **Cookies & Cookie Jar** (`Illuminate\Cookie\CookieJar`) — `cookie:`
- [ ] **API Resources & JSON Serialization** (`Illuminate\Http\Resources\Json\JsonResource`) — `resources:`
- [ ] **Action Controller Seam** (`Illuminate\Routing\Controller`) — `DeclaredAction` (Phase 7 Tier 2 Seam; premature commit 28555ec reverted)

### Domain 7: Database Connection, Query Builder, Transactions & Seeding
- [ ] **Database Connection & Transactions** (`Illuminate\Database\DatabaseManager`, `Illuminate\Database\Connection`) — `db:`
- [ ] **Database Query Builder (Table-Level Queries)** (`Illuminate\Database\Query\Builder`) — `db_queries:` / `queries.table`
- [/] **Database Schema & Blueprint** (`Illuminate\Database\Schema\Builder`, `Illuminate\Database\Schema\Blueprint`) — `schema:` (table options, indexes, and FK constraints mapped; missing table alters, renames, drops, and dynamic dispatch over hardcoded option/modifier whitelists)
- [ ] **Database Seeding & Factories** (`Illuminate\Database\Seeder`, `Illuminate\Database\Eloquent\Factories\Factory`) — `seeds:`

### Domain 8: Eloquent ORM & Query Builder
- [/] **Eloquent Model Configuration & Lifecycle** (`Illuminate\Database\Eloquent\Model`) — `models:` (`dispatchesEvents`, connection, keyType, dateFormat, attributes, with, withCount, touches, refreshes, perPage, observables mapped; missing relations, modern `casts()` array method, local/global query scopes, accessors/mutators, and lifecycle hooks)
- [ ] **Eloquent Relationships** (`Illuminate\Database\Eloquent\Relations\*`) — `models.relations:`
- [ ] **Eloquent Attribute Casts & Mutators** (`Illuminate\Database\Eloquent\Casts\Attribute`, `Illuminate\Contracts\Database\Eloquent\CastsAttributes`) — `models.casts:`
- [!] **Eloquent Query Builder (Model Queries)** (`Illuminate\Database\Eloquent\Builder`) — `queries:` (deviates from dynamic dispatch via 11 synthetic attribute classes; silently drops unmapped methods with `continue;` violating Rule 3; invents noun `from` hijacking native `Builder::from($table)`; couples to HTTP route context in `BelongsTo` violating Rule 5)
- [/] **Dynamic Model Synthesis** (`Illuminate\Database\Eloquent\Model`) — `DeclaredModel` (synthesizes isolated non-relational models)
- [ ] **Full-Text Search (Scout)** (`Laravel\Scout\Searchable`) — `scout:`

### Domain 9: Security, Identity & Access Control
- [ ] **Authorization Gates & Policies** (`Illuminate\Contracts\Auth\Access\Gate`, `Illuminate\Auth\Access\Gate`) — `gate:`
- [ ] **Authentication Manager & Guards** (`Illuminate\Auth\AuthManager`) — `auth:`
- [!] **Session Store & Flash Data** (`Illuminate\Session\SessionManager`, `Illuminate\Session\Store`) — `session:` (substituted by `FlashAction` glue)
- [ ] **Hashing & Encryption** (`Illuminate\Hashing\HashManager`, `Illuminate\Encryption\Encrypter`) — `hashing:`, `encryption:`
- [ ] **API Token Authentication (Sanctum)** (`Laravel\Sanctum\HasApiTokens`, `Laravel\Sanctum\Sanctum`) — `sanctum:`

### Domain 10: Events, Async & Realtime Systems
- [ ] **Events & Dispatcher** (`Illuminate\Events\Dispatcher`) — `events:`
- [ ] **Queues, Workers & Bus** (`Illuminate\Queue\QueueManager`, `Illuminate\Bus\Dispatcher`) — `queues:`, `bus:`
- [ ] **Mail & Mailables** (`Illuminate\Mail\MailManager`) — `mail:`
- [ ] **Notifications & Channels** (`Illuminate\Notifications\ChannelManager`) — `notifications:`
- [ ] **Broadcasting & WebSockets** (`Illuminate\Broadcasting\BroadcastManager`) — `broadcasting:`

### Domain 11: Operations, Console, Storage & Systems
- [ ] **Artisan Console Commands** (`Illuminate\Console\Application`) — `commands:`
- [ ] **Task Scheduling** (`Illuminate\Console\Scheduling\Schedule`) — `schedule:`
- [ ] **Cache Repository & Stores** (`Illuminate\Cache\CacheManager`, `Illuminate\Contracts\Cache\Repository`) — `cache:`
- [ ] **Filesystem & Storage Disks** (`Illuminate\Filesystem\FilesystemManager`) — `storage:`
- [ ] **Localization & Translation Loader** (`Illuminate\Translation\Translator`) — `lang:`
- [ ] **Logging & Context Repository** (`Illuminate\Log\LogManager`, `Illuminate\Log\Context\Repository`) — `logging:`, `context:`
- [ ] **Application Telemetry & Monitoring (Pulse)** (`Laravel\Pulse\Pulse`) — `pulse:`

### Domain 12: Processes, Concurrency & Extensibility
- [ ] **Processes & Concurrency** (`Illuminate\Process\Factory`, `Illuminate\Concurrency\ConcurrencyManager`) — `process:`, `concurrency:`
- [ ] **HTTP Client Factory** (`Illuminate\Http\Client\Factory`) — `http:`
- [ ] **Exception Handling & Reporting** (`Illuminate\Contracts\Debug\ExceptionHandler`) — `exceptions:`
- [ ] **Feature Flags** (`Laravel\Pennant\FeatureManager`) — `features:`

---

## 1. Deep Architectural Audit: Custom Glue Substituted for Missing Mappings

When a native Laravel subsystem is omitted from **Tier 1 (API Map)**, downstream features face an architectural void. Bypassing this void with custom procedural logic or synthetic attribute classes creates the **Composite Seam Shortcut** anti-pattern:

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                                 THE SHORTCUT CYCLE                                     │
│                                                                                        │
│   Missing Tier 1 Mapping ───► Downstream Feature Void ───► Synthetic Glue / Attribute  │
│   (e.g. Redirector,          (e.g. Action Redirect,        (e.g. RedirectAction,       │
│    BladeCompiler)             Inline Template)              RenderAction)              │
│                                                                    │                   │
│                                                                    ▼                   │
│   Fragmented Architecture ◄── Architectural Debt ◄── Bespoke DSL & Severed Pipelines   │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

### 1.1 The `RenderAction` Attribute Shortcut (`docs/declarative-inline-template.md`)
- **Glue Artifact**: Proposed `ZeroToProd\LaravelDeclaration\Attributes\RenderAction` class in `src/Attributes/RenderAction.php` with `RENDERERS = ['template', 'view']`.
- **Underlying Missing Mappings**:
  1. `Illuminate\View\Compilers\BladeCompiler` (`blade:`)
  2. `Illuminate\Contracts\Routing\ResponseFactory` (`responses:`)
  3. `Illuminate\Pagination\Paginator` (`pagination:`)
- **Architectural Defects**:
  - `RenderAction` bypasses the compiler registry and calls `Blade::render()` directly, rendering an anonymous component in isolation.
  - **View Composers Severed**: View composers registered via `view.composer` in Phase 2 never fire because `Blade::render()` has no named view identity in `ViewFactory`.
  - **Component Namespaces Missing**: `<x-layout>` or `<x-card>` components cannot resolve because `BladeCompiler::anonymousComponentPath()` is not mapped in Tier 1.
  - **Directives Missing**: Custom directives (`@datetime`, `@money`) cannot be registered declaratively because `BladeCompiler::directive()` is not mapped.
  - **Invented DSL in Glue**: Invented a synthetic attribute class instead of using Laravel's standard `ResponseFactory::make()` and `BladeCompiler::render()` contracts.
- **Pure Tier 1 Elimination**: Map `blade:`, `responses:`, and `pagination:` in Tier 1. Refactor `DeclaredView` to dispatch inline templates natively via `BladeCompiler::render()` and return responses via `ResponseFactory::make()`. Eliminate `RenderAction` entirely.

### 1.2 The `RedirectAction` Attribute Shortcut (`src/Attributes/RedirectAction.php`)
- **Glue Artifact**: `ZeroToProd\LaravelDeclaration\Attributes\RedirectAction` with hardcoded `GENERATORS = ['route', 'to', 'back', 'away', 'action', 'redirect']`.
- **Underlying Missing Mapping**: `Illuminate\Routing\Redirector` (`redirect:`).
- **Architectural Defects**:
  - Re-implements parameter substitution and fallback routing procedurally.
  - Handles status codes (302) and headers manually rather than delegating to native `Redirector` and `RedirectResponse`.
- **Pure Tier 1 Elimination**: Map `redirect:` to `Redirector` in Tier 1. `DeclaredAction` delegates directly to `app('redirect')` using mapped method calls.

### 1.3 The `FlashAction` Attribute Shortcut (`src/Attributes/FlashAction.php`)
- **Glue Artifact**: `ZeroToProd\LaravelDeclaration\Attributes\FlashAction` with `MODIFIERS = ['with', 'withInput', 'withErrors', 'headers', 'withFragment']`.
- **Underlying Missing Mappings**: `Illuminate\Http\RedirectResponse` chaining and `Illuminate\Session\Store` (`session:`).
- **Architectural Defects**:
  - Chains session flashing, input flashing, and error bag population manually via an ad-hoc loop in a custom attribute.
- **Pure Tier 1 Elimination**: Model `RedirectResponse` chaining options natively under the Tier 1 redirect declaration.

### 1.4 The `Mutation` Attribute Shortcut & Atomicity Void (`src/Attributes/Mutation.php`, `src/DeclaredAction.php`)
- **Glue Artifact**: `ZeroToProd\LaravelDeclaration\Attributes\Mutation` with bespoke branching for `'toggle'`, `'update'`, `'touch'`, `'create'`.
- **Underlying Missing Mappings**: `Illuminate\Database\DatabaseManager` (`db:` transactions) and native `Model` persistence methods.
- **Architectural Defects**:
  - **Zero Atomicity**: Mutations execute without transaction boundaries (`DB::transaction()`). If post-write redirect generation, session flashing, or event dispatching fails, dirty state persists.
  - **Invented Verb**: Eloquent has no native `toggle()` method. Writing `$target->{$col} = ! (bool) $target->{$col}; $target->save();` inside an attribute class violates Rule 1 (**Key = method name on target class**).
- **Pure Tier 1 Elimination**: Map `db.transaction` in Tier 1. Confine declarative mutations strictly to native Eloquent methods (`create`, `update`, `delete`, `save`, `touch`). Non-framework verbs belong in dedicated model methods or invokable reference callables.

### 1.5 The Native Route View / Redirect Bypass (`src/Route.php`)
- **Glue Artifact**: Forcing static views and simple redirects to route to seam controllers (`DeclaredView`, `DeclaredAction`).
- **Underlying Missing Mapping**: `Illuminate\Routing\Router::view()` and `Illuminate\Routing\Router::redirect()`.
- **Architectural Defects**:
  - High controller dispatch overhead for static content.
  - Ignores Laravel's native, optimized route handling and caching.
- **Pure Tier 1 Elimination**: Add top-level `view` and `redirect` route definitions under `routes:` in Tier 1 that invoke `Router::view()` and `Router::redirect()` natively when no dynamic queries or mutations are declared.

### 1.6 The Relational Eloquent Void (`src/Model.php`, `src/DeclaredModel.php`)
- **Glue Artifact**: Synthesizing isolated runtime model classes via `DeclaredModel` without relational capability.
- **Underlying Missing Mapping**: `Illuminate\Database\Eloquent\Relations\*` (`models.relations:`).
- **Architectural Defects**:
  - Dynamic models cannot define `belongsTo`, `hasMany`, or `belongsToMany`.
  - Violates the self-contained promise of zero-file model declaration.
- **Pure Tier 1 Elimination**: Map `models.relations:` to Eloquent relation definitions in Tier 1, allowing `DeclaredModel` to synthesize relational methods dynamically.

### 1.7 The Authorization Gate Void (`src/Request.php`, `src/DeclaredRequest.php`)
- **Glue Artifact**: Hand-coded boolean flags or external PHP classes for request authorization.
- **Underlying Missing Mapping**: `Illuminate\Contracts\Auth\Access\Gate` (`gate:`).
- **Architectural Defects**:
  - `src/Route.php` provides a `can` builder, but abilities and policies cannot be declared in YAML without a `gate:` mapping.
- **Pure Tier 1 Elimination**: Map `gate:` directly to `Gate::define()` and `Gate::policy()` in Tier 1.

### 1.8 The Pagination presentation Void (`src/Query.php`, `src/DeclaredView.php`)
- **Glue Artifact**: Requiring custom HTML pagination controls in inline templates.
- **Underlying Missing Mapping**: `Illuminate\Pagination\Paginator` (`pagination:`).
- **Architectural Defects**:
  - Views rendering `$todos->links()` cannot declaratively select `useTailwind()`, `useBootstrapFive()`, or `defaultView()`.
- **Pure Tier 1 Elimination**: Map `pagination:` in Tier 1 to configure pagination views and CSS presets during provider boot.

### 1.9 The Eloquent Query Dynamic Dispatch Void & Synthetic Attribute Taxonomy (`src/Query.php`, `src/DeclaredQuery.php`)
- **Glue Artifact**: `src/Query.php` and 11 synthetic attribute classes: `ZeroToProd\LaravelDeclaration\Attributes\Clause`, `Where`, `Spread`, `Flag`, `Count`, `Exists`, `Fetch`, `Find`, `Paginate`, `Terminal`, `BelongsTo`.
- **Underlying Missing Mapping**: Dynamic dispatch on `Illuminate\Database\Eloquent\Builder`.
- **Architectural Defects**:
  - **No Dynamic Dispatch**: Instead of forwarding method invocations directly to Eloquent's `Builder` instance (`$builder->{$method}(...$arguments)`), `Query.php` enumerates every single method as a hardcoded typed property with a reflection-inspected attribute class.
  - **Silent Failure (Violates Rule 3: Fail where Laravel fails)**: In `Query::run()`, the loop explicitly ignores any unmapped methods:
    `if (! isset(self::clauses()[$method])) { continue; }`
    `if (! isset(self::terminals()[$method])) { continue; }`
    If a user declares a macro, a dynamic local scope, or an unlisted Eloquent method, it fails silently rather than throwing a native `BadMethodCallException`.
  - **Invented Noun (`from`) Hijacks Native Eloquent Method**: In `src/Query.php`, `from` is a required string property representing either an Eloquent model class (`App\Models\Post`) or a dot-notated route relation (`user.posts`). `Query::extractClauses()` explicitly strips `from` from query execution. This completely prevents calling Laravel's native Query Builder method `from($table, $as = null)` for table aliasing or subquery roots.
  - **Cross-Subsystem Context Bleed (Violates Rule 5: Zero cross-subsystem orchestration)**: In `src/Attributes/BelongsTo.php`, the attribute method `apply()` reaches directly into route parameters (`$parameters[$param]`), coupling Tier 1 query definition to HTTP route parameter resolution instead of keeping Tier 1 pure and delegating contextual argument binding to Tier 2 seams.
- **Pure Tier 1 Elimination**: Eliminate the 11 synthetic attribute classes. Refactor `Query` and `DeclaredQuery` to dynamically dispatch method calls directly onto `Illuminate\Database\Eloquent\Builder`. Replace the hijacked `from` noun with `model` (for model-rooted queries) or allow native `from()` table selection, and separate route parameter binding resolution cleanly in Tier 2 (`DeclaredView` / `DeclaredQuery`).

### 1.10 The Route Static Builder & Invented Noun Deviation (`src/Route.php`, `src/Providers/RoutesDeclarationServiceProvider.php`)
- **Glue Artifact**: `src/Route.php` with static required properties (`methods`, `path`, `action`) and 16 hardcoded `#[Builder]` properties dispatched via custom procedural match in `RoutesDeclarationServiceProvider`.
- **Underlying Missing Mapping**: Dynamic dispatch on `Illuminate\Routing\Router` and `Illuminate\Routing\Route`.
- **Architectural Defects**:
  - **Invented Noun (`path`)**: Laravel's router and route system of record universally use `uri` (`Illuminate\Routing\Route::$uri`, `Router::addRoute($methods, $uri, $action)`). `Route.php` invents the noun `path` instead.
  - **No Dynamic HTTP Verb Registration**: In Laravel, routes are defined via fluent HTTP verb methods (`get`, `post`, `put`, `patch`, `delete`, `options`, `any`, `match`) or shortcuts (`view`, `redirect`). `Route.php` enforces rigid fixed keys (`methods: 'GET'`, `path: '/'`, `action: '...'`), preventing concise declarative verb syntax.
  - **Static Builder Whitelist**: Instead of dynamic dispatch across `Route` fluent methods, `Route.php` annotates 16 properties with `#[Builder]` and procedures in `RoutesDeclarationServiceProvider`:
    `match (true) { is_bool($value) => $route->{$method}(), ... }`
    This omits native route constraint methods (`whereAlpha`, `whereAlphaNumeric`, `whereNumber`, `whereUlid`, `whereUuid`, `whereIn`), security methods (`secure()`, `httpOnly()`, `httpsOnly()`), scoped binding enforcers (`enforcesScopedBindings()`, `preventsScopedBindings()`), and field-level binding definitions (`bindingFields()`).
  - **Missing Route Grouping**: No declarative mapping for `Router::group()`, forcing common prefixes, domains, and middleware to be duplicated across individual route items.
- **Pure Tier 1 Elimination**: Rename `path` to `uri`. Implement dynamic dispatch from manifest route keys to `Route` methods. Map `Router::group()` and HTTP verb shortcuts natively in Tier 1.

### 1.11 The Service Provider Dead Declarative Keys & Invented Nouns (`src/Provider.php`, `src/Providers/ProvidersDeclarationServiceProvider.php`)
- **Glue Artifact**: `src/Provider.php` defining `name`, `description`, `bindings`, `singletons`, `factories`.
- **Underlying Missing Mapping**: Native `Illuminate\Foundation\Application::register($provider)`.
- **Architectural Defects**:
  - **Dead Declarative Keys**: `ProvidersDeclarationServiceProvider::boot()` only invokes `$this->app->register($provider->class)`. The keys `name`, `description`, `bindings`, `singletons`, and `factories` are completely ignored and never dispatched to the Laravel application or provider instance.
  - **Invented Noun (`factories`)**: Laravel's `ServiceProvider` has no concept of `factories`.
  - **Conflated Responsibilities**: In Laravel, `$bindings` and `$singletons` are internal properties defined on custom `ServiceProvider` classes, not registration arguments passed to `Application::register()`. If declarative container bindings are needed, they belong under `app:` (`Domain 1`), not as dead keys under `providers:`.
- **Pure Tier 1 Elimination**: Clean up `Provider` DataModel to map strictly to `Application::register()`. Delegate container bindings uniformly to `app:`.

### 1.12 The View Composer Parameter Inversion (`src/View.php`, `src/Providers/ViewDeclarationServiceProvider.php`)
- **Glue Artifact**: Custom dictionary keying in `src/View.php` and argument flipping in `src/Providers/ViewDeclarationServiceProvider.php`.
- **Underlying Missing Mapping**: Dynamic dispatch on `Illuminate\View\Factory::composer()` and `creator()`.
- **Architectural Defects**:
  - **Inverted Parameter Signature (Violates Rule 2: Pass arguments untouched)**: Laravel's system of record defines `ViewFactory::composer($views, $callback = null)` and `creator($views, $callback = null)`, where `$views` (string|array) is the first argument and `$callback` is the second. In `src/View.php`, the dictionary is keyed by callback pointing to views (`$callback => $views`), which `ViewDeclarationServiceProvider` swaps procedurally:
    `$factory->{$method}($views, $callback);`
  - **Ad-hoc Procedural Loops**: Bypasses dynamic dispatch by writing five separate loops for `Location`, `ViewNamespace`, `addExtension`, `share`, and `Composer`.
  - **Missing Native View Methods**: Omits `exists()`, `file()`, `make()`, `renderEach()`, and `flushFinderCache()`.
- **Pure Tier 1 Elimination**: Invert the manifest schema to match Laravel's native signature (`views: callback`), and replace procedural loops with uniform dynamic dispatch on `Illuminate\View\Factory`.

### 1.13 The Form Request Naming Deviation (`src/Request.php`, `src/DeclaredRequest.php`)
- **Glue Artifact**: `src/Request.php` defining property `failOnUnknownFields`.
- **Underlying Missing Mapping**: `Illuminate\Foundation\Http\FormRequest::shouldFailOnUnknownFields()`.
- **Architectural Defects**:
  - **Invented Noun / Property Name (Violates Rule 1: Key = method name)**: In Laravel `FormRequest`, the method is `shouldFailOnUnknownFields()` and the class attribute is `#[FailOnUnknownFields]`. Defining a manifest key and property named `failOnUnknownFields` deviates from the native method contract.
  - **Dynamic Rule Objects Missing**: Lacks Tier 1 mapping for registering custom `ValidationRule` implementations or invoking `Illuminate\Validation\Factory::extend()`.
- **Pure Tier 1 Elimination**: Align naming with native method `shouldFailOnUnknownFields`, and map `validator:` in Tier 1.

### 1.14 The Schema Whitelist & Missing Alteration/Drop Dispatch (`src/TableDefinition.php`, `src/ColumnDefinitionModel.php`, `src/Internal/Commands/MigrateCommand.php`)
- **Glue Artifact**: Hardcoded PHP arrays `$tableOptions`, `$tableConstraints`, `$fkModifiers`, and `$colModifiers` in `TableDefinition.php` and `ColumnDefinitionModel.php`.
- **Underlying Missing Mapping**: Dynamic dispatch on `Illuminate\Database\Schema\Blueprint` and `Illuminate\Database\Schema\ColumnDefinition`.
- **Architectural Defects**:
  - **Rigid Whitelists**: Rather than dispatching dynamically onto `Blueprint` or `ColumnDefinition`, the classes check against static arrays. Any valid method added by Laravel or third-party database drivers (or Blueprint macros) throws `LogicException("Unknown column modifier or option...")`.
  - **Zero Alteration / Drop Dispatch**: `MigrateCommand` only inspects `if (! $schemaBuilder->hasTable($tableName))` and creates new tables. There is zero declarative support for altering existing tables, dropping tables (`dropTable`, `dropTableIfExists`), renaming columns, dropping columns (`dropColumn`), or dropping indexes (`dropIndex`, `dropForeign`).
- **Pure Tier 1 Elimination**: Replace static modifier whitelists with dynamic dispatch using `method_exists()` or `__call()` on `Blueprint` and `ColumnDefinition`. Expand `schema:` to include declarative migration actions (`alter`, `drop`, `rename`).

### 1.15 The Container & Application Unmapped Methods (`src/App.php`, `src/Providers/AppDeclarationServiceProvider.php`)
- **Glue Artifact**: Missing Container methods falsely documented as mapped.
- **Underlying Missing Mapping**: `Illuminate\Container\Container` and `Illuminate\Foundation\Application`.
- **Architectural Defects**:
  - **Documentation Audit Mismatch**: The framework mapping document claimed `tag`, `contextual`, `resolving`, and `afterResolving` were mapped in `src/App.php`. In reality, none of these methods exist in `src/App.php` or `AppDeclarationServiceProvider`.
  - **Missing Application Path Setters**: Native methods `useBootstrapPath()`, `useConfigPath()`, and `useEnvironmentPath()` are present on `Illuminate\Foundation\Application` but absent from `src/App.php`.
- **Pure Tier 1 Elimination**: Map `tag`, `contextual`, `resolving`, `afterResolving`, and the missing application path setters directly into Tier 1 `app:` via dynamic dispatch.

---

## 2. Comprehensive Laravel Framework API Mapping (12 Architectural Domains)

Derived from `vendor/laravel/framework/src/Illuminate/` (`laravel/framework` v13.33.0) and `docs/repos/laravel/docs/*.md`.

---

### Domain 1: Core Architecture, Container & Configuration

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Service Container**<br>`docs/repos/laravel/docs/container.md` | `Illuminate\Container\Container`<br>`Illuminate\Foundation\Application` | Tier 1 | **Partially Mapped** | `app:` (`src/App.php`) | **Mapped**: `bind`, `bindIf`, `singleton`, `singletonIf`, `scoped`, `scopedIf`, `instance`, `alias`, `extend`, `useAppPath`, `useDatabasePath`, `useLangPath`, `usePublicPath`, `useStoragePath`, `setLocale`, `setFallbackLocale`, `registered`, `booting`, `booted`, `terminating`.<br>**Gap**: `tag()`, `contextual()` (`when()->needs()->give()`), `resolving()`, `afterResolving()`, `useBootstrapPath()`, `useConfigPath()`, `useEnvironmentPath()`. | Advanced container configurations cannot be expressed declaratively; previously falsely audited as fully mapped. |
| **Configuration**<br>`docs/repos/laravel/docs/configuration.md` | `Illuminate\Config\Repository` | Tier 1 | **Implemented** | `config:` (`ConfigDeclarationServiceProvider`) | **Mapped**: `set($key, $value)`.<br>**Gap**: None. | None. Directly mutates configuration repository. |
| **Service Providers**<br>`docs/repos/laravel/docs/providers.md` | `Illuminate\Support\ServiceProvider` | Tier 1 | **Indirectly Mapped / Dead Verbs** | `providers:` (`src/Provider.php`) | **Mapped**: `Application::register($provider)`.<br>**Gap**: `bindings`, `singletons`, `factories`, `name`, `description` are dead declarative keys defined on `Provider.php` but discarded during provider registration; deferred provider boot arguments. | Developers declare bindings or factories on providers expecting them to register, but they are silently ignored. |

---

### Domain 2: HTTP Kernel & Middleware Pipeline

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **HTTP Kernel & Middleware**<br>`docs/repos/laravel/docs/middleware.md` | `Illuminate\Foundation\Http\Kernel`<br>`Illuminate\Routing\Pipeline` | Tier 1 | **Implemented** | `kernel:` (`src/Kernel.php`) | **Mapped**: `pushMiddleware`, `prependMiddleware`, `setGlobalMiddleware`, `appendMiddlewareToGroup`, `prependMiddlewareToGroup`, `setMiddlewareGroups`, `setMiddlewareAliases`, `setMiddlewarePriority`, `prependToMiddlewarePriority`, `appendToMiddlewarePriority`, `addToMiddlewarePriorityBefore`, `addToMiddlewarePriorityAfter`, `whenRequestLifecycleIsLongerThan`.<br>**Gap**: None. | None. Pipeline executes via native HTTP kernel. |
| **CSRF Protection**<br>`docs/repos/laravel/docs/csrf.md` | `Illuminate\Foundation\Http\Middleware\ValidateCsrfToken` | Tier 1 | **Missing / Left To Do** | `csrf:` / `kernel.csrf` | **Mapped**: None.<br>**Gap**: URI exclusions (`except: ['api/*', 'stripe/*']`). | Forces manual PHP editing of CSRF middleware exclusions for external webhooks. |
| **HTTP Precognition**<br>`docs/repos/laravel/docs/precognition.md` | `Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests` | Tier 1 | **Missing / Left To Do** | `precognition:` | **Mapped**: None.<br>**Gap**: Precognitive validation headers and route middleware binding. | Precognitive live frontend validation cannot be configured declaratively. |

---

### Domain 3: HTTP Routing, Pipeline, URLs & Throttling

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Router Configuration**<br>`docs/repos/laravel/docs/routing.md` | `Illuminate\Routing\Router` | Tier 1 | **Mapped** | `router:` (`src/Router.php`) | **Mapped**: `pattern`, `model`, `bind`, `middlewareGroup`, `aliasMiddleware`, `pushMiddlewareToGroup`, `prependMiddlewareToGroup`, `removeMiddlewareFromGroup`, `singularResourceParameters`, `resourceParameters`, `resourceVerbs`, `matched` (`patterns` a decided non-goal).<br>**Gap**: none. | Advanced router parameter patterns, global group definitions, and resource globals are declarable. |
| **Route Registration**<br>`docs/repos/laravel/docs/routing.md` | `Illuminate\Routing\Router`<br>`Illuminate\Routing\Route` | Tier 1 | **Partially Mapped (Invented Noun & Static Builders)** | `routes:` (`src/Route.php`) | **Mapped**: `addRoute`, `name`, `prefix`, `domain`, `middleware`, `withoutMiddleware`, `can`, `where`, `setDefaults`, `missing`, `fallback`, `scopeBindings`, `withoutScopedBindings`, `withTrashed`, `block`, `withoutBlocking`, `metadata`.<br>**Gap**: Uses invented noun `path` instead of native `uri`; lacks dynamic dispatch to fluent HTTP verbs (`get`, `post`, `match`, etc.); uses static list of 16 builders omitting native constraints (`whereAlpha`, `whereNumber`, `whereIn`), protocol restrictions (`secure()`, `httpOnly()`), parameter binding maps (`bindingFields()`), and route groups (`Router::group()`). | Routes cannot be declared using idiomatic HTTP verb keys; static content is routed through heavy seam controllers. |
| **Native Route Shortcuts**<br>`docs/repos/laravel/docs/routing.md` | `Illuminate\Routing\Router::view()`<br>`Illuminate\Routing\Router::redirect()` | Tier 1 | **Missing / Left To Do** | `routes:` (`view`, `redirect`) | **Mapped**: None.<br>**Gap**: Invoking native `Router::view()` and `Router::redirect()` directly when routes require no dynamic queries or actions. | Forces static views and simple redirects through heavy custom controllers (`DeclaredView`, `DeclaredAction`). |
| **URL Generation**<br>`docs/repos/laravel/docs/urls.md` | `Illuminate\Routing\UrlGenerator` | Tier 1 | **Missing / Left To Do** | `url:` | **Mapped**: None.<br>**Gap**: `signedRoute()`, `temporarySignedRoute()`, `forceScheme()`, `forceRootUrl()`, `defaults()`. | Forces URL signing and default scheme logic into bespoke middleware or closures. |
| **Rate Limiter**<br>`docs/repos/laravel/docs/rate-limiting.md` | `Illuminate\Cache\RateLimiter` | Tier 1 | **Missing / Left To Do** | `rate_limiter:` | **Mapped**: None.<br>**Gap**: `RateLimiter::for($name, Closure)`, `attempt()`. | Limits declarative routes to standard throttle strings without custom keyed rate limiters. |

---

### Domain 4: View Layer, Blade Engine, Components & Pagination

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **View Factory**<br>`docs/repos/laravel/docs/views.md` | `Illuminate\View\Factory`<br>`Illuminate\View\FileViewFinder` | Tier 1 | **Partially Mapped (Inverted Signatures)** | `view:` (`src/View.php`) | **Mapped**: `addLocation`, `prependLocation`, `addNamespace`, `prependNamespace`, `replaceNamespace`, `addExtension`, `share`, `composer`, `creator`.<br>**Gap**: Manifest inverts native `composer($views, $callback)` parameter signature into `$callback => $views`; missing `exists()`, `file()`, `make()`, `renderEach()`, and `flushFinderCache()`. | Inverted manifest parameter signature violates Rule 2 (pass arguments untouched); unmapped factory methods require PHP extensions. |
| **Blade Compiler**<br>`docs/repos/laravel/docs/blade.md` | `Illuminate\View\Compilers\BladeCompiler`<br>`Illuminate\View\Component` | Tier 1 | **Missing / Left To Do** | `blade:` | **Mapped**: None.<br>**Gap**: `directive()`, `if()`, `component()`, `components()`, `anonymousComponentPath()`, `anonymousComponentNamespace()`, `stringable()`, `precompiler()`, `withoutDoubleEncoding()`. | **Critical Shortcut**: Inline templates in `DeclaredView` cannot resolve anonymous components (`<x-layout>`) or custom directives (`@datetime`), forcing template rendering into an isolated component sandbox. |
| **Pagination Engine**<br>`docs/repos/laravel/docs/pagination.md` | `Illuminate\Pagination\Paginator`<br>`Illuminate\Pagination\LengthAwarePaginator` | Tier 1 | **Missing / Left To Do** | `pagination:` | **Mapped**: None.<br>**Gap**: `defaultView()`, `defaultSimpleView()`, `useTailwind()`, `useBootstrapFive()`. | Paginated query results rendered in views cannot declare standard framework styling declaratively. |
| **View Dispatch Controller**<br>`docs/repos/laravel/docs/views.md` | `Illuminate\Routing\ViewController` | Tier 2 | **Partially Mapped** | `DeclaredView` (`src/DeclaredView.php`) | **Mapped**: Extends `ViewController::__invoke()`, merges query results and route parameters into `$args['data']`.<br>**Gap**: Dynamic dispatch to `BladeCompiler::render()`, view composer event bridging. | Does not support inline templates yet. |

---

### Domain 5: Request Lifecycle, Input Resolution & Validation

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Form Request Declaration**<br>`docs/repos/laravel/docs/requests.md` | `Illuminate\Foundation\Http\FormRequest` | Tier 1 | **Partially Mapped (Invented Noun)** | `requests:` (`src/Request.php`) | **Mapped**: `rules`, `messages`, `attributes`, `stopOnFirstFailure`, `redirect`, `redirectRoute`, `redirectAction`, `errorBag`, `validationData`, `prepareForValidation`, `passedValidation`, `withValidator`, `after`, `validator`, `failedValidation`, `failedAuthorization`, `authorize`.<br>**Gap**: Property `failOnUnknownFields` uses invented noun instead of native method `shouldFailOnUnknownFields()`; lacks dynamic validation rule factory dispatch (`validator.extend()`, `validator.replacer()`); missing dynamic conditional rules (`sometimes`). | Request definitions map broadly onto `FormRequest`, but custom validation rules require manual PHP service provider registration. |
| **Validation Factory**<br>`docs/repos/laravel/docs/validation.md` | `Illuminate\Validation\Factory`<br>`Illuminate\Contracts\Validation\ValidationRule` | Tier 1 | **Missing / Left To Do** | `validator:` | **Mapped**: None.<br>**Gap**: `extend()`, `extendImplicit()`, `extendDependent()`, `replacer()`, custom `Rule` object bindings. | Prevents declaring custom validation rules directly in YAML; forces PHP service providers. |
| **Form Request Seam**<br>`docs/repos/laravel/docs/requests.md` | `Illuminate\Foundation\Http\FormRequest` | Tier 2 | **Partially Mapped** | `DeclaredRequest` (`src/DeclaredRequest.php`) | **Mapped**: Validates resolved inbound request against manifest definition; fails with standard `ValidationException`.<br>**Gap**: Policy-based authorization hooks. | Integrates declarative validation cleanly into route pipeline. |

---

### Domain 6: Response Generation, Redirects, Cookies & API Resources

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Response Factory**<br>`docs/repos/laravel/docs/responses.md` | `Illuminate\Contracts\Routing\ResponseFactory`<br>`Illuminate\Routing\ResponseFactory` | Tier 1 | **Missing / Left To Do** | `responses:` | **Mapped**: None.<br>**Gap**: `make()`, `view()`, `json()`, `noContent()`, `stream()`, `download()`, `macro()`. | **Shortcut Risk**: `DeclaredView` and `DeclaredAction` hardcode response creation instead of delegating to mapped response formatters. |
| **Redirector & Redirect Response**<br>`docs/repos/laravel/docs/responses.md` | `Illuminate\Routing\Redirector`<br>`Illuminate\Http\RedirectResponse`<br>`Illuminate\Routing\RedirectController` | Tier 1 | **Missing / Left To Do** | `redirect:` | **Mapped**: None.<br>**Gap**: Pure Tier 1 mapping of `route()`, `to()`, `back()`, `away()`, `action()`, `with()`, `withCookies()`, `withInput()`, `withErrors()`. | Eliminates premature synthetic attribute classes (`RedirectAction`, `FlashAction`). |
| **Cookie Jar**<br>`docs/repos/laravel/docs/responses.md` | `Illuminate\Cookie\CookieJar` | Tier 1 | **Missing / Left To Do** | `cookie:` | **Mapped**: None.<br>**Gap**: `make()`, `forever()`, `forget()`, queueing cookies. | Outgoing cookies must be attached procedurally via PHP middleware. |
| **API Resources**<br>`docs/repos/laravel/docs/eloquent-resources.md` | `Illuminate\Http\Resources\Json\JsonResource` | Tier 1 | **Missing / Left To Do** | `resources:` | **Mapped**: None.<br>**Gap**: Declarative model-to-JSON transformations and collection wrapping. | RESTful API routes must return raw models or hand-written Resource classes. |
| **Action Controller Seam**<br>`docs/declarative-action.md` | `Illuminate\Routing\Controller` | Tier 2 | **Active Scope (Phase 7)** | `DeclaredAction` | **Mapped**: None (Premature commit 28555ec reverted).<br>**Gap**: Decoupled state mutation seam utilizing `DB::transaction()` and native `RedirectResponse`. | Must be implemented strictly after Tier 1 `db:` (Phase 6.5) and `redirect:` (Phase 6.6) mappings. |

---

### Domain 7: Database Connection, Query Builder, Transactions & Seeding

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Database Connection & Transactions**<br>`docs/repos/laravel/docs/database.md` | `Illuminate\Database\DatabaseManager`<br>`Illuminate\Database\Connection` | Tier 1 | **Missing / Left To Do** | `db:` | **Mapped**: None.<br>**Gap**: `transaction()`, `statement()`, `unprepared()`, `listen()`, `beforeExecuting()`. | **Critical Shortcut**: `DeclaredAction` executes model mutations without database transaction boundaries. If post-write logic fails, partial writes persist. |
| **Database Query Builder**<br>`docs/repos/laravel/docs/queries.md` | `Illuminate\Database\Query\Builder` | Tier 1 | **Missing / Left To Do** | `db_queries:` / `queries.table` | **Mapped**: None.<br>**Gap**: Table-level direct queries (`DB::table(...)`) bypassing Eloquent models. | `queries:` is forced to bind strictly to Eloquent classes, preventing lightweight raw table reads. |
| **Database Schema & Blueprint**<br>`docs/repos/laravel/docs/migrations.md` | `Illuminate\Database\Schema\Builder`<br>`Illuminate\Database\Schema\Blueprint` | Tier 1 | **Partially Mapped** | `schema:` (`src/Schema.php`, `TableDefinition.php`) | **Mapped**: `id`, `string`, `text`, `boolean`, `integer`, `foreignId`, `timestamps`, table options (`engine`, `charset`, `collation`, `temporary`, `comment`), column modifiers (`nullable`, `default`, `unique`, `index`, `primary`, etc.), table constraints (`primary`, `unique`, `index`, `fullText`, `spatialIndex`, `vectorIndex`), foreign key modifiers (`constrained`, `cascadeOnDelete`, `cascadeOnUpdate`, etc.).<br>**Gap**: Table alters, renames, drops (`dropTable`, `dropTableIfExists`, `dropColumn`, `renameColumn`, `dropIndex`, `dropForeign`), and dynamic dispatch on `Blueprint`/`ColumnDefinition` (currently restricted by hardcoded static whitelists in `TableDefinition.php` and `ColumnDefinitionModel.php`). | Complex schema mutations, table drops, and driver-specific column types cannot be declared dynamically. |
| **Database Seeding & Factories**<br>`docs/repos/laravel/docs/seeding.md` | `Illuminate\Database\Seeder`<br>`Illuminate\Database\Eloquent\Factories\Factory` | Tier 1 | **Missing / Left To Do** | `seeds:` | **Mapped**: None.<br>**Gap**: Declarative table record insertion or factory sequence definitions. | Declarative applications have no native way to seed baseline database state. |

---

### Domain 8: Eloquent ORM, Relationships, Casts & Synthesis

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Eloquent Model Configuration**<br>`docs/repos/laravel/docs/eloquent.md` | `Illuminate\Database\Eloquent\Model` | Tier 1 | **Partially Mapped** | `models:` (`src/Model.php`) | **Mapped**: `table`, `primaryKey`, `incrementing`, `timestamps`, `casts`, `fillable`, `guarded`, `hidden`, `visible`, `appends`, `observe`, `addGlobalScope`, `getRouteKeyName`, `connection`, `keyType`, `dateFormat`, `attributes`, `with`, `withCount`, `touches`, `refreshes`, `perPage`, `dispatchesEvents`, `observables`.<br>**Gap**: Relationships, modern `casts()` array methods, custom Cast classes, local query scopes (`scopeActive`), mutators/accessors (`Attribute::make`), lifecycle hooks (`booted`, `booting`), pruning (`prunable`). | Model property defaults map 1:1, but models cannot declare relations, modern cast methods, or local scopes. |
| **Eloquent Relationships**<br>`docs/repos/laravel/docs/eloquent-relationships.md` | `Illuminate\Database\Eloquent\Relations\*` | Tier 1 | **Missing / Left To Do** | `models.relations:` | **Mapped**: None.<br>**Gap**: `hasOne`, `hasMany`, `belongsTo`, `belongsToMany`, `hasManyThrough`, `morphTo`, `morphMany`. | **Critical Blocker**: Dynamic model synthesis cannot create relational models without relationship mappings. |
| **Eloquent Attribute Casts & Mutators**<br>`docs/repos/laravel/docs/eloquent-mutators.md` | `Illuminate\Database\Eloquent\Casts\Attribute`<br>`Illuminate\Contracts\Database\Eloquent\CastsAttributes` | Tier 1 | **Missing / Left To Do** | `models.casts:` | **Mapped**: Property string casts.<br>**Gap**: Modern `casts()` method array definitions, custom Cast classes, and accessor/mutator callables. | Model attribute conversions are limited to primitive scalar types. |
| **Eloquent Query Builder**<br>`docs/repos/laravel/docs/queries.md` | `Illuminate\Database\Eloquent\Builder` | Tier 1 | **Indirectly Mapped / Synthetic Attribute Shortcut** | `queries:` (`src/Query.php`) | **Mapped**: 40+ methods mapped via 11 synthetic attribute classes (`Clause`, `Where`, `Spread`, `Flag`, `Count`, `Exists`, `Fetch`, `Find`, `Paginate`, `Terminal`, `BelongsTo`).<br>**Gap**: Dynamic dispatch on `Builder`; unmapped methods and macros silently ignored (`continue;`) violating Rule 3; invented noun `from` hijacks native `Builder::from($table, $as)` for model/relation identification; route parameter bleed in `BelongsTo` violates Rule 5; subquery closures, raw SQL expressions (`whereRaw`, `selectRaw`). | Silently swallows unsupported or misspelled methods; breaks native table aliasing via `from()`; tightly couples Tier 1 query evaluation with HTTP route parameters. |
| **Dynamic Query Seam**<br>`docs/declarative-query.md` | `Illuminate\Database\Eloquent\Builder` | Tier 2 | **Implemented** | `DeclaredQuery` (`src/DeclaredQuery.php`) | **Mapped**: Evaluates query definition, binds request parameters, and executes query against model. | Connects route requests to Eloquent execution cleanly. |
| **Dynamic Model Synthesis**<br>`docs/declarative-model.md` | `Illuminate\Database\Eloquent\Model` | Tier 2 | **Partially Mapped** | `DeclaredModel` (`src/DeclaredModel.php`) | **Mapped**: Synthesizes runtime classes extending `Model`.<br>**Gap**: Synthesizing relational methods (`belongsTo()`, `hasMany()`). | Relies on Tier 1 relationship mapping to support relational models. |
| **Full-Text Search (Scout)**<br>`docs/repos/laravel/docs/scout.md` | `Laravel\Scout\Searchable` | Tier 1 | **Missing / Left To Do** | `scout:` | **Mapped**: None.<br>**Gap**: `search()`, search index configuration, searchable array definitions. | Full-text search and Algolia/Meilisearch indexing cannot be declared in YAML. |

---

### Domain 9: Security, Identity, Authentication & Authorization Gates

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Authorization Gate**<br>`docs/repos/laravel/docs/authorization.md` | `Illuminate\Contracts\Auth\Access\Gate`<br>`Illuminate\Auth\Access\Gate` | Tier 1 | **Missing / Left To Do** | `gate:` | **Mapped**: None.<br>**Gap**: `define()`, `policy()`, `before()`, `after()`, `resource()`, `authorize()`. | Forces route authorization to rely on hardcoded booleans or hand-written request classes rather than native Gates/Policies. |
| **Authentication Guards**<br>`docs/repos/laravel/docs/authentication.md` | `Illuminate\Auth\AuthManager` | Tier 1 | **Missing / Left To Do** | `auth:` | **Mapped**: None.<br>**Gap**: `guard()`, `provider()`, `shouldUse()`, default driver selection. | Authentication guard definitions must be configured via PHP config files. |
| **Session Manager & Store**<br>`docs/repos/laravel/docs/session.md` | `Illuminate\Session\SessionManager`<br>`Illuminate\Session\Store` | Tier 1 | **Missing / Left To Do** | `session:` | **Mapped**: None.<br>**Gap**: `flash()`, `now()`, `reflash()`, `keep()`, `put()`, `get()`. | `DeclaredAction` created bespoke `FlashAction` attribute instead of using `Session::flash()`. |
| **Hashing & Encryption**<br>`docs/repos/laravel/docs/hashing.md`<br>`encryption.md` | `Illuminate\Hashing\HashManager`<br>`Illuminate\Encryption\Encrypter` | Tier 1 | **Missing / Left To Do** | `hashing:`, `encryption:` | **Mapped**: None.<br>**Gap**: `make()`, `encrypt()`, `decrypt()`. | Driver choices and key configurations cannot be set via YAML. |
| **API Token Authentication (Sanctum)**<br>`docs/repos/laravel/docs/sanctum.md` | `Laravel\Sanctum\HasApiTokens`<br>`Laravel\Sanctum\Sanctum` | Tier 1 | **Missing / Left To Do** | `sanctum:` | **Mapped**: None.<br>**Gap**: Token abilities, expiration configuration, stateful domain bindings. | API routes requiring token-based authentication must be wired manually in PHP. |

---

### Domain 10: Events, Listeners & Async Communication

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Event Dispatcher**<br>`docs/repos/laravel/docs/events.md` | `Illuminate\Events\Dispatcher` | Tier 1 | **Missing / Left To Do** | `events:` | **Mapped**: None.<br>**Gap**: `listen()`, `subscribe()`, `dispatch()`, `until()`. | State mutations in `DeclaredAction` cannot dispatch decoupled domain events to notify other subsystems. |
| **Queues & Jobs**<br>`docs/repos/laravel/docs/queues.md` | `Illuminate\Queue\QueueManager`<br>`Illuminate\Bus\Dispatcher` | Tier 1 | **Missing / Left To Do** | `queues:`, `bus:` | **Mapped**: None.<br>**Gap**: `push()`, `later()`, `dispatch()`, `dispatchSync()`. | Async job dispatching cannot be initiated declaratively. |
| **Mail & Notifications**<br>`docs/repos/laravel/docs/mail.md`<br>`notifications.md` | `Illuminate\Mail\MailManager`<br>`Illuminate\Notifications\ChannelManager` | Tier 1 | **Missing / Left To Do** | `mail:`, `notifications:` | **Mapped**: None.<br>**Gap**: `send()`, `to()`, notification channel routing. | Transactional emails and notifications must be hand-written in PHP listeners. |
| **Broadcasting**<br>`docs/repos/laravel/docs/broadcasting.md` | `Illuminate\Broadcasting\BroadcastManager` | Tier 1 | **Missing / Left To Do** | `broadcasting:` | **Mapped**: None.<br>**Gap**: Channel routes, broadcaster driver configuration. | Real-time events cannot be broadcasted declaratively. |

---

### Domain 11: Operations, Console, Storage, Logging & Systems

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Artisan Console**<br>`docs/repos/laravel/docs/artisan.md` | `Illuminate\Console\Application` | Tier 1 | **Missing / Left To Do** | `commands:` | **Mapped**: Internal package commands (`declaration:migrate`, etc.).<br>**Gap**: Declarative custom commands (`commands:`). | Custom console commands cannot be registered via YAML. |
| **Task Scheduling**<br>`docs/repos/laravel/docs/scheduling.md` | `Illuminate\Console\Scheduling\Schedule` | Tier 1 | **Missing / Left To Do** | `schedule:` | **Mapped**: None.<br>**Gap**: `command()`, `job()`, `call()`, `daily()`, `hourly()`. | Recurring cron jobs cannot be scheduled via YAML. |
| **Cache Manager**<br>`docs/repos/laravel/docs/cache.md` | `Illuminate\Cache\CacheManager`<br>`Illuminate\Contracts\Cache\Repository` | Tier 1 | **Missing / Left To Do** | `cache:` | **Mapped**: None.<br>**Gap**: Declarative query memoization, store configuration, cache tagging. | Query caching must be implemented in hand-written repository scopes. |
| **Filesystem & Storage**<br>`docs/repos/laravel/docs/filesystem.md` | `Illuminate\Filesystem\FilesystemManager` | Tier 1 | **Missing / Left To Do** | `storage:` | **Mapped**: None.<br>**Gap**: `disk()`, `build()`, disk driver configuration. | File upload destinations must be configured in PHP config files. |
| **Localization & Translation**<br>`docs/repos/laravel/docs/localization.md` | `Illuminate\Translation\Translator` | Tier 1 | **Missing / Left To Do** | `lang:` | **Mapped**: None.<br>**Gap**: `addLines()`, `addJsonPath()`, `setLocale()`. | View translations rely on physical language files. |
| **Logging & Context**<br>`docs/repos/laravel/docs/logging.md`<br>`context.md` | `Illuminate\Log\LogManager`<br>`Illuminate\Log\Context\Repository` | Tier 1 | **Missing / Left To Do** | `logging:`, `context:` | **Mapped**: None.<br>**Gap**: `channel()`, `Context::add()`. | Trace IDs and contextual metadata cannot be bound declaratively. |
| **Application Telemetry & Monitoring (Pulse)**<br>`docs/repos/laravel/docs/pulse.md` | `Laravel\Pulse\Pulse` | Tier 1 | **Missing / Left To Do** | `pulse:` | **Mapped**: None.<br>**Gap**: Recorders configuration, slow query thresholds, user resolvers. | Performance monitoring and slow query detection cannot be configured via manifest. |

---

### Domain 12: Processes, Concurrency & Extensibility

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Processes & Concurrency**<br>`docs/repos/laravel/docs/processes.md`<br>`concurrency.md` | `Illuminate\Process\Factory`<br>`Illuminate\Concurrency\ConcurrencyManager` | Tier 1 | **Missing / Left To Do** | `process:`, `concurrency:` | **Mapped**: None.<br>**Gap**: `run()`, `pool()`, `concurrency()->run()`. | Concurrent asynchronous data resolution is unavailable in YAML. |
| **HTTP Client**<br>`docs/repos/laravel/docs/http-client.md` | `Illuminate\Http\Client\Factory` | Tier 1 | **Missing / Left To Do** | `http:` | **Mapped**: None.<br>**Gap**: `baseUrl()`, `withHeaders()`, `macro()`. | External API integrations in view data must be hardcoded in PHP service classes. |
| **Exception Handling**<br>`docs/repos/laravel/docs/errors.md` | `Illuminate\Contracts\Debug\ExceptionHandler` | Tier 1 | **Missing / Left To Do** | `exceptions:` | **Mapped**: None.<br>**Gap**: `renderable()`, `reportable()`, `dontFlash()`. | Uncaught exceptions in seam controllers cannot be mapped to custom declarative error responses. |
| **Feature Flags**<br>`docs/repos/laravel/docs/pennant.md` | `Laravel\Pennant\FeatureManager` | Tier 1 | **Missing / Left To Do** | `features:` | **Mapped**: None.<br>**Gap**: `define()`, feature-based route middleware. | Declarative routes cannot be toggled conditionally using feature flags. |

---

## 3. Concrete Architectural Progression Toward `declarative-inline-template.md`

To progress towards Phase 8 (`docs/declarative-inline-template.md`) naturally—eliminating shortcuts and adhering strictly to the design rules (**Key = method name**, **Pass references through**, **Fail where Laravel fails**)—the following architectural sequence must be executed:

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│ STEP 1 (TIER 1 API MAP): Map `blade:` -> `Illuminate\View\Compilers\BladeCompiler`     │
│  - Methods: directive, if, component, components, anonymousComponentPath, stringable    │
│  - Registered via BladeDeclarationServiceProvider queued on blade.compiler resolution  │
└───────────────────────────────────────────┬────────────────────────────────────────────┘
                                            │
                                            ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│ STEP 2 (TIER 1 API MAP): Map `responses:` -> `Illuminate\Contracts\Routing\ResponseFactory`
│  - Declarative response format defaults, status codes, headers, and custom macros      │
└───────────────────────────────────────────┬────────────────────────────────────────────┘
                                            │
                                            ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│ STEP 3 (TIER 1 API MAP): Map `pagination:` -> `Illuminate\Pagination\Paginator`        │
│  - Declarative pagination styling: useTailwind, useBootstrapFive, defaultView          │
└───────────────────────────────────────────┬────────────────────────────────────────────┘
                                            │
                                            ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│ STEP 4 (TIER 2 GLUE CODE): Refactor `DeclaredView` for Inline Templates (Phase 8)       │
│  - Eliminate bespoke `RenderAction` attribute class entirely                           │
│  - Resolve dynamic queries (queries:) and route parameter bindings                     │
│  - Fire view composer event `composing: {route.name}` to trigger view.composer hooks   │
│  - Render inline template via native BladeCompiler::render() with mapped components    │
│  - Return outgoing HTTP response via ResponseFactory::make($html, $status, $headers)   │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

---

### Step 1: Map `blade:` into Tier 1 (`BladeCompiler`)

Create `src/Blade.php` (DataModel) and `src/Providers/BladeDeclarationServiceProvider.php` mapping directly to `Illuminate\View\Compilers\BladeCompiler`.

#### Declarative Schema in Manifest (`manifest/app.yml`):
```yaml
blade:
  anonymousComponentPath:
    - path: resources/views/components
      prefix: ~
  anonymousComponentNamespace:
    - directory: resources/views/inputs
      prefix: inputs
  directive:
    datetime: App\Blade\Directives@datetime
  if:
    admin: App\Blade\Conditions@isAdmin
  withoutDoubleEncoding: true
```

#### Implementation Rules for `BladeDeclarationServiceProvider`:
- **System of Record**: `Illuminate\View\Compilers\BladeCompiler`.
- **Registration**: Queued via `$this->callAfterResolving('blade.compiler', function (BladeCompiler $blade) { ... })`.
- **Key-to-Method Mapping**:
  - `directive`: Invokes `$blade->directive($name, $handler)`.
  - `if`: Invokes `$blade->if($name, $callback)`.
  - `component`: Invokes `$blade->component($class, $alias)`.
  - `components`: Invokes `$blade->components($components)`.
  - `anonymousComponentPath`: Invokes `$blade->anonymousComponentPath($path, $prefix)`.
  - `anonymousComponentNamespace`: Invokes `$blade->anonymousComponentNamespace($directory, $prefix)`.
  - `stringable`: Invokes `$blade->stringable($class, $callback)`.
  - `withoutDoubleEncoding`: Invokes `$blade->withoutDoubleEncoding()`.
- **Pass-through**: Reference strings (`App\Blade\Directives@datetime`) resolve via Laravel's native container call resolution (`app()->call()`).

---

### Step 2: Map `responses:` into Tier 1 (`ResponseFactory`)

Create `src/Response.php` and `src/Providers/ResponseDeclarationServiceProvider.php` mapping directly to `Illuminate\Contracts\Routing\ResponseFactory`.

#### Declarative Schema in Manifest (`manifest/app.yml`):
```yaml
responses:
  macro:
    apiSuccess: App\Http\Responses\ApiResponses@success
```

#### Implementation Rules:
- **System of Record**: `Illuminate\Contracts\Routing\ResponseFactory` (`Illuminate\Routing\ResponseFactory`).
- **Registration**: Registered during provider boot on `app(ResponseFactory::class)`.
- Standardizes all response generation (`make()`, `view()`, `json()`) across both `DeclaredView` and `DeclaredAction`.

---

### Step 3: Map `pagination:` into Tier 1 (`Paginator`)

Create `src/Providers/PaginationDeclarationServiceProvider.php` mapping directly to `Illuminate\Pagination\Paginator`.

#### Declarative Schema in Manifest (`manifest/app.yml`):
```yaml
pagination:
  defaultView: pagination::tailwind
  defaultSimpleView: pagination::simple-tailwind
```

#### Implementation Rules:
- **System of Record**: `Illuminate\Pagination\Paginator`.
- **Registration**: Registered during provider boot on `Paginator`.
- Ensures `$todos->links()` renders correctly in inline Blade templates.

---

### Step 4: Refactor `DeclaredView` in Tier 2 (Phase 8 Seam)

With `blade:`, `responses:`, and `pagination:` mapped cleanly into Tier 1, `src/DeclaredView.php` no longer needs any bespoke attribute classes (`RenderAction`). It cleanly bridges route parameters, queries, view composers, Blade compilation, and response generation:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Response;
use Illuminate\Routing\ViewController;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use LogicException;

class DeclaredView extends ViewController
{
    /**
     * @param  string  $method
     * @param  array<string, mixed>  $parameters
     */
    public function callAction($method, $parameters): Response
    {
        return $this->{$method}(...$parameters);
    }

    public function __invoke(mixed ...$args): Response
    {
        $args += ['data' => [], 'status' => 200, 'headers' => []];

        $route = request()->route();
        $routeParameters = array_filter($args, static function (string|int $key): bool {
            return ! in_array(
                $key,
                ['template', 'view', 'data', 'status', 'headers', 'deleteCachedView'],
                true
            );
        }, ARRAY_FILTER_USE_KEY);

        // 1. Inbound validation if metadata.request is declared
        $parameters = [
            ...$routeParameters,
            'request' => $route?->getMetadata('request') === null
                ? request()
                : app(DeclaredRequest::class),
        ];

        // 2. Resolve dynamic view data (queries or container callables)
        /** @var array<string, mixed> $data */
        $data = $args['data'];
        $manifest = app(Manifest::class);

        $resolvedData = array_map(
            static function (mixed $value) use ($parameters, $manifest): mixed {
                if (is_string($value) && $manifest->queries->has($value)) {
                    return DeclaredQuery::run($value, $parameters);
                }

                return is_string($value) && str_contains(Str::before($value, '@'), '\\')
                    ? app()->call($value, $parameters)
                    : $value;
            },
            $data,
        );

        $mergedData = array_merge($resolvedData, $routeParameters);

        // 3. Dynamic Dispatch: Template wins over external View file
        if (isset($args['template']) && is_string($args['template'])) {
            // Bridge to ViewFactory composers if route is named
            if ($routeName = $route?->getName()) {
                event("composing: {$routeName}", [$mergedData]);
            }

            $deleteCachedView = ! isset($args['deleteCachedView']) || (bool) $args['deleteCachedView'];
            $content = Blade::render($args['template'], $mergedData, deleteCachedView: $deleteCachedView);

            /** @var ResponseFactory $responseFactory */
            $responseFactory = $this->response;

            return $responseFactory->make($content, (int) $args['status'], (array) $args['headers']);
        }

        if (isset($args['view'])) {
            return parent::__invoke(...[
                ...$args,
                'data' => $mergedData,
            ]);
        }

        throw new LogicException(
            "DeclaredView requires either 'template' or 'view' to be specified in setDefaults."
        );
    }
}
```

---

## 4. Master Phased Implementation Roadmap: API Maps First, Glue Code Second

To maintain clean separation between the Laravel API map and glue code, all future roadmap phases are strictly categorized:

| Phase | Category | Subsystem / Component | System of Record | Deliverables |
|---|---|---|---|---|
| **Phase 6** | Tier 1 API Map & Tier 2 Seam | **Declarative Schema (`schema:`)** | `Illuminate\Database\Schema\Builder` | Schema DataModel, `MigrateCommand`, SQLite/MySQL table creation. |
| **Phase 6.1** | Tier 1 API Map | **Schema Dynamic Dispatch, Alters & Drops (`schema:`)** | `Illuminate\Database\Schema\Builder`<br>`Illuminate\Database\Schema\Blueprint` | Eliminate static modifier whitelists via dynamic dispatch; declarative table alterations, column drops, renames, and drops (`dropIfExists`). |
| **Phase 6.2** | Tier 1 API Map | **Route URI & Verb Dynamic Dispatch (`routes:`)** | `Illuminate\Routing\Router`<br>`Illuminate\Routing\Route` | Replace invented noun `path` with native `uri`; replace 16 static `#[Builder]` properties with dynamic dispatch; support HTTP verb keys and `Router::group()`. |
| **Phase 6.3** | Tier 1 API Map | **Query Dynamic Dispatch & Attribute Elimination (`queries:`)** | `Illuminate\Database\Eloquent\Builder` | Eliminate 11 synthetic attribute classes (`Clause`, `Where`, `Spread`, `Flag`, etc.); dynamic dispatch on `Builder`; eliminate silent skips; replace hijacked `from` noun; decouple route parameters. |
| **Phase 6.4** | Tier 1 API Map | **Provider & View Factory Normalization (`providers:`, `view:`)** | `Illuminate\Foundation\Application`<br>`Illuminate\View\Factory` | Remove dead keys (`bindings`, `singletons`, `factories`, `name`, `description`) from `Provider.php`; align `composer`/`creator` manifest signature to native `$views => $callback`. |
| **Phase 6.5** | Tier 1 API Map | **Database Transactions (`db:`)** | `Illuminate\Database\DatabaseManager` | `db.transaction` mapping for atomic state execution. |
| **Phase 6.6** | Tier 1 API Map | **Redirector & Redirect Responses (`redirect:`)** | `Illuminate\Routing\Redirector` | Native parameter replacement and redirect responses. |
| **Phase 7** | Tier 2 Glue Code | **Declarative Action (`DeclaredAction`)** | `Illuminate\Routing\Controller` | Decoupled state mutation seam utilizing `DB::transaction()` and native `RedirectResponse`. |
| **Phase 7.5** | Tier 1 API Map | **Blade Compiler (`blade:`)** | `Illuminate\View\Compilers\BladeCompiler` | `Blade` DataModel, `BladeDeclarationServiceProvider`, directive/component path mapping. |
| **Phase 7.6** | Tier 1 API Map | **Response Factory (`responses:`)** | `Illuminate\Contracts\Routing\ResponseFactory` | Declarative response status, header, and format defaults. |
| **Phase 7.7** | Tier 1 API Map | **Pagination Engine (`pagination:`)** | `Illuminate\Pagination\Paginator` | Declarative pagination view styling and configuration. |
| **Phase 8** | Tier 2 Glue Code | **Declarative Inline Templates (`DeclaredView`)** | `BladeCompiler::render()` & `ResponseFactory` | Dynamic template dispatch in `DeclaredView`, view composer event bridging. |
| **Phase 8.5** | Tier 1 API Map | **Eloquent Relationships (`models.relations:`)** | `Illuminate\Database\Eloquent\Relations\*` | `belongsTo`, `hasMany`, `belongsToMany` mapping on `Model`. |
| **Phase 9** | Tier 2 Glue Code | **Dynamic Model Synthesis (`DeclaredModel`)** | `Illuminate\Database\Eloquent\Model` | Zero-PHP runtime model synthesis with relational support. |
| **Phase 10** | Tier 1 API Map | **Authorization Gates (`gate:`)** | `Illuminate\Contracts\Auth\Access\Gate` | Declarative ability and policy definitions. |
| **Phase 11** | Tier 1 API Map | **Event Dispatcher (`events:`)** | `Illuminate\Events\Dispatcher` | Declarative listener and subscriber bindings for model and action events. |
| **Phase 12** | Tier 1 API Map | **Rate Limiter (`rate_limiter:`)** | `Illuminate\Cache\RateLimiter` | Declarative named rate limiter definitions (`RateLimiter::for()`). |
| **Phase 13** | Tier 1 API Map | **CSRF & Security (`csrf:`)** | `Illuminate\Foundation\Http\Middleware\ValidateCsrfToken` | Declarative URI exclusions from CSRF verification. |
| **Phase 14** | Tier 1 API Map | **Task Scheduling (`schedule:`)** | `Illuminate\Console\Scheduling\Schedule` | Declarative cron schedules for commands and queued jobs. |
| **Phase 15** | Tier 1 API Map | **Cache & Storage (`cache:`, `storage:`)** | `Illuminate\Cache\CacheManager`<br>`Illuminate\Filesystem\FilesystemManager` | Declarative cache stores and storage disk configurations. |
| **Phase 16** | Tier 1 API Map | **Exception Handling (`exceptions:`)** | `Illuminate\Contracts\Debug\ExceptionHandler` | Declarative exception-to-view and status code mappings. |
