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
- [x] **Service Container** (`Illuminate\Container\Container`, `Illuminate\Foundation\Application`) — `app:`
- [x] **Configuration Repository** (`Illuminate\Config\Repository`) — `config:`
- [x] **Service Providers** (`Illuminate\Support\ServiceProvider`) — `providers:`

### Domain 2: HTTP Kernel & Middleware Pipeline
- [x] **HTTP Kernel & Middleware Pipeline** (`Illuminate\Foundation\Http\Kernel`, `Illuminate\Routing\Pipeline`) — `kernel:`
- [ ] **CSRF Verification & Route Exclusions** (`Illuminate\Foundation\Http\Middleware\ValidateCsrfToken`) — `csrf:`

### Domain 3: HTTP Routing, Pipeline, URLs & Throttling
- [x] **Router Configuration & Binders** (`Illuminate\Routing\Router`) — `router:`
- [/] **Route Registration** (`Illuminate\Routing\Router`, `Illuminate\Routing\Route`) — `routes:` (missing route groups, resource routes, fallbacks)
- [ ] **Native Route Shortcuts (`view` / `redirect`)** (`Illuminate\Routing\Router::view()`, `Router::redirect()`) — `routes.view`, `routes.redirect`
- [ ] **URL Generation & Signed URLs** (`Illuminate\Routing\UrlGenerator`) — `url:`
- [ ] **Rate Limiter** (`Illuminate\Cache\RateLimiter`) — `rate_limiter:`

### Domain 4: View Layer, Blade Engine & Presentation
- [x] **View Factory & Namespaces** (`Illuminate\View\Factory`, `Illuminate\View\FileViewFinder`) — `view:`
- [ ] **Blade Compiler & Directives** (`Illuminate\View\Compilers\BladeCompiler`) — `blade:`
- [ ] **Anonymous Components & Namespaces** (`Illuminate\View\Compilers\BladeCompiler`, `Illuminate\View\Component`) — `blade.components:`
- [ ] **Pagination View Resolvers & Styling** (`Illuminate\Pagination\Paginator`, `Illuminate\Pagination\LengthAwarePaginator`) — `pagination:`
- [/] **View Dispatch Controller** (`Illuminate\Routing\ViewController`) — `DeclaredView` (lacks inline template dynamic dispatch and view composer event bridging)

### Domain 5: Request Lifecycle, Input Resolution & Validation
- [x] **Form Request Declaration** (`Illuminate\Foundation\Http\FormRequest`) — `requests:`
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
- [/] **Database Schema & Blueprint** (`Illuminate\Database\Schema\Builder`, `Illuminate\Database\Schema\Blueprint`) — `schema:` (missing alters, drops, composite indexes, foreign key constraints)
- [ ] **Database Seeding & Factories** (`Illuminate\Database\Seeder`, `Illuminate\Database\Eloquent\Factories\Factory`) — `seeds:`

### Domain 8: Eloquent ORM & Query Builder
- [/] **Eloquent Model Configuration & Lifecycle** (`Illuminate\Database\Eloquent\Model`) — `models:` (missing relations, modern casts, and lifecycle hooks)
- [ ] **Eloquent Relationships** (`Illuminate\Database\Eloquent\Relations\*`) — `models.relations:`
- [ ] **Eloquent Attribute Casts & Mutators** (`Illuminate\Database\Eloquent\Casts\Attribute`, `Illuminate\Contracts\Database\Eloquent\CastsAttributes`) — `models.casts:`
- [x] **Eloquent Query Builder (Model Queries)** (`Illuminate\Database\Eloquent\Builder`) — `queries:`
- [/] **Dynamic Model Synthesis** (`Illuminate\Database\Eloquent\Model`) — `DeclaredModel` (synthesizes isolated non-relational models)

### Domain 9: Security, Identity & Access Control
- [ ] **Authorization Gates & Policies** (`Illuminate\Contracts\Auth\Access\Gate`, `Illuminate\Auth\Access\Gate`) — `gate:`
- [ ] **Authentication Manager & Guards** (`Illuminate\Auth\AuthManager`) — `auth:`
- [!] **Session Store & Flash Data** (`Illuminate\Session\SessionManager`, `Illuminate\Session\Store`) — `session:` (substituted by `FlashAction` glue)
- [ ] **Hashing & Encryption** (`Illuminate\Hashing\HashManager`, `Illuminate\Encryption\Encrypter`) — `hashing:`, `encryption:`

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

---

## 2. Comprehensive Laravel Framework API Mapping (12 Architectural Domains)

Derived from `vendor/laravel/framework/src/Illuminate/` (`laravel/framework` v13.33.0) and `docs/repos/laravel/docs/*.md`.

