# Declarative Framework API Mapping & Gap Analysis — Architecture Audit & Inline Template Roadmap

Source of truth: `vendor/laravel/framework/src/Illuminate` (`laravel/framework` v13.33.0), official Laravel documentation repository (`docs/repos/laravel/docs/*.md`), and package specifications (`docs/declarative-*.md`).

This document conducts an exhaustive architectural audit of the Laravel framework API against the current declaration engine (`laravel-declaration`). It identifies architectural shortcuts taken in `docs/declarative-request-to-view-roadmap.md`, maps all framework subsystems showing completed versus unmapped components, and delivers a concrete recommendation to advance towards `docs/declarative-inline-template.md`.

---

## 1. Executive Summary: The "Shortcut" Problem

The current roadmap in `docs/declarative-request-to-view-roadmap.md` achieves rapid end-to-end functionality by introducing **composite seam classes**—notably `DeclaredAction` and `DeclaredView`—that bundle multiple disparate framework responsibilities into route defaults. 

While effective as an initial proof of concept, this approach creates architectural shortcuts because foundational framework subsystems have not been mapped into the manifest as first-class citizens:
1. **The Blade Bypass Shortcut**: `view:` maps `Illuminate\View\Factory` (view locations, shared data, composers), but entirely skips `Illuminate\View\Compilers\BladeCompiler`. When `docs/declarative-inline-template.md` introduces `template:` via `Blade::render()`, it compiles to a temporary anonymous component hash. **Crucially, this completely bypasses all view composers registered under `view.composer` in Phase 2**, severing the dynamic view data pipeline.
2. **The Composite Action Shortcut**: `DeclaredAction` merges Eloquent model mutation (`Model::create()`, `update()`, `delete()`), input attribute mapping, database persistence, redirect generation, and session flash chaining into a single controller. In native Laravel, these are distinct concerns handled by `Illuminate\Routing\RedirectController`, `Illuminate\Contracts\Routing\ResponseFactory`, `Illuminate\Events\Dispatcher`, and `Illuminate\Database\DatabaseManager`.
3. **The Relational Eloquent Shortcut**: `models:` in `src/Model.php` maps 20+ property defaults, but **omits Eloquent relationships** (`hasMany`, `belongsTo`, `belongsToMany`). Consequently, dynamic model synthesis (Phase 9) produces isolated models incapable of relational persistence without hand-written PHP files.
4. **The Missing Security & Event Pillars**: Authorization (`Illuminate\Contracts\Auth\Access\Gate`) and Events (`Illuminate\Events\Dispatcher`) are omitted, forcing request authorization into custom classes and state mutations to run without decoupled lifecycle listeners or database transactions.

---

## 2. Complete Laravel Framework API Mapping

The table below maps the entire Laravel framework API (derived from `docs/repos/laravel/docs/documentation.md` and `vendor/laravel/framework/src/Illuminate/`), detailing the **system of record**, current implementation status, manifest key, and architectural gap.

### Status Definitions:
- **Implemented**: Fully mapped via dedicated DataModel, manifest key, service provider loop, and test suite.
- **Active Scope**: Identified in `declarative-request-to-view-roadmap.md` (Phases 6–9) with active specification.
- **Unmapped Gap**: Not mapped in the manifest; currently forcing composite shortcuts or deferred to hand-written PHP.

---

### Domain 1: Architecture Concepts & Container

| Subsystem | System of Record (Laravel Class) | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps |
|---|---|---|---|---|
| **Service Container** | `Illuminate\Container\Container` / `Illuminate\Foundation\Application` | **Implemented** | `app:` (`src/App.php`) | **Mapped**: `bind`, `singleton`, `scoped`, `instance`, `alias`, `tag`, `contextual`, `extend`, `terminating`, `resolving`, `afterResolving`.<br>**Gap**: None. Adheres to Rule 1 & Rule 2. |
| **Configuration** | `Illuminate\Config\Repository` | **Implemented** | `config:` (`ConfigDeclarationServiceProvider`) | **Mapped**: `Config::set($key, $value)`.<br>**Gap**: None. |
| **Service Providers** | `Illuminate\Support\ServiceProvider` | **Implemented** | `providers:` (`src/Provider.php`) | **Mapped**: `Application::register($provider)`.<br>**Gap**: Deferred provider boot arguments. |
| **HTTP Kernel & Middleware** | `Illuminate\Foundation\Http\Kernel` | **Implemented** | `kernel:` (`src/Kernel.php`) | **Mapped**: `middleware`, `middlewareGroups`, `middlewareAliases`, `middlewarePriority`, `whenRequestLifecycleIsLongerThan`.<br>**Gap**: None. |

