# Declarative Framework API Mapping & Gap Analysis — Architecture Audit, Tier Separation & Inline Template Roadmap

Source of truth: `vendor/laravel/framework/src/Illuminate` (`laravel/framework` v13.33.0), official Laravel documentation repository (`docs/repos/laravel/docs/*.md`), and package specifications (`docs/declarative-*.md`).

This document provides an exhaustive architectural audit of the Laravel framework API against the current declaration engine (`laravel-declaration`). It formalizes the strict architectural boundary between the **Laravel API Map (Tier 1)** and **Declarative Seams / Glue Code (Tier 2)**, identifies architectural shortcuts caused by incomplete framework mappings, maps all framework subsystems showing completed versus remaining components across 12 architectural domains, and delivers a concrete recommendation to advance towards `docs/declarative-inline-template.md`.

---

## Framework API Implementation Checklist Roadmap

Check (`[x]`) indicates implemented in package source (`src/`) with verified feature tests. Unchecked (`[ ]`) indicates left to do.

### Core Architecture, Container & Configuration
- [x] **Service Container** (`Illuminate\Container\Container`, `Illuminate\Foundation\Application`) — `app:`
- [x] **Configuration Repository** (`Illuminate\Config\Repository`) — `config:`
- [x] **Service Providers** (`Illuminate\Support\ServiceProvider`) — `providers:`
- [x] **HTTP Kernel & Middleware Pipeline** (`Illuminate\Foundation\Http\Kernel`, `Illuminate\Routing\Pipeline`) — `kernel:`

### HTTP Routing, Pipeline, URLs & Throttling
- [x] **Router Configuration & Binders** (`Illuminate\Routing\Router`) — `router:`
- [x] **Route Registration** (`Illuminate\Routing\Router`, `Illuminate\Routing\Route`) — `routes:`
- [ ] **Native Route Shortcuts (`view` / `redirect`)** (`Illuminate\Routing\Router::view()`, `Router::redirect()`) — `routes.view`, `routes.redirect`
- [ ] **URL Generation & Signed URLs** (`Illuminate\Routing\UrlGenerator`) — `url:`
- [ ] **Rate Limiter** (`Illuminate\Cache\RateLimiter`) — `rate_limiter:`
- [ ] **CSRF Verification & Route Exclusions** (`Illuminate\Foundation\Http\Middleware\ValidateCsrfToken`) — `csrf:`

### View Layer, Blade Engine & Presentation
- [x] **View Factory & Namespaces** (`Illuminate\View\Factory`, `Illuminate\View\FileViewFinder`) — `view:`
- [ ] **Blade Compiler & Directives** (`Illuminate\View\Compilers\BladeCompiler`) — `blade:`
- [ ] **Anonymous Components & Namespaces** (`Illuminate\View\Compilers\BladeCompiler`, `Illuminate\View\Component`) — `blade.components:`
- [ ] **Pagination View Resolvers & Styling** (`Illuminate\Pagination\Paginator`, `Illuminate\Pagination\LengthAwarePaginator`) — `pagination:`

### Request Lifecycle, Input Resolution & Validation
- [x] **Form Request Declaration** (`Illuminate\Foundation\Http\FormRequest`) — `requests:`
- [ ] **Validation Factory & Custom Rules** (`Illuminate\Validation\Factory`, `Illuminate\Contracts\Validation\ValidationRule`) — `validator:`

### Response Generation, Redirects & Transport
- [ ] **Response Factory & Macros** (`Illuminate\Contracts\Routing\ResponseFactory`, `Illuminate\Routing\ResponseFactory`) — `responses:`
- [ ] **Redirector & Redirect Responses** (`Illuminate\Routing\Redirector`, `Illuminate\Http\RedirectResponse`, `Illuminate\Routing\RedirectController`) — `redirect:`
- [ ] **Cookies & Cookie Jar** (`Illuminate\Cookie\CookieJar`) — `cookie:`
- [ ] **API Resources & JSON Serialization** (`Illuminate\Http\Resources\Json\JsonResource`) — `resources:`

### Database Connection, Transactions, Querying & Schema
- [ ] **Database Connection & Transactions** (`Illuminate\Database\DatabaseManager`, `Illuminate\Database\Connection`) — `db:`
- [ ] **Database Query Builder (Table-Level Queries)** (`Illuminate\Database\Query\Builder`) — `db_queries:`
- [x] **Database Schema & Blueprint** (`Illuminate\Database\Schema\Builder`, `Illuminate\Database\Schema\Blueprint`) — `schema:`
- [ ] **Database Seeding & Test Data** (`Illuminate\Database\Seeder`, `Illuminate\Database\Eloquent\Factories\Factory`) — `seeds:`

### Eloquent ORM & Query Builder
- [x] **Eloquent Model Configuration & Lifecycle** (`Illuminate\Database\Eloquent\Model`) — `models:`
- [ ] **Eloquent Relationships** (`Illuminate\Database\Eloquent\Relations\*`) — `models.relations:`
- [ ] **Eloquent Attribute Casts & Mutators** (`Illuminate\Database\Eloquent\Casts\Attribute`, `Illuminate\Contracts\Database\Eloquent\CastsAttributes`) — `models.casts:`
- [x] **Eloquent Query Builder (Model Queries)** (`Illuminate\Database\Eloquent\Builder`) — `queries:`