---

### Domain 1: Core Architecture, Container & Configuration

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Service Container**<br>`docs/repos/laravel/docs/container.md` | `Illuminate\Container\Container`<br>`Illuminate\Foundation\Application` | Tier 1 | **Implemented** | `app:` (`src/App.php`) | **Mapped**: `bind`, `singleton`, `scoped`, `instance`, `alias`, `tag`, `contextual`, `extend`, `terminating`, `resolving`, `afterResolving`.<br>**Gap**: None. | None. Adheres strictly to Rules 1–5. |
| **Configuration**<br>`docs/repos/laravel/docs/configuration.md` | `Illuminate\Config\Repository` | Tier 1 | **Implemented** | `config:` (`ConfigDeclarationServiceProvider`) | **Mapped**: `set($key, $value)`.<br>**Gap**: None. | None. Directly mutates configuration repository. |
| **Service Providers**<br>`docs/repos/laravel/docs/providers.md` | `Illuminate\Support\ServiceProvider` | Tier 1 | **Implemented** | `providers:` (`src/Provider.php`) | **Mapped**: `Application::register($provider)`.<br>**Gap**: Deferred provider boot arguments. | None. Registers native package providers cleanly. |

---

### Domain 2: HTTP Kernel & Middleware Pipeline

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **HTTP Kernel & Middleware**<br>`docs/repos/laravel/docs/middleware.md` | `Illuminate\Foundation\Http\Kernel`<br>`Illuminate\Routing\Pipeline` | Tier 1 | **Implemented** | `kernel:` (`src/Kernel.php`) | **Mapped**: `middleware`, `middlewareGroups`, `middlewareAliases`, `middlewarePriority`, `whenRequestLifecycleIsLongerThan`.<br>**Gap**: None. | None. Pipeline executes via native HTTP kernel. |
| **CSRF Protection**<br>`docs/repos/laravel/docs/csrf.md` | `Illuminate\Foundation\Http\Middleware\ValidateCsrfToken` | Tier 1 | **Missing / Left To Do** | `csrf:` / `kernel.csrf` | **Mapped**: None.<br>**Gap**: URI exclusions (`except: ['api/*', 'stripe/*']`). | Forces manual PHP editing of CSRF middleware exclusions for external webhooks. |

---

### Domain 3: HTTP Routing, Pipeline, URLs & Throttling

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Router Configuration**<br>`docs/repos/laravel/docs/routing.md` | `Illuminate\Routing\Router` | Tier 1 | **Implemented** | `router:` (`src/Router.php`) | **Mapped**: `pattern`, `patterns`, `model`, `bind`.<br>**Gap**: Global group prefixes/domains outside individual route items. | None. Global binders and route parameters bind natively. |
| **Route Registration**<br>`docs/repos/laravel/docs/routing.md` | `Illuminate\Routing\Router`<br>`Illuminate\Routing\Route` | Tier 1 | **Partially Mapped** | `routes:` (`src/Route.php`) | **Mapped**: `addRoute`, `name`, `prefix`, `domain`, `middleware`, `withoutMiddleware`, `can`, `where`, `setDefaults`, `missing`, `fallback`, `scopeBindings`, `withoutScopedBindings`, `withTrashed`, `block`, `withoutBlocking`, `metadata`.<br>**Gap**: Native `Router::redirect()`, `Router::view()`, `Router::group()`, `Router::resource()`, `Router::apiResource()`. | Static views and simple redirects are routed through heavy custom seam controllers (`DeclaredView`, `DeclaredAction`). |
| **Native Route Shortcuts**<br>`docs/repos/laravel/docs/routing.md` | `Illuminate\Routing\Router::view()`<br>`Illuminate\Routing\Router::redirect()` | Tier 1 | **Missing / Left To Do** | `routes:` (`view`, `redirect`) | **Mapped**: None.<br>**Gap**: Invoking native `Router::view()` and `Router::redirect()` directly when routes require no dynamic queries or actions. | Forces static views and simple redirects through heavy custom controllers (`DeclaredView`, `DeclaredAction`). |
| **URL Generation**<br>`docs/repos/laravel/docs/urls.md` | `Illuminate\Routing\UrlGenerator` | Tier 1 | **Missing / Left To Do** | `url:` | **Mapped**: None.<br>**Gap**: `signedRoute()`, `temporarySignedRoute()`, `forceScheme()`, `forceRootUrl()`, `defaults()`. | Forces URL signing and default scheme logic into bespoke middleware or closures. |
| **Rate Limiter**<br>`docs/repos/laravel/docs/rate-limiting.md` | `Illuminate\Cache\RateLimiter` | Tier 1 | **Missing / Left To Do** | `rate_limiter:` | **Mapped**: None.<br>**Gap**: `RateLimiter::for($name, Closure)`, `attempt()`. | Limits declarative routes to standard throttle strings without custom keyed rate limiters. |