---

### Domain 2: HTTP Routing, Pipeline & Dispatch

| Subsystem | System of Record (Laravel Class) | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps |
|---|---|---|---|---|
| **Router Configuration** | `Illuminate\Routing\Router` | **Implemented** | `router:` (`src/Router.php`) | **Mapped**: `pattern`, `patterns`, `model`, `bind`.<br>**Gap**: Route group prefixes/domains outside route items. |
| **Route Registration** | `Illuminate\Routing\Router` / `Route` | **Implemented** | `routes:` (`src/Route.php`) | **Mapped**: `addRoute`, `middleware`, `name`, `where`, `setDefaults`, `domain`, `prefix`.<br>**Gap**: Native `Router::redirect()` and `Router::view()` bypassed for custom seams. |
| **Redirect Controller** | `Illuminate\Routing\RedirectController` | **Unmapped Gap** | Bypassed by `DeclaredAction` | **Unmapped**: Native `RedirectController::__invoke()` handles route redirect parameter interpolation. Currently re-implemented inside `DeclaredAction` via `RedirectAction`. |
| **URL Generation** | `Illuminate\Routing\UrlGenerator` | **Unmapped Gap** | None | **Unmapped**: `URL::signedRoute()`, `URL::forceScheme()`, `URL::defaults()`. |
| **Rate Limiter** | `Illuminate\Cache\RateLimiter` | **Unmapped Gap** | None | **Unmapped**: `RateLimiter::for($name, Closure)`. Route throttling currently relies on hardcoded throttle middleware strings. |

---

### Domain 3: View Layer & Blade Compilation

| Subsystem | System of Record (Laravel Class) | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps |
|---|---|---|---|---|
| **View Factory** | `Illuminate\View\Factory` | **Implemented** | `view:` (`src/View.php`) | **Mapped**: `addLocation`, `addNamespace`, `share`, `composer`, `creator`.<br>**Gap**: Missing link to inline template names. |
| **Blade Compiler** | `Illuminate\View\Compilers\BladeCompiler` | **Unmapped Gap** | None (Targeted in Phase 8 via `DeclaredView`) | **Unmapped**: `directive()`, `if()`, `component()`, `components()`, `anonymousComponentPath()`, `stringable()`, `precompiler()`.<br>**Gap Impact**: Template rendering is forced into `setDefaults.template`, bypassing Blade component resolution and view composers. |
| **View Dispatch Controller** | `Illuminate\Routing\ViewController` | **Implemented** | `DeclaredView` (`src/DeclaredView.php`) | **Mapped**: View rendering with dynamic data merging and query resolution.<br>**Gap**: Direct call to `Blade::render()` unlinks view files and bypasses `View\Factory` events. |

---

### Domain 4: Request Lifecycle, Input & Validation

| Subsystem | System of Record (Laravel Class) | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps |
|---|---|---|---|---|
| **Form Request** | `Illuminate\Foundation\Http\FormRequest` | **Implemented** | `requests:` (`src/Request.php`, `DeclaredRequest`) | **Mapped**: `rules`, `messages`, `attributes`, `stopOnFirstFailure`, `redirect`, `redirectRoute`, `errorBag`.<br>**Gap**: Dynamic conditional rules (`sometimes`). |
| **Validator Factory** | `Illuminate\Validation\Factory` | **Unmapped Gap** | None | **Unmapped**: `Validator::extend()`, custom implicit rules, custom rule objects. |

---

### Domain 5: Response Generation & Transport