### Security, Identity & Access Control
- [ ] **Authorization Gates & Policies** (`Illuminate\Contracts\Auth\Access\Gate`, `Illuminate\Auth\Access\Gate`) — `gate:`
- [ ] **Authentication Manager & Guards** (`Illuminate\Auth\AuthManager`) — `auth:`
- [ ] **Session Store & Flash Data** (`Illuminate\Session\SessionManager`, `Illuminate\Session\Store`) — `session:`
- [ ] **Hashing & Encryption** (`Illuminate\Hashing\HashManager`, `Illuminate\Encryption\Encrypter`) — `hashing:`, `encryption:`

### Events, Async & Realtime Systems
- [ ] **Events & Dispatcher** (`Illuminate\Events\Dispatcher`) — `events:`
- [ ] **Queues, Workers & Bus** (`Illuminate\Queue\QueueManager`, `Illuminate\Bus\Dispatcher`) — `queues:`, `bus:`
- [ ] **Mail & Mailables** (`Illuminate\Mail\MailManager`) — `mail:`
- [ ] **Notifications & Channels** (`Illuminate\Notifications\ChannelManager`) — `notifications:`
- [ ] **Broadcasting & WebSockets** (`Illuminate\Broadcasting\BroadcastManager`) — `broadcasting:`

### Operations, Console, Storage & Systems
- [ ] **Artisan Console Commands** (`Illuminate\Console\Application`) — `commands:`
- [ ] **Task Scheduling** (`Illuminate\Console\Scheduling\Schedule`) — `schedule:`
- [ ] **Cache Repository & Stores** (`Illuminate\Cache\CacheManager`, `Illuminate\Contracts\Cache\Repository`) — `cache:`
- [ ] **Filesystem & Storage Disks** (`Illuminate\Filesystem\FilesystemManager`) — `storage:`
- [ ] **Localization & Translation Loader** (`Illuminate\Translation\Translator`) — `lang:`
- [ ] **Logging & Context Repository** (`Illuminate\Log\LogManager`, `Illuminate\Log\Context\Repository`) — `logging:`, `context:`
- [ ] **Processes & Concurrency** (`Illuminate\Process\Factory`, `Illuminate\Concurrency\ConcurrencyManager`) — `process:`, `concurrency:`
- [ ] **HTTP Client Factory** (`Illuminate\Http\Client\Factory`) — `http:`
- [ ] **Exception Handling & Reporting** (`Illuminate\Contracts\Debug\ExceptionHandler`) — `exceptions:`
- [ ] **Feature Flags** (`Laravel\Pennant\FeatureManager`) — `features:`

---

## 1. Architectural Foundation: Tier 1 (API Map) vs. Tier 2 (Glue Code)

To preserve architectural integrity, eliminate shortcuts, and adhere strictly to framework principles, the package architecture is separated into two distinct tiers:

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

### 1.1 The "Composite Seam Shortcut" Anti-Pattern

When a native Laravel subsystem is omitted from **Tier 1 (API Map)**, downstream features face an architectural void. Historically, this void was bypassed by adding bespoke procedural logic and custom attribute classes into **Tier 2 (Glue Code)**. 

This creates the **Composite Seam Shortcut** anti-pattern:
1. **The Blade Compiler Bypass (`docs/declarative-inline-template.md`)**:
   `Illuminate\View\Compilers\BladeCompiler` was omitted from Tier 1. Consequently, `docs/declarative-inline-template.md` bypassed the compiler registry, invented a custom `RenderAction` attribute class (`RENDERERS = ['template', 'view']`), and invoked `Blade::render()` directly in an isolated component sandbox. This severed integration with `view.composer`, prevented declarative Blade directives (`@datetime`), and blocked anonymous component path registration (`<x-layout>`).
2. **The Composite Action Monolith (`docs/declarative-action.md`)**:
   `Illuminate\Routing\RedirectController`, `Illuminate\Contracts\Routing\ResponseFactory`, `Illuminate\Database\DatabaseManager` (transactions), and `Illuminate\Events\Dispatcher` were omitted from Tier 1. Consequently, `DeclaredAction` became a bloated controller that hand-rolled URL parameter string interpolation (`RedirectAction`), session flash arrays (`FlashAction`), un-transactioned model mutations (`Mutation`), and lacked domain event dispatching.
3. **The Relational Eloquent Void (`docs/declarative-model.md`)**:
   Eloquent relationship methods (`hasMany`, `belongsTo`, `belongsToMany`) were omitted from `models:` in Tier 1. Consequently, dynamic model synthesis (`DeclaredModel`) produced isolated, non-relational database models.
4. **The Gate Authorization Bypass (`docs/declarative-requests.md`)**:
   `Illuminate\Contracts\Auth\Access\Gate` was omitted from Tier 1, forcing request authorization in `DeclaredRequest` to rely on ad-hoc boolean flags or custom PHP classes rather than declarative policy evaluation.
5. **The Rate Limiter Void (`docs/declarative-routing.md`)**:
   `Illuminate\Cache\RateLimiter` was omitted from Tier 1, forcing routes to rely solely on hardcoded string limits (`throttle:60,1`) rather than declarative named rate limiters (`RateLimiter::for('api', ...)`).
6. **The Native Route Bypass (`docs/declarative-routing.md`)**:
   Native `Router::view()` and `Router::redirect()` were omitted from `routes:` in Tier 1, forcing every static view and simple redirect to pass through heavy seam controllers (`DeclaredView`, `DeclaredAction`) rather than executing via Laravel's native, optimized routing engine.