---

### Domain 4: View Layer, Blade Engine, Components & Pagination

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **View Factory**<br>`docs/repos/laravel/docs/views.md` | `Illuminate\View\Factory`<br>`Illuminate\View\FileViewFinder` | Tier 1 | **Implemented** | `view:` (`src/View.php`) | **Mapped**: `addLocation`, `prependLocation`, `addNamespace`, `prependNamespace`, `replaceNamespace`, `addExtension`, `share`, `composer`, `creator`.<br>**Gap**: Virtual view registration (`registerView()`). | View lookup and shared data are fully native. |
| **Blade Compiler**<br>`docs/repos/laravel/docs/blade.md` | `Illuminate\View\Compilers\BladeCompiler`<br>`Illuminate\View\Component` | Tier 1 | **Missing / Left To Do** | `blade:` | **Mapped**: None.<br>**Gap**: `directive()`, `if()`, `component()`, `components()`, `anonymousComponentPath()`, `anonymousComponentNamespace()`, `stringable()`, `precompiler()`, `withoutDoubleEncoding()`. | **Critical Shortcut**: Inline templates in `DeclaredView` cannot resolve anonymous components (`<x-layout>`) or custom directives (`@datetime`), forcing template rendering into an isolated component sandbox. |
| **Pagination Engine**<br>`docs/repos/laravel/docs/pagination.md` | `Illuminate\Pagination\Paginator`<br>`Illuminate\Pagination\LengthAwarePaginator` | Tier 1 | **Missing / Left To Do** | `pagination:` | **Mapped**: None.<br>**Gap**: `defaultView()`, `defaultSimpleView()`, `useTailwind()`, `useBootstrapFive()`. | Paginated query results rendered in views cannot declare standard framework styling declaratively. |
| **View Dispatch Controller**<br>`docs/repos/laravel/docs/views.md` | `Illuminate\Routing\ViewController` | Tier 2 | **Partially Mapped** | `DeclaredView` (`src/DeclaredView.php`) | **Mapped**: Extends `ViewController::__invoke()`, merges query results and route parameters into `$args['data']`.<br>**Gap**: Dynamic dispatch to `BladeCompiler::render()`, view composer event bridging. | Does not support inline templates yet. |

---

### Domain 5: Request Lifecycle, Input Resolution & Validation

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Form Request Declaration**<br>`docs/repos/laravel/docs/requests.md` | `Illuminate\Foundation\Http\FormRequest` | Tier 1 | **Implemented** | `requests:` (`src/Request.php`) | **Mapped**: `rules`, `messages`, `attributes`, `stopOnFirstFailure`, `redirect`, `redirectRoute`, `errorBag`.<br>**Gap**: Dynamic conditional rules (`sometimes`). | Request definitions map 1:1 onto `FormRequest` properties and methods. |
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
| **Database Schema & Blueprint**<br>`docs/repos/laravel/docs/migrations.md` | `Illuminate\Database\Schema\Builder`<br>`Illuminate\Database\Schema\Blueprint` | Tier 1 | **Partially Mapped** | `schema:` (`src/Schema.php`, `TableDefinition.php`) | **Mapped**: `id`, `string`, `text`, `boolean`, `integer`, `foreignId`, `timestamps`, basic modifiers (`nullable`, `default`, `unique`).<br>**Gap**: Table alters, drops (`dropIfExists`), composite indexes, foreign key constraints (`constrained()`, `cascadeOnDelete()`), table options (engine, collation). | Complex schema mutations require manual migrations. |
| **Database Seeding & Factories**<br>`docs/repos/laravel/docs/seeding.md` | `Illuminate\Database\Seeder`<br>`Illuminate\Database\Eloquent\Factories\Factory` | Tier 1 | **Missing / Left To Do** | `seeds:` | **Mapped**: None.<br>**Gap**: Declarative table record insertion or factory sequence definitions. | Declarative applications have no native way to seed baseline database state. |

---