| Subsystem | System of Record (Laravel Class) | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps |
|---|---|---|---|---|
| **Response Factory** | `Illuminate\Routing\ResponseFactory` | **Unmapped Gap** | Bypassed in `DeclaredView` / `DeclaredAction` | **Unmapped**: `ResponseFactory::make()`, `view()`, `json()`, `stream()`, `download()`. Response creation is coupled directly to controller seams rather than declared as response formats. |
| **Redirect Response** | `Illuminate\Http\RedirectResponse` / `Redirector` | **Active Scope** | `DeclaredAction` (`FlashAction`, `RedirectAction`) | **Mapped**: `route()`, `to()`, `back()`, `with()`, `withInput()`, `withErrors()`.<br>**Gap**: Tightly coupled to mutation logic. |
| **API Resources** | `Illuminate\Http\Resources\Json\JsonResource` | **Unmapped Gap** | None | **Unmapped**: Declarative API resource transformations for REST endpoints. |

---

### Domain 6: Database, Migrations & Schema Definition

| Subsystem | System of Record (Laravel Class) | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps |
|---|---|---|---|---|
| **Schema Builder & Blueprint** | `Illuminate\Database\Schema\Builder` / `Blueprint` | **Implemented** | `schema:` (`src/Schema.php`, `declaration:migrate`) | **Mapped**: `id`, `string`, `text`, `boolean`, `integer`, `foreignId`, `timestamps`, column modifiers (`nullable`, `default`, `unique`).<br>**Gap**: Table alters / drops (`dropIfExists`), indexes (`index`, `spatialIndex`). |
| **Database Connection & Transactions** | `Illuminate\Database\DatabaseManager` | **Unmapped Gap** | None | **Unmapped**: `DB::transaction()`, connection selection per operation. Mutations in `DeclaredAction` execute un-transactioned. |
| **Database Seeding** | `Illuminate\Database\Seeder` | **Unmapped Gap** | None | **Unmapped**: Declarative seed records (`seeders:`, `db:seed`). |

---

### Domain 7: Eloquent ORM & Query Builder

| Subsystem | System of Record (Laravel Class) | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps |
|---|---|---|---|---|
| **Eloquent Model Defaults** | `Illuminate\Database\Eloquent\Model` | **Implemented** | `models:` (`src/Model.php`, `DeclaredModel`) | **Mapped**: `table`, `primaryKey`, `incrementing`, `timestamps`, `casts`, `fillable`, `guarded`, `hidden`, `visible`, `appends`, `observe`, `addGlobalScope`, `getRouteKeyName`.<br>**Gap**: Dynamic synthesis lacks relationships. |
| **Eloquent Relationships** | `Illuminate\Database\Eloquent\Relations\*` | **Unmapped Gap** | None | **Unmapped**: `hasMany`, `belongsTo`, `hasOne`, `belongsToMany`, `morphMany`. Critical blocker for dynamic models. |
| **Eloquent Query Builder** | `Illuminate\Database\Eloquent\Builder` | **Implemented** | `queries:` (`src/Query.php`, `DeclaredQuery`) | **Mapped**: 40+ methods (`where`, `with`, `latest`, `limit`, `paginate`, `get`, `first`, etc.).<br>**Gap**: Subquery closures, raw SQL expressions. |

---

### Domain 8: Security, Identity & Authorization

| Subsystem | System of Record (Laravel Class) | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps |
|---|---|---|---|---|
| **Authorization Gate** | `Illuminate\Contracts\Auth\Access\Gate` | **Unmapped Gap** | None | **Unmapped**: `Gate::define()`, `Gate::policy()`, `Gate::before()`. Authorize in `FormRequest` has no declarative policy engine. |
| **Authentication Guards** | `Illuminate\Auth\AuthManager` | **Unmapped Gap** | None | **Unmapped**: Declarative guard definitions, `Auth::routes()`. |
| **Session Manager** | `Illuminate\Session\SessionManager` | **Unmapped Gap** | Partially via `FlashAction` | **Unmapped**: Session lifetime configuration, declarative session key binding. |

---

### Domain 9: Events, Listeners & Async Communication