7. **The Pagination Rendering Void (`docs/declarative-query.md`)**:
   `Illuminate\Pagination\Paginator` view resolvers were omitted from Tier 1, preventing inline templates and views from configuring pagination styling (`useTailwind()`, `defaultView()`) declaratively.

**Architectural Rule**: *Every framework capability must be cleanly mapped in Tier 1 before Tier 2 glue code orchestrates it.*

---

## 2. Complete Laravel Framework API Mapping

The tables below map the entire Laravel framework API (derived from `docs/repos/laravel/docs/*.md` and `vendor/laravel/framework/src/Illuminate/`), detailing the **system of record**, architecture tier, implementation status, manifest key, mapped methods, unmapped gaps, and shortcut risks.

### Status Definitions:
- **Implemented**: Fully mapped via dedicated DataModel, manifest key, service provider loop, and feature test suite in package source (`src/`).
- **Active Scope**: Identified in current roadmap specifications with active design.
- **Left To Do (Tier 1 API Map)**: Native Laravel class/registry requiring direct 1:1 declarative mapping.
- **Left To Do (Tier 2 Glue Code)**: Seam integration logic orchestrating mapped Tier 1 components.

---

### Domain 1: Core Architecture, Container & Configuration

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Service Container**<br>`docs/repos/laravel/docs/container.md` | `Illuminate\Container\Container`<br>`Illuminate\Foundation\Application` | Tier 1 | **Implemented** | `app:` (`src/App.php`) | **Mapped**: `bind`, `singleton`, `scoped`, `instance`, `alias`, `tag`, `contextual`, `extend`, `terminating`, `resolving`, `afterResolving`.<br>**Gap**: None. Adheres strictly to Rules 1–5. | None. Container resolution operates identically to native Laravel. |
| **Configuration**<br>`docs/repos/laravel/docs/configuration.md` | `Illuminate\Config\Repository` | Tier 1 | **Implemented** | `config:` (`ConfigDeclarationServiceProvider`) | **Mapped**: `set($key, $value)`.<br>**Gap**: None. | None. Config keys mutate repository directly. |
| **Service Providers**<br>`docs/repos/laravel/docs/providers.md` | `Illuminate\Support\ServiceProvider` | Tier 1 | **Implemented** | `providers:` (`src/Provider.php`) | **Mapped**: `Application::register($provider)`.<br>**Gap**: Deferred provider boot arguments. | None. Registers native package providers cleanly. |

---

### Domain 2: HTTP Kernel & Middleware Pipeline

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **HTTP Kernel & Middleware**<br>`docs/repos/laravel/docs/middleware.md` | `Illuminate\Foundation\Http\Kernel`<br>`Illuminate\Routing\Pipeline` | Tier 1 | **Implemented** | `kernel:` (`src/Kernel.php`) | **Mapped**: `middleware`, `middlewareGroups`, `middlewareAliases`, `middlewarePriority`, `whenRequestLifecycleIsLongerThan`.<br>**Gap**: None. | None. Middleware pipeline runs through native HTTP kernel. |
| **CSRF Protection**<br>`docs/repos/laravel/docs/csrf.md` | `Illuminate\Foundation\Http\Middleware\ValidateCsrfToken` | Tier 1 | **Left To Do (Tier 1 API Map)** | `csrf:` / `kernel.csrf` | **Mapped**: None.<br>**Gap**: Declarative URI exclusions (`except: ['api/*', 'stripe/*']`). | Forces manual PHP editing of CSRF middleware exclusions for external webhooks. |

---

### Domain 3: HTTP Routing, Pipeline, URLs & Throttling

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Router Configuration**<br>`docs/repos/laravel/docs/routing.md` | `Illuminate\Routing\Router` | Tier 1 | **Implemented** | `router:` (`src/Router.php`) | **Mapped**: `pattern`, `patterns`, `model`, `bind`.<br>**Gap**: Global group prefixes/domains outside route items. | None. Global binders and route parameters bind natively. |
| **Route Registration**<br>`docs/repos/laravel/docs/routing.md` | `Illuminate\Routing\Router`<br>`Illuminate\Routing\Route` | Tier 1 | **Implemented** | `routes:` (`src/Route.php`) | **Mapped**: `addRoute`, `middleware`, `name`, `where`, `setDefaults`, `domain`, `prefix`.<br>**Gap**: Native `Router::redirect()` and `Router::view()` bypassed. | Routes register via native `addRoute()` with standard defaults. |
| **Native Route Shortcuts**<br>`docs/repos/laravel/docs/routing.md` | `Illuminate\Routing\Router::view()`<br>`Illuminate\Routing\Router::redirect()` | Tier 1 | **Left To Do (Tier 1 API Map)** | `routes:` (`view`, `redirect`) | **Mapped**: None.<br>**Gap**: Invoking native `Router::view()` and `Router::redirect()` directly when routes require no dynamic queries or actions. | Forces static views and simple redirects through heavy custom controllers (`DeclaredView`, `DeclaredAction`). |
| **URL Generation**<br>`docs/repos/laravel/docs/urls.md` | `Illuminate\Routing\UrlGenerator` | Tier 1 | **Left To Do (Tier 1 API Map)** | `url:` | **Mapped**: None.<br>**Gap**: `signedRoute()`, `temporarySignedRoute()`, `forceScheme()`, `forceRootUrl()`, `defaults()`. | Forces URL signing and default scheme logic into bespoke middleware or closures. |
| **Rate Limiter**<br>`docs/repos/laravel/docs/rate-limiting.md` | `Illuminate\Cache\RateLimiter` | Tier 1 | **Left To Do (Tier 1 API Map)** | `rate_limiter:` | **Mapped**: None.<br>**Gap**: `RateLimiter::for($name, Closure)`, `attempt()`. | Limits declarative routes to standard throttle strings without custom keyed rate limiters. |