### Domain 8: Eloquent ORM, Relationships, Casts & Synthesis

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Eloquent Model Configuration**<br>`docs/repos/laravel/docs/eloquent.md` | `Illuminate\Database\Eloquent\Model` | Tier 1 | **Partially Mapped** | `models:` (`src/Model.php`) | **Mapped**: `table`, `primaryKey`, `incrementing`, `timestamps`, `casts`, `fillable`, `guarded`, `hidden`, `visible`, `appends`, `observe`, `addGlobalScope`, `getRouteKeyName`.<br>**Gap**: Relationships, modern `casts()` array methods, custom Cast classes, lifecycle event bindings (`dispatchesEvents`). | Model property defaults map 1:1, but models cannot declare relations or modern casts. |
| **Eloquent Relationships**<br>`docs/repos/laravel/docs/eloquent-relationships.md` | `Illuminate\Database\Eloquent\Relations\*` | Tier 1 | **Missing / Left To Do** | `models.relations:` | **Mapped**: None.<br>**Gap**: `hasOne`, `hasMany`, `belongsTo`, `belongsToMany`, `hasManyThrough`, `morphTo`, `morphMany`. | **Critical Blocker**: Dynamic model synthesis cannot create relational models without relationship mappings. |
| **Eloquent Attribute Casts & Mutators**<br>`docs/repos/laravel/docs/eloquent-mutators.md` | `Illuminate\Database\Eloquent\Casts\Attribute`<br>`Illuminate\Contracts\Database\Eloquent\CastsAttributes` | Tier 1 | **Missing / Left To Do** | `models.casts:` | **Mapped**: Property string casts.<br>**Gap**: Modern `casts()` method array definitions, custom Cast classes, and accessor/mutator callables. | Model attribute conversions are limited to primitive scalar types. |
| **Eloquent Query Builder**<br>`docs/repos/laravel/docs/queries.md` | `Illuminate\Database\Eloquent\Builder` | Tier 1 | **Implemented** | `queries:` (`src/Query.php`) | **Mapped**: 40+ methods (`where`, `with`, `latest`, `limit`, `paginate`, `get`, `first`, etc.).<br>**Gap**: Subquery closures, raw SQL expressions (`whereRaw`, `selectRaw`). | Adheres strictly to Rule 1 (`Key = method name`). |
| **Dynamic Query Seam**<br>`docs/declarative-query.md` | `Illuminate\Database\Eloquent\Builder` | Tier 2 | **Implemented** | `DeclaredQuery` (`src/DeclaredQuery.php`) | **Mapped**: Evaluates query definition, binds request parameters, and executes query against model. | Connects route requests to Eloquent execution cleanly. |
| **Dynamic Model Synthesis**<br>`docs/declarative-model.md` | `Illuminate\Database\Eloquent\Model` | Tier 2 | **Partially Mapped** | `DeclaredModel` (`src/DeclaredModel.php`) | **Mapped**: Synthesizes runtime classes extending `Model`.<br>**Gap**: Synthesizing relational methods (`belongsTo()`, `hasMany()`). | Relies on Tier 1 relationship mapping to support relational models. |

---

### Domain 9: Security, Identity, Authentication & Authorization Gates

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Authorization Gate**<br>`docs/repos/laravel/docs/authorization.md` | `Illuminate\Contracts\Auth\Access\Gate`<br>`Illuminate\Auth\Access\Gate` | Tier 1 | **Missing / Left To Do** | `gate:` | **Mapped**: None.<br>**Gap**: `define()`, `policy()`, `before()`, `after()`, `resource()`, `authorize()`. | Forces route authorization to rely on hardcoded booleans or hand-written request classes rather than native Gates/Policies. |
| **Authentication Guards**<br>`docs/repos/laravel/docs/authentication.md` | `Illuminate\Auth\AuthManager` | Tier 1 | **Missing / Left To Do** | `auth:` | **Mapped**: None.<br>**Gap**: `guard()`, `provider()`, `shouldUse()`, default driver selection. | Authentication guard definitions must be configured via PHP config files. |
| **Session Manager & Store**<br>`docs/repos/laravel/docs/session.md` | `Illuminate\Session\SessionManager`<br>`Illuminate\Session\Store` | Tier 1 | **Missing / Left To Do** | `session:` | **Mapped**: None.<br>**Gap**: `flash()`, `now()`, `reflash()`, `keep()`, `put()`, `get()`. | `DeclaredAction` created bespoke `FlashAction` attribute instead of using `Session::flash()`. |
| **Hashing & Encryption**<br>`docs/repos/laravel/docs/hashing.md`<br>`encryption.md` | `Illuminate\Hashing\HashManager`<br>`Illuminate\Encryption\Encrypter` | Tier 1 | **Missing / Left To Do** | `hashing:`, `encryption:` | **Mapped**: None.<br>**Gap**: `make()`, `encrypt()`, `decrypt()`. | Driver choices and key configurations cannot be set via YAML. |

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