| Subsystem | System of Record (Laravel Class) | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps |
|---|---|---|---|---|
| **Event Dispatcher** | `Illuminate\Events\Dispatcher` | **Unmapped Gap** | `models.dispatchesEvents` (partial) | **Unmapped**: `Event::listen($event, $listener)`, `Event::subscribe()`. No top-level `events:` block exists to attach listeners to model mutations or HTTP lifecycles. |
| **Queues & Jobs** | `Illuminate\Queue\QueueManager` | **Unmapped Gap** | None | **Unmapped**: Declarative queued job dispatching. |
| **Mail & Notifications** | `Illuminate\Mail\MailManager` / `NotificationManager` | **Unmapped Gap** | None | **Unmapped**: Declarative mailable notifications on state mutations. |

---

### Domain 10: Operations, Console & Caching

| Subsystem | System of Record (Laravel Class) | Status | Manifest Key / Seam Class | Mapped Methods vs. Unmapped Gaps |
|---|---|---|---|---|
| **Artisan Console** | `Illuminate\Console\Application` | **Unmapped Gap** | Hand-coded internal commands | **Unmapped**: Declarative custom commands (`commands:`). |
| **Task Scheduling** | `Illuminate\Console\Scheduling\Schedule` | **Unmapped Gap** | None | **Unmapped**: Declarative cron scheduling (`schedule:`). |
| **Cache Manager** | `Illuminate\Cache\CacheManager` | **Unmapped Gap** | None | **Unmapped**: Query cache memoization, declarative tag flushes. |
| **File Storage** | `Illuminate\Filesystem\FilesystemManager` | **Unmapped Gap** | None | **Unmapped**: `Storage::disk()` declarations. |

---

## 3. Deep Architectural Critique: Why the Roadmap Took Shortcuts

Examining `docs/declarative-request-to-view-roadmap.md` against this map reveals why the implementation feels like a "shortcut":

### 3.1 The Blade vs. View Disconnect (The Immediate Problem)
In Phase 2, the package mapped `Illuminate\View\Factory` under `view:`, supporting `share`, `composer`, and `creator`. 
However, **Blade compilation was omitted**. 
When Phase 8 (`declarative-inline-template.md`) addresses inline templates, it jumps directly to:
```php
Blade::render($template, $data, deleteCachedView: true);
```
**Consequences of this shortcut**:
1. `Blade::render()` instantiates an anonymous component that renders to an ephemeral file (`storage/framework/views/{hash}.blade.php`).
2. Because the view is evaluated by file path rather than a named view identifier (e.g. `todos.index`), **`View\Factory::composer()` bindings never trigger**.
3. View components cannot be declared. If a user needs `<x-layout>` or `<x-todo-item>`, they must create physical disk files, contradicting the single-manifest promise.
4. Custom Blade directives (`Blade::directive()`) and custom conditions (`Blade::if()`) cannot be declared in YAML.

### 3.2 The `DeclaredAction` Composite Trap
In Phase 7, `DeclaredAction` was invented to handle state mutations (`POST`, `PATCH`, `DELETE`). It reads `model`, `call`, `target`, `redirect`, `status`, and `with` from `setDefaults`.
**Consequences of this shortcut**:
1. **Conflation of Concerns**: It combines database writing with HTTP redirect formatting. In native Laravel, controllers invoke domain actions and return a response from `ResponseFactory`.
2. **Missing Database Transactions**: Model mutations (`Todo::create()`) run without `DB::transaction()`. If post-mutation logic fails, partial writes persist.
3. **Ignored `RedirectController`**: Laravel already provides `Illuminate\Routing\RedirectController` specifically designed to interpolate route defaults into redirect responses. `DeclaredAction` hand-rolled custom URL parameter replacement instead of using Laravel's native controller.
4. **Missing Event Dispatch**: State writes in `DeclaredAction` cannot dispatch decoupled domain events because there is no `events:` block in the manifest.

### 3.3 The Relational Gap in Dynamic Models
Phase 9 plans "Zero-PHP Dynamic Model Synthesis" where `models:` entries synthesize classes extending `DeclaredModel`.
**Consequences of this shortcut**:
`models:` contains no relationship mappings (`hasMany`, `belongsTo`). A synthesized `Todo` cannot relate to a `User`. The roadmap claims relations "stay PHP in the model class", but dynamic synthesis has no PHP class file, creating a direct architectural contradiction.