---

### Domain 4: View Layer, Blade Engine, Components & Pagination

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **View Factory**<br>`docs/repos/laravel/docs/views.md` | `Illuminate\View\Factory`<br>`Illuminate\View\FileViewFinder` | Tier 1 | **Implemented** | `view:` (`src/View.php`) | **Mapped**: `addLocation`, `prependLocation`, `addNamespace`, `prependNamespace`, `replaceNamespace`, `addExtension`, `share`, `composer`, `creator`.<br>**Gap**: Virtual view registration (`registerView()`). | View lookup and shared data are fully native. |
| **Blade Compiler**<br>`docs/repos/laravel/docs/blade.md` | `Illuminate\View\Compilers\BladeCompiler`<br>`Illuminate\View\Component` | Tier 1 | **Left To Do (Tier 1 API Map)** | `blade:` | **Mapped**: None.<br>**Gap**: `directive()`, `if()`, `component()`, `components()`, `anonymousComponentPath()`, `anonymousComponentNamespace()`, `stringable()`, `precompiler()`, `withoutDoubleEncoding()`. | **Critical Shortcut**: Inline templates in `DeclaredView` cannot resolve anonymous components (`<x-layout>`) or custom directives (`@datetime`), forcing template rendering into an isolated component sandbox. |
| **Pagination Engine**<br>`docs/repos/laravel/docs/pagination.md` | `Illuminate\Pagination\Paginator`<br>`Illuminate\Pagination\LengthAwarePaginator` | Tier 1 | **Left To Do (Tier 1 API Map)** | `pagination:` | **Mapped**: None.<br>**Gap**: `defaultView()`, `defaultSimpleView()`, `useTailwind()`, `useBootstrapFive()`. | Paginated query results rendered in views cannot declare standard framework styling declaratively. |
| **View Dispatch Controller**<br>`docs/repos/laravel/docs/views.md` | `Illuminate\Routing\ViewController` | Tier 2 | **Implemented** | `DeclaredView` (`src/DeclaredView.php`) | **Mapped**: Extends `ViewController::__invoke()`, merges query results and route parameters into `$args['data']`.<br>**Gap**: Bridging inline templates into named view events. | Integrates `queries:` and route defaults cleanly into view rendering. |
| **Inline Template Dispatch**<br>`docs/declarative-inline-template.md` | `Illuminate\View\Compilers\BladeCompiler::render()` | Tier 2 | **Active Scope (Phase 8)** | `DeclaredView` (`template:`) | **Mapped**: None.<br>**Gap**: Dynamic dispatch to `BladeCompiler::render()` while bridging `view.composer` and component resolution. | Skipping Tier 1 `blade:` mapping severs anonymous component loading and view composers. |

---

### Domain 5: Request Lifecycle, Input Resolution & Validation

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Form Request Declaration**<br>`docs/repos/laravel/docs/requests.md` | `Illuminate\Foundation\Http\FormRequest` | Tier 1 | **Implemented** | `requests:` (`src/Request.php`) | **Mapped**: `rules`, `messages`, `attributes`, `stopOnFirstFailure`, `redirect`, `redirectRoute`, `errorBag`.<br>**Gap**: Dynamic conditional rules (`sometimes`). | Request definitions map 1:1 onto `FormRequest` properties and methods. |
| **Validation Factory**<br>`docs/repos/laravel/docs/validation.md` | `Illuminate\Validation\Factory`<br>`Illuminate\Contracts\Validation\ValidationRule` | Tier 1 | **Left To Do (Tier 1 API Map)** | `validator:` | **Mapped**: None.<br>**Gap**: `extend()`, `extendImplicit()`, `extendDependent()`, `replacer()`, custom `Rule` object bindings. | Prevents declaring custom validation rules directly in YAML; forces PHP service providers. |
| **Form Request Seam**<br>`docs/repos/laravel/docs/requests.md` | `Illuminate\Foundation\Http\FormRequest` | Tier 2 | **Implemented** | `DeclaredRequest` (`src/DeclaredRequest.php`) | **Mapped**: Validates resolved inbound request against manifest definition; fails with standard `ValidationException`.<br>**Gap**: Policy-based authorization hooks. | Integrates declarative validation cleanly into route pipeline. |

---

### Domain 6: Response Generation, Redirects, Cookies & API Resources

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Response Factory**<br>`docs/repos/laravel/docs/responses.md` | `Illuminate\Contracts\Routing\ResponseFactory`<br>`Illuminate\Routing\ResponseFactory` | Tier 1 | **Left To Do (Tier 1 API Map)** | `responses:` | **Mapped**: None.<br>**Gap**: `make()`, `view()`, `json()`, `noContent()`, `stream()`, `download()`, `macro()`. | **Shortcut**: `DeclaredView` and `DeclaredAction` hardcode response creation instead of delegating to mapped response formatters. |
| **Redirector & Redirect Response**<br>`docs/repos/laravel/docs/responses.md` | `Illuminate\Routing\Redirector`<br>`Illuminate\Http\RedirectResponse`<br>`Illuminate\Routing\RedirectController` | Tier 1 | **Left To Do (Tier 1 API Map)** | `redirect:` | **Mapped**: None.<br>**Gap**: `route()`, `to()`, `back()`, `away()`, `with()`, `withCookies()`, `withInput()`, `withErrors()`, URL parameter substitution. | **Shortcut**: `DeclaredAction` hand-rolled `RedirectAction` and `FlashAction` attribute classes instead of delegating to native `Redirector`. |
| **Cookie Jar**<br>`docs/repos/laravel/docs/responses.md` | `Illuminate\Cookie\CookieJar` | Tier 1 | **Left To Do (Tier 1 API Map)** | `cookie:` | **Mapped**: None.<br>**Gap**: `make()`, `forever()`, `forget()`, queueing cookies. | Outgoing cookies must be attached procedurally via PHP middleware. |
| **API Resources**<br>`docs/repos/laravel/docs/eloquent-resources.md` | `Illuminate\Http\Resources\Json\JsonResource` | Tier 1 | **Left To Do (Tier 1 API Map)** | `resources:` | **Mapped**: None.<br>**Gap**: Declarative model-to-JSON transformations and collection wrapping. | RESTful API routes must return raw models or hand-written Resource classes. |
| **Action Controller Seam**<br>`docs/declarative-action.md` | `Illuminate\Routing\Controller` | Tier 2 | **Implemented** | `DeclaredAction` (`src/DeclaredAction.php`) | **Mapped**: Invokes mutation, generates redirect, applies session flash.<br>**Gap**: Decoupling mutations from redirects; wrapping in transactions. | Bundles model writing, redirection, and session flashing into an un-transactioned composite controller. |

---

### Domain 7: Database Connection, Query Builder, Transactions & Seeding

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Database Connection & Transactions**<br>`docs/repos/laravel/docs/database.md` | `Illuminate\Database\DatabaseManager`<br>`Illuminate\Database\Connection` | Tier 1 | **Left To Do (Tier 1 API Map)** | `db:` | **Mapped**: None.<br>**Gap**: `transaction()`, `statement()`, `unprepared()`, `listen()`, `beforeExecuting()`. | **Critical Shortcut**: `DeclaredAction` executes model mutations without database transaction boundaries. If post-write logic fails, partial writes persist. |
| **Database Query Builder**<br>`docs/repos/laravel/docs/queries.md` | `Illuminate\Database\Query\Builder` | Tier 1 | **Left To Do (Tier 1 API Map)** | `db_queries:` | **Mapped**: None.<br>**Gap**: Table-level direct queries (`DB::table(...)`) bypassing Eloquent models. | `queries:` is forced to bind strictly to Eloquent classes, preventing lightweight raw table reads. |
| **Database Seeding**<br>`docs/repos/laravel/docs/seeding.md` | `Illuminate\Database\Seeder`<br>`Illuminate\Database\Eloquent\Factories\Factory` | Tier 1 | **Left To Do (Tier 1 API Map)** | `seeds:` | **Mapped**: None.<br>**Gap**: Declarative table record insertion or factory sequence definitions. | Declarative applications have no native way to seed baseline database state. |

---

### Domain 8: Database Migrations & Schema Blueprint

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Schema Builder & Blueprint**<br>`docs/repos/laravel/docs/migrations.md` | `Illuminate\Database\Schema\Builder`<br>`Illuminate\Database\Schema\Blueprint` | Tier 1 | **Implemented** | `schema:` (`src/Schema.php`, `TableDefinition.php`) | **Mapped**: `id`, `string`, `text`, `boolean`, `integer`, `foreignId`, `timestamps`, modifiers (`nullable`, `default`, `unique`).<br>**Gap**: Table alters, drops (`dropIfExists`), composite indexes, foreign key constraints (`constrained()`, `cascadeOnDelete()`). | Schema declaration maps directly to `Blueprint` methods. |
| **Schema Migration Runner**<br>`docs/declarative-schema.md` | `Illuminate\Database\Schema\Builder::create()` | Tier 2 | **Implemented** | `MigrateCommand` (`declaration:migrate`) | **Mapped**: Reads `schema.tables` from manifest and invokes `Schema::create()` with declared `Blueprint` definitions. | Executes table creation cleanly from manifest specifications. |

---