---

## 4. Concrete Recommendations to Progress Towards `declarative-inline-template.md`

To progress towards Phase 8 (`docs/declarative-inline-template.md`) naturally—eliminating shortcuts and adhering strictly to the design rules (**Key = method name**, **Pass references through**, **Fail where Laravel fails**)—the following architectural progression is recommended:

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│ Step 1: Map Blade Subsystem (`blade:` -> `Illuminate\View\Compilers\BladeCompiler`) │
│  - directive, if, anonymousComponentPath, component                              │
└────────────────────────────────────────┬─────────────────────────────────────────┘
                                         │
                                         ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│ Step 2: Virtual Named View Integration (`View\Factory` + `Blade`)                │
│  - Bridge inline templates to named views so `view.composer` triggers naturally   │
└────────────────────────────────────────┬─────────────────────────────────────────┘
                                         │
                                         ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│ Step 3: Implement Phase 8 (`declarative-inline-template.md`) in `DeclaredView`    │
│  - Dispatches via `ResponseFactory::make()` and respects registered components    │
└──────────────────────────────────────────────────────────────────────────────────┘
```

### Recommendation 1: Map `blade:` into `Manifest`
Introduce a top-level `blade:` block mapping directly to `Illuminate\View\Compilers\BladeCompiler` methods:
```yaml
blade:
  directive:
    datetime: App\Blade\Directives@datetime
  if:
    admin: App\Blade\Conditions@isAdmin
  anonymousComponentPath:
    - path: resources/views/components
      prefix: ~
```
**Justification**: Gives `BladeCompiler` its rightful place as a first-class subsystem alongside `view:`, allowing layout templates and UI components to be referenced inside inline templates.

### Recommendation 2: Bridge Inline Templates into Named Views
Instead of having `DeclaredView` call `Blade::render()` in isolation, introduce a **Virtual Template Provider** that registers declared templates into Laravel's `View\Factory`:
1. When a route declares `name: todos.index` and `template: "<h1>Todos</h1>"`, register `todos.index` as a virtual view in `View\Factory`.
2. When the request resolves, call `ResponseFactory::view('todos.index', $data, $status, $headers)`.
3. **Result**: All view composers (`view.composer: {todos.index: ...}`), creators, and shared data run through the native Laravel pipeline without bypassing Phase 2 deliverables.

### Recommendation 3: Refine `setDefaults.template` in `DeclaredView`
Within `src/DeclaredView.php`:
1. If `setDefaults.template` is present, resolve dynamic data via `DeclaredQuery` and parameter bindings (Stage 15).
2. Render via `Blade::render($template, $data, deleteCachedView: true)`.
3. Wrap output with `ResponseFactory::make($html, $status, $headers)`.
4. Ensure route parameters bound via `SubstituteBindings` (`Router::model()`) are automatically exposed to the inline template context.

### Recommendation 4: Complete Model Relationships Prior to Phase 9
Before attempting dynamic model synthesis in Phase 9, extend `src/Model.php` to support declarative relationship definitions:
```yaml
models:
  - class: App\Models\Todo
    table: todos
    relations:
      user:
        belongsTo: App\Models\User
```
This resolves the dynamic synthesis paradox and enables declarative parent-child models.

---

## 5. Summary of Recommended Phase Order

1. **Phase 6: Declarative Schema (`schema:`)** [Already in progress]: Finalize table migrations.
2. **Phase 7: Declarative Action (`DeclaredAction`)**: Decouple state mutations from redirects; leverage native `RedirectResponse`.
3. **Phase 7.5: Declarative Blade Compiler (`blade:`)**: Map `BladeCompiler` directives and anonymous component paths.
4. **Phase 8: Declarative Inline Templates (`DeclaredView`)**: Implement `template:` rendering with virtual view naming to preserve view composer integration.
5. **Phase 8.5: Declarative Eloquent Relationships (`models.relations`)**: Map `belongsTo`, `hasMany` on `Model`.
6. **Phase 9: Dynamic Model Synthesis (`DeclaredModel`)**: Zero-file Eloquent synthesis with full relational support.