### Domain 9: Eloquent ORM, Relationships, Casts & Factories

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Eloquent Model Configuration**<br>`docs/repos/laravel/docs/eloquent.md` | `Illuminate\Database\Eloquent\Model` | Tier 1 | **Implemented** | `models:` (`src/Model.php`) | **Mapped**: `table`, `primaryKey`, `incrementing`, `timestamps`, `casts`, `fillable`, `guarded`, `hidden`, `visible`, `appends`, `observe`, `addGlobalScope`, `getRouteKeyName`.<br>**Gap**: Relationship definitions. | Model property defaults map 1:1 onto Eloquent protected properties. |
| **Eloquent Relationships**<br>`docs/repos/laravel/docs/eloquent-relationships.md` | `Illuminate\Database\Eloquent\Relations\*` | Tier 1 | **Left To Do (Tier 1 API Map)** | `models.relations:` | **Mapped**: None.<br>**Gap**: `hasOne`, `hasMany`, `belongsTo`, `belongsToMany`, `hasManyThrough`, `morphTo`, `morphMany`. | **Critical Blocker**: Dynamic model synthesis cannot create relational models without relationship mappings. |
| **Eloquent Attribute Casts & Mutators**<br>`docs/repos/laravel/docs/eloquent-mutators.md` | `Illuminate\Database\Eloquent\Casts\Attribute`<br>`Illuminate\Contracts\Database\Eloquent\CastsAttributes` | Tier 1 | **Left To Do (Tier 1 API Map)** | `models.casts:` | **Mapped**: Property string casts.<br>**Gap**: Modern `casts()` method array definitions, custom Cast classes, and accessor/mutator callables. | Model attribute conversions are limited to primitive scalar types. |
| **Eloquent Query Builder**<br>`docs/repos/laravel/docs/queries.md` | `Illuminate\Database\Eloquent\Builder` | Tier 1 | **Implemented** | `queries:` (`src/Query.php`) | **Mapped**: 40+ methods (`where`, `with`, `latest`, `limit`, `paginate`, `get`, `first`, etc.).<br>**Gap**: Subquery closures, raw SQL expressions (`whereRaw`, `selectRaw`). | Adheres strictly to Rule 1 (`Key = method name`). |
| **Dynamic Query Seam**<br>`docs/declarative-query.md` | `Illuminate\Database\Eloquent\Builder` | Tier 2 | **Implemented** | `DeclaredQuery` (`src/DeclaredQuery.php`) | **Mapped**: Evaluates query definition, binds request parameters, and executes query against model. | Connects route requests to Eloquent execution cleanly. |
| **Dynamic Model Synthesis**<br>`docs/declarative-model.md` | `Illuminate\Database\Eloquent\Model` | Tier 2 | **Implemented** | `DeclaredModel` (`src/DeclaredModel.php`) | **Mapped**: Synthesizes runtime classes extending `Model`.<br>**Gap**: Synthesizing relational methods (`belongsTo()`, `hasMany()`). | Relies on Tier 1 relationship mapping to support relational models. |

---

### Domain 10: Security, Identity, Authentication & Authorization Gates

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Authorization Gate**<br>`docs/repos/laravel/docs/authorization.md` | `Illuminate\Contracts\Auth\Access\Gate`<br>`Illuminate\Auth\Access\Gate` | Tier 1 | **Left To Do (Tier 1 API Map)** | `gate:` | **Mapped**: None.<br>**Gap**: `define()`, `policy()`, `before()`, `after()`, `resource()`, `authorize()`. | Forces route authorization to rely on hardcoded booleans or hand-written request classes rather than native Gates/Policies. |
| **Authentication Guards**<br>`docs/repos/laravel/docs/authentication.md` | `Illuminate\Auth\AuthManager` | Tier 1 | **Left To Do (Tier 1 API Map)** | `auth:` | **Mapped**: None.<br>**Gap**: `guard()`, `provider()`, `shouldUse()`, default driver selection. | Authentication guard definitions must be configured via PHP config files. |
| **Session Manager & Store**<br>`docs/repos/laravel/docs/session.md` | `Illuminate\Session\SessionManager`<br>`Illuminate\Session\Store` | Tier 1 | **Left To Do (Tier 1 API Map)** | `session:` | **Mapped**: None.<br>**Gap**: `flash()`, `now()`, `reflash()`, `keep()`, `put()`, `get()`. | `DeclaredAction` created bespoke `FlashAction` attribute instead of using `Session::flash()`. |
| **Hashing & Encryption**<br>`docs/repos/laravel/docs/hashing.md`<br>`encryption.md` | `Illuminate\Hashing\HashManager`<br>`Illuminate\Encryption\Encrypter` | Tier 1 | **Left To Do (Tier 1 API Map)** | `hashing:`, `encryption:` | **Mapped**: None.<br>**Gap**: `make()`, `encrypt()`, `decrypt()`. | Driver choices and key configurations cannot be set via YAML. |

---

### Domain 11: Events, Listeners & Async Communication

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Event Dispatcher**<br>`docs/repos/laravel/docs/events.md` | `Illuminate\Events\Dispatcher` | Tier 1 | **Left To Do (Tier 1 API Map)** | `events:` | **Mapped**: `models.dispatchesEvents` (partial).<br>**Gap**: `listen()`, `subscribe()`, `dispatch()`, `until()`. | State mutations in `DeclaredAction` cannot dispatch decoupled domain events to notify other subsystems. |
| **Queues & Jobs**<br>`docs/repos/laravel/docs/queues.md` | `Illuminate\Queue\QueueManager`<br>`Illuminate\Bus\Dispatcher` | Tier 1 | **Left To Do (Tier 1 API Map)** | `queues:`, `bus:` | **Mapped**: None.<br>**Gap**: `push()`, `later()`, `dispatch()`, `dispatchSync()`. | Async job dispatching cannot be initiated declaratively. |
| **Mail & Notifications**<br>`docs/repos/laravel/docs/mail.md`<br>`notifications.md` | `Illuminate\Mail\MailManager`<br>`Illuminate\Notifications\ChannelManager` | Tier 1 | **Left To Do (Tier 1 API Map)** | `mail:`, `notifications:` | **Mapped**: None.<br>**Gap**: `send()`, `to()`, notification channel routing. | Transactional emails and notifications must be hand-written in PHP listeners. |
| **Broadcasting**<br>`docs/repos/laravel/docs/broadcasting.md` | `Illuminate\Broadcasting\BroadcastManager` | Tier 1 | **Left To Do (Tier 1 API Map)** | `broadcasting:` | **Mapped**: None.<br>**Gap**: Channel routes, broadcaster driver configuration. | Real-time events cannot be broadcasted declaratively. |

---

### Domain 12: Operations, Console, Storage, Logging & Systems

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps | Shortcut Risk / Impact on Glue Code |
|---|---|---|---|---|---|---|
| **Artisan Console**<br>`docs/repos/laravel/docs/artisan.md` | `Illuminate\Console\Application` | Tier 1 | **Left To Do (Tier 1 API Map)** | `commands:` | **Mapped**: Internal package commands (`declaration:migrate`, etc.).<br>**Gap**: Declarative custom commands (`commands:`). | Custom console commands cannot be registered via YAML. |
| **Task Scheduling**<br>`docs/repos/laravel/docs/scheduling.md` | `Illuminate\Console\Scheduling\Schedule` | Tier 1 | **Left To Do (Tier 1 API Map)** | `schedule:` | **Mapped**: None.<br>**Gap**: `command()`, `job()`, `call()`, `daily()`, `hourly()`. | Recurring cron jobs cannot be scheduled via YAML. |
| **Cache Manager**<br>`docs/repos/laravel/docs/cache.md` | `Illuminate\Cache\CacheManager`<br>`Illuminate\Contracts\Cache\Repository` | Tier 1 | **Left To Do (Tier 1 API Map)** | `cache:` | **Mapped**: None.<br>**Gap**: Declarative query memoization, store configuration, cache tagging. | Query caching must be implemented in hand-written repository scopes. |
| **Filesystem & Storage**<br>`docs/repos/laravel/docs/filesystem.md` | `Illuminate\Filesystem\FilesystemManager` | Tier 1 | **Left To Do (Tier 1 API Map)** | `storage:` | **Mapped**: None.<br>**Gap**: `disk()`, `build()`, disk driver configuration. | File upload destinations must be configured in PHP config files. |
| **Localization & Translation**<br>`docs/repos/laravel/docs/localization.md` | `Illuminate\Translation\Translator` | Tier 1 | **Left To Do (Tier 1 API Map)** | `lang:` | **Mapped**: None.<br>**Gap**: `addLines()`, `addJsonPath()`, `setLocale()`. | View translations rely on physical language files. |
| **Logging & Context**<br>`docs/repos/laravel/docs/logging.md`<br>`context.md` | `Illuminate\Log\LogManager`<br>`Illuminate\Log\Context\Repository` | Tier 1 | **Left To Do (Tier 1 API Map)** | `logging:`, `context:` | **Mapped**: None.<br>**Gap**: `channel()`, `Context::add()`. | Trace IDs and contextual metadata cannot be bound declaratively. |
| **Processes & Concurrency**<br>`docs/repos/laravel/docs/processes.md`<br>`concurrency.md` | `Illuminate\Process\Factory`<br>`Illuminate\Concurrency\ConcurrencyManager` | Tier 1 | **Left To Do (Tier 1 API Map)** | `process:`, `concurrency:` | **Mapped**: None.<br>**Gap**: `run()`, `pool()`, `concurrency()->run()`. | Concurrent asynchronous data resolution is unavailable in YAML. |
| **HTTP Client**<br>`docs/repos/laravel/docs/http-client.md` | `Illuminate\Http\Client\Factory` | Tier 1 | **Left To Do (Tier 1 API Map)** | `http:` | **Mapped**: None.<br>**Gap**: `baseUrl()`, `withHeaders()`, `macro()`. | External API integrations in view data must be hardcoded in PHP service classes. |
| **Exception Handling**<br>`docs/repos/laravel/docs/errors.md` | `Illuminate\Contracts\Debug\ExceptionHandler` | Tier 1 | **Left To Do (Tier 1 API Map)** | `exceptions:` | **Mapped**: None.<br>**Gap**: `renderable()`, `reportable()`, `dontFlash()`. | Uncaught exceptions in seam controllers cannot be mapped to custom declarative error responses. |
| **Feature Flags**<br>`docs/repos/laravel/docs/pennant.md` | `Laravel\Pennant\FeatureManager` | Tier 1 | **Left To Do (Tier 1 API Map)** | `features:` | **Mapped**: None.<br>**Gap**: `define()`, feature-based route middleware. | Declarative routes cannot be toggled conditionally using feature flags. |

---

## 3. Deep Architectural Critique: How Missing API Maps Forced Shortcuts

A critical examination of the current codebase and specifications reveals that every existing shortcut stems directly from skipping Tier 1 API mappings:

### 3.1 The Blade Compiler vs. Inline Template Shortcut (`docs/declarative-inline-template.md`)

In Phase 2, `view:` mapped `Illuminate\View\Factory` (lookup paths, namespaces, composers, creators). However, **`Illuminate\View\Compilers\BladeCompiler` was omitted from Tier 1**.

When `docs/declarative-inline-template.md` (Phase 8) introduced inline template rendering, it jumped straight to calling `Blade::render()` inside `DeclaredView` and invented a bespoke `RenderAction` attribute class:
```php
Blade::render($template, $data, deleteCachedView: true);
```
**Consequences of this shortcut**:
1. **Bypasses View Composers**: `Blade::render()` compiles to a transient anonymous component. Because it is not evaluated as a named view in `ViewFactory`, **view composers registered in `view.composer` never execute**, breaking the view data pipeline established in Phase 2.
2. **Missing Component Paths**: `<x-layout>` or `<x-card>` UI components cannot be registered or resolved because `BladeCompiler::anonymousComponentPath()` is not mapped in Tier 1. Users are forced to write monolithic inline templates rather than modular components.
3. **Missing Custom Directives**: Declarative templates cannot use domain directives (e.g. `@datetime`, `@money`) because `BladeCompiler::directive()` is not mapped.
4. **Invented DSL in Glue Code**: `RenderAction` introduced an ad-hoc renderer list (`['template', 'view']`) instead of relying on Laravel's standard `ResponseFactory` and `BladeCompiler` contracts.

**The Natural Solution**: Map `blade:` directly to `BladeCompiler` in Tier 1. When `DeclaredView` renders an inline template, all components and directives are already registered in the compiler. `DeclaredView` simply triggers the route's named view composer event, compiles via `BladeCompiler::render()`, and returns standard `ResponseFactory::make()`.

---

### 3.2 The `DeclaredAction` Monolith Shortcut (`docs/declarative-action.md`)

In Phase 7, `DeclaredAction` was introduced to handle `POST`, `PATCH`, and `DELETE` requests. Because four foundational subsystems were missing from Tier 1, `DeclaredAction` became a composite monolith:
1. **Missing `DatabaseManager::transaction()` (`db:`)**: Model mutations execute without transactions. A failure during redirect formatting or event dispatch leaves dirty database writes.
2. **Missing `ResponseFactory` & `Redirector` (`responses:`, `redirect:`)**: Instead of using Laravel's native `RedirectResponse` or `RedirectController` (which already interpolates route parameters into destination URLs), `DeclaredAction` invented custom attribute classes (`RedirectAction`, `FlashAction`) and hand-rolled regex parameter replacement.
3. **Missing `Dispatcher` (`events:`)**: State writes cannot dispatch native Laravel events because there is no `events:` mapping in the manifest.

**The Natural Solution**: Map `db:`, `redirect:`, `responses:`, and `events:` into Tier 1. Refactor `DeclaredAction` to execute mutations inside `DB::transaction()`, dispatch domain events via `Event::dispatch()`, and return standard `RedirectResponse` instances without custom attribute classes.

---

### 3.3 The Native Route View / Redirect Bypass (`docs/declarative-routing.md`)

Laravel's native `Router` contains dedicated, hyper-optimized methods for static views and redirects:
- `Route::view('/welcome', 'welcome', ['status' => 200]);`
- `Route::redirect('/here', '/there', 301);`

Because these methods were not mapped under `routes:` in Tier 1, all routes were forced to route to custom seam controllers (`DeclaredView` or `DeclaredAction`). 

**The Natural Solution**: Update `src/Route.php` to recognize `view` and `redirect` top-level keys. If a route defines `view` without dynamic `queries:`, invoke `Router::view()` natively. If a route defines `redirect` without mutations, invoke `Router::redirect()` natively. Reserve `DeclaredView` and `DeclaredAction` exclusively for dynamic orchestration.

---

### 3.4 The Relational Eloquent Void (`docs/declarative-model.md`)

Dynamic model synthesis (`src/DeclaredModel.php`) creates runtime Eloquent classes.
However, `models:` in `src/Model.php` currently omits Eloquent relationships. 

**Consequences of this shortcut**:
A dynamically synthesized `Todo` model cannot declare `$this->belongsTo(User::class)`. The roadmap claimed relationships "stay PHP in the model class", but dynamic synthesis produces models *without* PHP class files, creating an irreconcilable paradox.

**The Natural Solution**: Map `models.relations:` directly to `Illuminate\Database\Eloquent\Relations\*` in Tier 1. Dynamic model synthesis can then generate relationship methods automatically.

---

### 3.5 The Pagination Rendering Shortcut (`docs/declarative-query.md`)

When `queries:` executes `paginate: 15` or `cursorPaginate: 10`, the query builder produces an `Illuminate\Pagination\LengthAwarePaginator` or `CursorPaginator`.
When an inline template or view renders `$todos->links()`, it uses Laravel's default pagination views. Because `Illuminate\Pagination\Paginator` is unmapped in Tier 1, applications cannot set `Paginator::useTailwind()`, `Paginator::useBootstrapFive()`, or `Paginator::defaultView()` declaratively, forcing templates to hand-roll custom pagination controls.

**The Natural Solution**: Map `pagination:` in Tier 1 to invoke `Paginator::defaultView()` and CSS framework helpers during boot.

---

### 3.6 The Authorization Gate Bypass (`docs/declarative-requests.md`)

In `DeclaredRequest`, authorization relies on custom PHP hooks or ad-hoc boolean flags because `Illuminate\Contracts\Auth\Access\Gate` is not mapped in Tier 1.

**Consequences of this shortcut**:
Declarative routes and requests cannot leverage Laravel's native `can:` middleware or Policy evaluation.

**The Natural Solution**: Map `gate:` directly to `Illuminate\Contracts\Auth\Access\Gate` in Tier 1, allowing routes to declare `can: update,todo` natively.

---

## 4. Concrete Recommendations to Progress Towards `declarative-inline-template.md`

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

## 5. Master Phased Implementation Roadmap: API Maps First, Glue Code Second

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
