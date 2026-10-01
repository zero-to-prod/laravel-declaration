# Declarative Framework API Mapping — Current-State Tier Map

Source of truth: `vendor/laravel/framework/src/Illuminate` (`laravel/framework` v13.33.0), official Laravel documentation repository (`docs/repos/laravel/docs/*.md`), and package specifications (`docs/declarative-*.md`). Every status below was verified against package source (`src/`) and feature tests (`tests/Feature/`).

This document maps the Laravel framework API onto the declaration engine (`laravel-declaration`). It formalizes the strict boundary between the **Laravel API Map (Tier 1)** and **Declarative Seams / Glue Code (Tier 2)**. Remaining Tier 1 work is inventoried in [declarative-tier1-remaining.md](declarative-tier1-remaining.md).

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
- `[x] Implemented (Tier 1 API Map)`: Fully mapped via DataModel, manifest key, service provider, and feature tests in package source (`src/`).
- `[/] Partially Mapped (Tier 1 API Map)`: Represented in manifest/source, but missing native methods or options — enumerated in [declarative-tier1-remaining.md](declarative-tier1-remaining.md).
- `[ ] Missing / Left To Do (Tier 1 API Map)`: Native Laravel class/registry not yet projected; no declarative surface exists.
- Decided non-goals are recorded inline with their grounding document.

### Domain 1: Core Architecture, Container & Configuration
- [x] **Service Container & Application** (`Illuminate\Container\Container`, `Illuminate\Foundation\Application`) — `app:`
- [x] **Configuration Repository** (`Illuminate\Config\Repository`) — `config:`
- [x] **Service Providers** (`Illuminate\Support\ServiceProvider`) — `providers:`

### Domain 2: HTTP Kernel & Middleware Pipeline
- [x] **HTTP Kernel & Middleware Pipeline** (`Illuminate\Foundation\Http\Kernel`, `Illuminate\Routing\Pipeline`) — `kernel:`
- [ ] **CSRF Verification & Route Exclusions** (`Illuminate\Foundation\Http\Middleware\ValidateCsrfToken`) — `csrf:`
- [ ] **HTTP Precognition** (`Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests`) — `precognition:`

### Domain 3: HTTP Routing, Pipeline, URLs & Throttling
- [x] **Router Configuration & Binders** (`Illuminate\Routing\Router`) — `router:` (`patterns()` a decided non-goal)
- [x] **Route Registration** (`Illuminate\Routing\Router`, `Illuminate\Routing\Route`) — `routes:` (`addRoute`, `group`, `resource`, `apiResource`, `singleton`, `apiSingleton`, `view`, `redirect`, `permanentRedirect` registrar map + dynamic `builders` dispatch)
- [ ] **URL Generation & Signed URLs** (`Illuminate\Routing\UrlGenerator`) — `url:`
- [ ] **Rate Limiter** (`Illuminate\Cache\RateLimiter`) — `rate_limiter:`

### Domain 4: View Layer, Blade Engine & Presentation
- [x] **View Factory & Namespaces** (`Illuminate\View\Factory`, `Illuminate\View\FileViewFinder`) — `view:` (the render-time surface dispatches through `DeclaredView`'s `factory:`; `flushFinderCache`/`flushState` are `view:` epilogue booleans — [declarative-view-factory.md](declarative-view-factory.md))
- [x] **Blade Compiler & Directives** (`Illuminate\View\Compilers\BladeCompiler`) — `blade:`
- [/] **Pagination View Resolvers & Styling** (`Illuminate\Pagination\Paginator`, `Illuminate\Pagination\LengthAwarePaginator`) — `pagination:` (Tailwind/Bootstrap 5 mapped; Bootstrap 3/4 and the `useBootstrap()` alias remain)
- [x] **View Dispatch Controller** (`Illuminate\Routing\ViewController`) — `DeclaredView` (inline `template` dispatch, `factory:` render-time surface, `composing:` event bridge, `ResponseFactory` response)

### Domain 5: Request Lifecycle, Input Resolution & Validation
- [x] **Form Request Declaration** (`Illuminate\Foundation\Http\FormRequest`) — `requests:` (native `shouldFailOnUnknownFields()`, rule class-strings container-resolved, `Rule::when()`/`Rule::unless()` conditional rules)
- [x] **Validation Factory & Custom Rules** (`Illuminate\Validation\Factory`, `Illuminate\Contracts\Validation\ValidationRule`) — `validator:`
- [x] **Form Request Seam** (`Illuminate\Foundation\Http\FormRequest`) — `DeclaredRequest` (`authorize` map form dispatches onto the native `Gate` contract via `Container::call` — [declarative-gate.md](declarative-gate.md))

### Domain 6: Response Generation, Redirects & Transport
- [/] **Response Factory & Macros** (`Illuminate\Contracts\Routing\ResponseFactory`, `Illuminate\Routing\ResponseFactory`) — `responses:` (`macro` mapped; `make`/`view`/`json`/`noContent`/`stream`/`download` response forms are not declarative surfaces)
- [ ] **Redirector & Redirect Responses** (`Illuminate\Routing\Redirector`, `Illuminate\Http\RedirectResponse`) — `redirect:`
- [ ] **Cookies & Cookie Jar** (`Illuminate\Cookie\CookieJar`) — `cookie:`
- [ ] **API Resources & JSON Serialization** (`Illuminate\Http\Resources\Json\JsonResource`) — `resources:`
- [ ] **Action Controller Seam** (`Illuminate\Routing\Controller`) — `DeclaredAction` (Tier 2; spec at [declarative-action.md](declarative-action.md), not yet in source)

### Domain 7: Database Connection, Query Builder, Transactions & Seeding
- [/] **Database Connection & Transactions** (`Illuminate\Database\DatabaseManager`, `Illuminate\Database\Connection`) — `db:` (`connection` + `listen` mapped; `transaction`, `statement`, `unprepared`, `beforeExecuting` remain)
- [ ] **Database Query Builder (Table-Level Queries)** (`Illuminate\Database\Query\Builder`) — `db_queries:` / `queries.table`
- [x] **Database Schema & Blueprint** (`Illuminate\Database\Schema\Builder`, `Illuminate\Database\Schema\Blueprint`) — `schema:` (`create`, `table`, `rename`, `drop`, `dropIfExists` under native names; `BlueprintAction` dynamic dispatch with guard annotations — [declarative-schema-table-operations.md](declarative-schema-table-operations.md))
- [ ] **Database Seeding & Factories** (`Illuminate\Database\Seeder`, `Illuminate\Database\Eloquent\Factories\Factory`) — `seeds:`

### Domain 8: Eloquent ORM & Query Builder
- [x] **Eloquent Model Configuration & Lifecycle** (`Illuminate\Database\Eloquent\Model`) — `models:` (every declared `Model` property plus `observe`, `addGlobalScope`, `getRouteKeyName` — [declarative-model.md](declarative-model.md); relations, modern `casts()`, accessors/mutators, local scopes, `booted`, `prunable` are decided non-goals: class-body concerns per [declarative-model-configuration.md](declarative-model-configuration.md))
- [/] **Eloquent Query Builder (Model Queries)** (`Illuminate\Database\Eloquent\Builder`) — `queries:` (dynamic dispatch onto `Builder`/`Relation` with native `BadMethodCallException` propagation; nouns `model`/`relation`; the `relation` root still reads route parameters directly — a Rule 5 tension — and subquery closures / raw expressions are strings-only)
- [/] **Dynamic Model Synthesis** (`Illuminate\Database\Eloquent\Model`) — `DeclaredModel` (abstract seam ships with property/method injection; the zero-PHP synthesis autoloader does not)

### Domain 9: Security, Identity & Access Control
- [/] **Authorization Gates & Policies** (`Illuminate\Contracts\Auth\Access\Gate`, `Illuminate\Auth\Access\Gate`) — `gate:` (`define`, `policy` mapped — [declarative-gate.md](declarative-gate.md); `before`, `after`, `resource`, `allowIf`/`denyIf`, `guessPolicyNamesUsing` remain)
- [ ] **Authentication Manager & Guards** (`Illuminate\Auth\AuthManager`) — `auth:`
- [ ] **Session Store & Flash Data** (`Illuminate\Session\SessionManager`, `Illuminate\Session\Store`) — `session:`
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
- [ ] **Full-Text Search (Scout)** (`Laravel\Scout\Searchable`) — `scout:`

---

## Comprehensive Laravel Framework API Mapping (12 Architectural Domains)

Derived from `vendor/laravel/framework/src/Illuminate/` (`laravel/framework` v13.33.0) and `docs/repos/laravel/docs/*.md`. "Mapped" lists the manifest keys that exist on the DataModel and are applied by the corresponding `Providers/*DeclarationServiceProvider`; "Gap" lists the remaining native surface.

---

### Domain 1: Core Architecture, Container & Configuration

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped vs. Gap |
|---|---|---|---|---|---|
| **Service Container & Application**<br>`docs/repos/laravel/docs/container.md` | `Illuminate\Container\Container`<br>`Illuminate\Foundation\Application` | Tier 1 | **Implemented** | `app:` (`src/App.php`, `Providers/AppDeclarationServiceProvider.php`) | **Mapped**: `bind`, `bindIf`, `singleton`, `singletonIf`, `scoped`, `scopedIf`, `instance`, `alias`, `extend`, `tag`, `when` (`needs`/`give` contextual bindings), `resolving`, `afterResolving`, `registered`, `booting`, `booted`, `terminating`, `setLocale`, `setFallbackLocale`, and path setters `useAppPath`, `useDatabasePath`, `useLangPath`, `usePublicPath`, `useStoragePath`, `useBootstrapPath`, `useConfigPath`, `useEnvironmentPath`.<br>**Gap**: none. |
| **Configuration**<br>`docs/repos/laravel/docs/configuration.md` | `Illuminate\Config\Repository` | Tier 1 | **Implemented** | `config:` (`Providers/ConfigDeclarationServiceProvider.php`) | **Mapped**: `set($key, $value)` — direct mutation of the configuration repository.<br>**Gap**: none. |
| **Service Providers**<br>`docs/repos/laravel/docs/providers.md` | `Illuminate\Foundation\Application::register()` | Tier 1 | **Implemented** | `providers:` (`src/Provider.php`, `Providers/ProvidersDeclarationServiceProvider.php`) | **Mapped**: `class` (required) and `force` — one call per entry to `Application::register()`; all keys dispatch to the application.<br>**Gap**: none. Container bindings live under `app:` per Domain 1. |

### Domain 2: HTTP Kernel & Middleware Pipeline

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped vs. Gap |
|---|---|---|---|---|---|
| **HTTP Kernel & Middleware**<br>`docs/repos/laravel/docs/middleware.md` | `Illuminate\Foundation\Http\Kernel`<br>`Illuminate\Routing\Pipeline` | Tier 1 | **Implemented** | `kernel:` (`src/Kernel.php`, `Providers/KernelDeclarationServiceProvider.php`) | **Mapped**: `pushMiddleware`, `prependMiddleware`, `setGlobalMiddleware`, `appendMiddlewareToGroup`, `prependMiddlewareToGroup`, `setMiddlewareGroups`, `setMiddlewareAliases`, `setMiddlewarePriority`, `prependToMiddlewarePriority`, `appendToMiddlewarePriority`, `addToMiddlewarePriorityBefore`, `addToMiddlewarePriorityAfter`, `whenRequestLifecycleIsLongerThan`.<br>**Gap**: none. |
| **CSRF Protection**<br>`docs/repos/laravel/docs/csrf.md` | `Illuminate\Foundation\Http\Middleware\ValidateCsrfToken` | Tier 1 | **Missing** | `csrf:` / `kernel.csrf` | **Gap**: URI exclusions (`except: ['api/*', 'stripe/*']`). |
| **HTTP Precognition**<br>`docs/repos/laravel/docs/precognition.md` | `Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests` | Tier 1 | **Missing** | `precognition:` | **Gap**: Precognitive validation headers and route middleware binding. |

### Domain 3: HTTP Routing, Pipeline, URLs & Throttling

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped vs. Gap |
|---|---|---|---|---|---|
| **Router Configuration**<br>`docs/repos/laravel/docs/routing.md` | `Illuminate\Routing\Router` | Tier 1 | **Implemented** | `router:` (`src/Router.php`, `Providers/RouterDeclarationServiceProvider.php`) | **Mapped**: `pattern`, `model`, `bind`, `middlewareGroup`, `aliasMiddleware`, `pushMiddlewareToGroup`, `prependMiddlewareToGroup`, `removeMiddlewareFromGroup`, `singularResourceParameters`, `resourceParameters`, `resourceVerbs`, `matched` — attribute-selected dispatch (`#[Binding]`/`#[Setter]`/`#[AppendTo]`/`#[PrependTo]`/`#[Append]`), with the `kernel:` precedence model ([declarative-router-configuration.md](declarative-router-configuration.md)).<br>**Gap**: `patterns()` — a decided non-goal ([declarative-router.md](declarative-router.md) §2.6); it is the plural batch of the mapped `pattern()`. |
| **Route Registration**<br>`docs/repos/laravel/docs/routing.md` | `Illuminate\Routing\Router`<br>`Illuminate\Routing\Route` | Tier 1 | **Implemented** | `routes:` (`src/Routes.php`, `src/Route.php`, `Providers/RoutesDeclarationServiceProvider.php`) | **Mapped**: the `routes:` block is a map of native `Router` registration method names — `addRoute`, `group`, `resource`, `apiResource`, `singleton`, `apiSingleton`, `view`, `redirect`, `permanentRedirect` — dispatched dynamically with the post-call seam following the return type (`Route` → `builders`, `Pending*Registration` → options, group → nested `routes`) ([declarative-route-registrars.md](declarative-route-registrars.md)). Per-`Route` fluent surface dispatches dynamically (`builders`), covering constraints (`whereAlpha`, `whereNumber`, `whereIn`), protocol restrictions (`secure`, `httpOnly`), scoped binding enforcers, `bindingFields`, `missing`, `fallback`, `can`, `metadata`, `block`/`withoutBlocking`, `withTrashed`, `when`/`unless`.<br>**Gap**: verb dispatch keys (`routes.get`/`routes.post`/…) — a decided non-goal (§2.4 note 1 of [declarative-route-registrars.md](declarative-route-registrars.md)); `methods:` already covers verbs natively. |
| **URL Generation**<br>`docs/repos/laravel/docs/urls.md` | `Illuminate\Routing\UrlGenerator` | Tier 1 | **Missing** | `url:` | **Gap**: `signedRoute()`, `temporarySignedRoute()`, `forceScheme()`, `forceRootUrl()`, `defaults()`. |
| **Rate Limiter**<br>`docs/repos/laravel/docs/rate-limiting.md` | `Illuminate\Cache\RateLimiter` | Tier 1 | **Missing** | `rate_limiter:` | **Gap**: `RateLimiter::for($name, Closure)`, `attempt()`. |

### Domain 4: View Layer, Blade Engine, Components & Pagination

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped vs. Gap |
|---|---|---|---|---|---|
| **View Factory**<br>`docs/repos/laravel/docs/views.md` | `Illuminate\View\Factory`<br>`Illuminate\View\FileViewFinder` | Tier 1 | **Implemented** | `view:` (`src/View.php`, `Providers/ViewDeclarationServiceProvider.php`) | **Mapped (boot-time)**: `addLocation`, `prependLocation`, `addNamespace`, `prependNamespace`, `replaceNamespace`, `addExtension`, `share`, `composer` (`views: callback` in native argument order), `creator`; epilogue booleans `flushFinderCache`, `flushState`.<br>**Mapped (render-time)**: `make`, `file`, `first`, `renderEach`, `renderWhen`, `renderUnless`, and every future `Factory` method dispatch through one `DeclaredView` `setDefaults.factory` entry — `$Factory->{$method}(...$arguments)`, zero per-method code ([declarative-view-factory.md](declarative-view-factory.md)). `exists()` stays reachable-only (`$__env->exists()`): a `bool` is not renderable. |
| **Blade Compiler**<br>`docs/repos/laravel/docs/blade.md` | `Illuminate\View\Compilers\BladeCompiler` | Tier 1 | **Implemented** | `blade:` (`src/Blade.php`, `Providers/BladeDeclarationServiceProvider.php`) | **Mapped**: `directive`, `if`, `component`, `components`, `anonymousComponentPath`, `anonymousComponentNamespace`, `stringable`, `withoutDoubleEncoding` — queued via `callAfterResolving('blade.compiler', …)`; reference strings resolve through Laravel's native container call resolution.<br>**Gap**: none on the registry surface. |
| **Pagination Engine**<br>`docs/repos/laravel/docs/pagination.md` | `Illuminate\Pagination\Paginator`<br>`Illuminate\Pagination\LengthAwarePaginator` | Tier 1 | **Partially Mapped** | `pagination:` (`src/Pagination.php`, `Providers/PaginationDeclarationServiceProvider.php`) | **Mapped**: `defaultView`, `defaultSimpleView`, `useTailwind`, `useBootstrapFive`.<br>**Gap**: `useBootstrapThree()`, `useBootstrapFour()`, and the `useBootstrap()` alias (which delegates to `useBootstrapFour()` in v13.33.0, `AbstractPaginator.php:628-667`). The remaining `AbstractPaginator` statics (`resolveCurrentPath`, `currentPageResolver`, `queryStringResolver`, …) are runtime plumbing a manifest should not declare. |
| **View Dispatch Controller**<br>`docs/repos/laravel/docs/views.md` | `Illuminate\Routing\ViewController` | Tier 2 | **Implemented** | `DeclaredView` (`src/DeclaredView.php`) | **Mapped**: extends `ViewController::__invoke()`; validates via metadata `request`; resolves query handles (`DeclaredQuery::run`) and `Class@method` callables into view data with route-parameter precedence; inline `template` renders via `Blade::render()` (`deleteCachedView` honored) with the `composing: {routeName}` event bridge; `view` delegates to the parent; `factory` dispatches the render-time `ViewFactory` surface; the response returns through `ResponseFactory::make()`. Exactly one render source (`template`, `view`, `factory`) is enforced with `LogicException`.<br>**Gap**: inline templates intentionally have no view identity, so named-view composers do not fire — by design ([declarative-inline-template.md](declarative-inline-template.md)). |

### Domain 5: Request Lifecycle, Input Resolution & Validation

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped vs. Gap |
|---|---|---|---|---|---|
| **Form Request Declaration**<br>`docs/repos/laravel/docs/requests.md` | `Illuminate\Foundation\Http\FormRequest` | Tier 1 | **Implemented** | `requests:` (`src/Request.php`) | **Mapped**: `rules`, `messages`, `attributes`, `authorize`, `validationData`, `prepareForValidation`, `passedValidation`, `withValidator`, `after`, `validator`, `failedValidation`, `failedAuthorization`, `redirect`, `redirectRoute`, `redirectAction`, `errorBag`, `stopOnFirstFailure`, `shouldFailOnUnknownFields` — every key is the native method/property name; rule class-strings are container-resolved in `DeclaredRequest::rule()`; conditional rules are entries keyed with the native `Rule::when()`/`Rule::unless()` method names ([declarative-validator.md](declarative-validator.md)).<br>**Gap**: none. |
| **Validation Factory**<br>`docs/repos/laravel/docs/validation.md` | `Illuminate\Validation\Factory`<br>`Illuminate\Contracts\Validation\ValidationRule` | Tier 1 | **Implemented** | `validator:` (`src/Validator.php`, `Providers/ValidatorDeclarationServiceProvider.php`) | **Mapped**: `extend()`, `extendImplicit()`, `extendDependent()`, `replacer()` — one key per native registry-method name, applied when Laravel first resolves the shared factory; references pass through untouched to Laravel's own `callClassBasedExtension`/`callClassBasedReplacer` dispatch ([declarative-validator.md](declarative-validator.md)).<br>**Gap**: none. |
| **Form Request Seam**<br>`docs/repos/laravel/docs/requests.md` | `Illuminate\Foundation\Http\FormRequest` | Tier 2 | **Implemented** | `DeclaredRequest` (`src/DeclaredRequest.php`) | **Mapped**: validates the resolved inbound request against the manifest definition and fails with the standard `ValidationException`; `authorize` resolves string references via `Container::call`, and its map form dispatches onto the native `Illuminate\Contracts\Auth\Access\Gate` contract (`check`, `any`, `none`, `allows`, `denies`, `inspect`, `authorize`, `raw`) with the `Authorize::getGateArguments()` `arguments` contract ([declarative-gate.md](declarative-gate.md)).<br>**Gap**: none. |

### Domain 6: Response Generation, Redirects, Cookies & API Resources

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped vs. Gap |
|---|---|---|---|---|---|
| **Response Factory**<br>`docs/repos/laravel/docs/responses.md` | `Illuminate\Contracts\Routing\ResponseFactory`<br>`Illuminate\Routing\ResponseFactory` | Tier 1 | **Partially Mapped** | `responses:` (`src/Response.php`, `Providers/ResponseDeclarationServiceProvider.php`) | **Mapped**: `macro($name, $reference)` — each macro wraps `$Application->call($reference, $args)` on the shared factory.<br>**Gap**: declarative response *forms* — `make()`, `view()`, `json()`, `noContent()`, `stream()`, `download()` — remain seam-dispatch concerns. |
| **Redirector & Redirect Response**<br>`docs/repos/laravel/docs/responses.md` | `Illuminate\Routing\Redirector`<br>`Illuminate\Http\RedirectResponse` | Tier 1 | **Missing** | `redirect:` | **Gap**: `route()`, `to()`, `back()`, `away()`, `action()`, and `RedirectResponse` chaining (`with()`, `withCookies()`, `withInput()`, `withErrors()`). Blocked in Tier 2 until mapped: the `DeclaredAction` seam spec ([declarative-action.md](declarative-action.md)) consumes it. |
| **Cookie Jar**<br>`docs/repos/laravel/docs/responses.md` | `Illuminate\Cookie\CookieJar` | Tier 1 | **Missing** | `cookie:` | **Gap**: `make()`, `forever()`, `forget()`, queueing cookies. |
| **API Resources**<br>`docs/repos/laravel/docs/eloquent-resources.md` | `Illuminate\Http\Resources\Json\JsonResource` | Tier 1 | **Missing** | `resources:` | **Gap**: declarative model-to-JSON transformations and collection wrapping. |
| **Action Controller Seam**<br>`docs/declarative-action.md` | `Illuminate\Routing\Controller` | Tier 2 | **Spec Only** | `DeclaredAction` | **Gap**: no `src/DeclaredAction.php` exists; the seam spec ([declarative-action.md](declarative-action.md)) defines the mutation + PRG redirect lifecycle. Strictly sequenced after Tier 1 `db:` transactions and `redirect:`. |

### Domain 7: Database Connection, Query Builder, Transactions & Seeding

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped vs. Gap |
|---|---|---|---|---|---|
| **Database Connection & Transactions**<br>`docs/repos/laravel/docs/database.md` | `Illuminate\Database\DatabaseManager`<br>`Illuminate\Database\Connection` | Tier 1 | **Partially Mapped** | `db:` (`src/Database.php`, `Providers/DatabaseDeclarationServiceProvider.php`) | **Mapped**: `connection` (listener scoping) and `listen` (query listeners via `DatabaseManager::listen()`).<br>**Gap**: `transaction()`, `statement()`, `unprepared()`, `beforeExecuting()`. |
| **Database Query Builder**<br>`docs/repos/laravel/docs/queries.md` | `Illuminate\Database\Query\Builder` | Tier 1 | **Missing** | `db_queries:` / `queries.table` | **Gap**: table-level direct queries (`DB::table(...)`) bypassing Eloquent models. |
| **Database Schema & Blueprint**<br>`docs/repos/laravel/docs/migrations.md` | `Illuminate\Database\Schema\Builder`<br>`Illuminate\Database\Schema\Blueprint` | Tier 1 | **Implemented** | `schema:` (`src/Schema.php`, `src/TableDefinition.php`, `src/BlueprintAction.php`, `Internal/Commands/MigrateCommand.php`) | **Mapped**: the five native `Schema` operations `create`, `table`, `rename`, `drop`, `dropIfExists` with guard-derived idempotency (`hasTable`/`hasColumn`/`hasIndex`/`hasForeignKey`); all `Blueprint` column types, column modifiers, FK modifiers, index declarations, and table options dispatch dynamically through `BlueprintAction` sequential dispatch onto whatever the Blueprint actually returned (no static whitelists) ([declarative-schema-table-operations.md](declarative-schema-table-operations.md)).<br>**Gap**: none on the mapped surface; `ColumnDefinition::change()` is pinned by test. |
| **Database Seeding & Factories**<br>`docs/repos/laravel/docs/seeding.md` | `Illuminate\Database\Seeder`<br>`Illuminate\Database\Eloquent\Factories\Factory` | Tier 1 | **Missing** | `seeds:` | **Gap**: declarative record insertion or factory sequence definitions. |

### Domain 8: Eloquent ORM, Relationships, Casts & Synthesis

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped vs. Gap |
|---|---|---|---|---|---|
| **Eloquent Model Configuration**<br>`docs/repos/laravel/docs/eloquent.md` | `Illuminate\Database\Eloquent\Model` | Tier 1 | **Implemented** | `models:` (`src/Model.php`) | **Mapped**: `table`, `primaryKey`, `keyType`, `incrementing`, `timestamps`, `dateFormat`, `attributes`, `casts`, `fillable`, `guarded`, `hidden`, `visible`, `appends`, `with`, `withCount`, `touches`, `refreshes`, `perPage`, `connection`, `dispatchesEvents`, `observables`, `observe`, `addGlobalScope`, `getRouteKeyName` — injected by `DeclaredModel::__construct()` before `bootIfNotBooted()` ([declarative-model.md](declarative-model.md)).<br>**Decided non-goals** ([declarative-model-configuration.md](declarative-model-configuration.md), grounded in v13.33.0): `relations` (methods in the class; `guessBelongsToRelation()` misnames Closure relations; pivot/`ofMany` chain syntax is a DSL), `casts()` method dispatch (merged over `$casts` by Laravel, so the property key composes), `Attribute` accessors/mutators (class methods; `appends`/`with` name them), local scopes (`scope*` class methods), `booted()`/`booting()` (static hooks; `observe` covers event registration), `prunable()` (requires the `Prunable`/`MassPrunable` trait). Custom cast classes remain usable as string `casts` values. |
| **Eloquent Query Builder**<br>`docs/repos/laravel/docs/queries.md` | `Illuminate\Database\Eloquent\Builder` | Tier 1 | **Partially Mapped** | `queries:` (`src/Query.php`) | **Mapped**: dynamic dispatch — each clause key is the native `Builder`/`Relation` method name, forwarded as `$target->{$method}(...$args)` with native `BadMethodCallException` propagation (fail-where-Laravel-fails); roots are `model` (class-string) or `relation` (`param.relation`) nouns; list/map/flag argument shapes pass through untouched.<br>**Gap**: the `relation` root reads route parameters directly inside the Tier 1 evaluation (Rule 5 tension — contextual argument binding belongs in Tier 2); subquery closures and raw SQL expressions (`whereRaw`, `selectRaw`) are strings-only. |
| **Dynamic Query Seam**<br>`docs/declarative-query.md` | `Illuminate\Database\Eloquent\Builder` | Tier 2 | **Implemented** | `DeclaredQuery` (`src/DeclaredQuery.php`) | **Mapped**: evaluates the query definition, binds request parameters, executes against the root, and returns results to `DeclaredView`/`DeclaredAction` consumers.<br>**Gap**: none beyond the Tier 1 `relation`-root note above. |
| **Dynamic Model Synthesis**<br>`docs/declarative-model.md` | `Illuminate\Database\Eloquent\Model` | Tier 2 | **Partially Mapped** | `DeclaredModel` (`src/DeclaredModel.php`) | **Mapped**: abstract base class injecting every declared `models:` key (`__construct()` property injection, `resolveObserveAttributes()`, `resolveGlobalScopeAttributes()`, `isIgnoringTouch()` overrides, `getRouteKeyName()`); zero-file declaration covered by `tests/Feature/DeclaredModelTest.php` for on-disk fixtures.<br>**Gap**: the zero-PHP runtime synthesis autoloader — when `Manifest::$models` contains a class-string absent from disk, synthesize a subclass of `DeclaredModel` (roadmap Phase 2; hook point `LaravelDeclarationProvider::register()`), with collision guards against disk classes. Synthesized classes carry the declared keys only; relations/scopes/accessors remain class-body concerns. |
| **Full-Text Search (Scout)**<br>`docs/repos/laravel/docs/scout.md` | `Laravel\Scout\Searchable` | Tier 1 | **Missing** | `scout:` | **Gap**: `search()`, search index configuration, searchable array definitions. |

### Domain 9: Security, Identity, Authentication & Authorization Gates

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped vs. Gap |
|---|---|---|---|---|---|
| **Authorization Gate**<br>`docs/repos/laravel/docs/authorization.md` | `Illuminate\Contracts\Auth\Access\Gate`<br>`Illuminate\Auth\Access\Gate` | Tier 1 | **Partially Mapped** | `gate:` (`src/Gate.php`, `Providers/GateDeclarationServiceProvider.php`) | **Mapped**: `define()`, `policy()` — one call per entry, applied when the Gate first resolves (`callAfterResolving`); the `DeclaredRequest` `authorize` map form consumes them through the native contract ([declarative-gate.md](declarative-gate.md)).<br>**Gap**: `before()`, `after()`, `resource()`, `allowIf()`/`denyIf()`, `guessPolicyNamesUsing()`. |
| **Authentication Guards**<br>`docs/repos/laravel/docs/authentication.md` | `Illuminate\Auth\AuthManager` | Tier 1 | **Missing** | `auth:` | **Gap**: `guard()`, `provider()`, `shouldUse()`, default driver selection. |
| **Session Manager & Store**<br>`docs/repos/laravel/docs/session.md` | `Illuminate\Session\SessionManager`<br>`Illuminate\Session\Store` | Tier 1 | **Missing** | `session:` | **Gap**: `flash()`, `now()`, `reflash()`, `keep()`, `put()`, `get()`. |
| **Hashing & Encryption**<br>`docs/repos/laravel/docs/hashing.md`<br>`encryption.md` | `Illuminate\Hashing\HashManager`<br>`Illuminate\Encryption\Encrypter` | Tier 1 | **Missing** | `hashing:`, `encryption:` | **Gap**: `make()`, `encrypt()`, `decrypt()`. |
| **API Token Authentication (Sanctum)**<br>`docs/repos/laravel/docs/sanctum.md` | `Laravel\Sanctum\HasApiTokens`<br>`Laravel\Sanctum\Sanctum` | Tier 1 | **Missing** | `sanctum:` | **Gap**: token abilities, expiration configuration, stateful domain bindings. |

### Domain 10: Events, Listeners & Async Communication

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped vs. Gap |
|---|---|---|---|---|---|
| **Event Dispatcher**<br>`docs/repos/laravel/docs/events.md` | `Illuminate\Events\Dispatcher` | Tier 1 | **Missing** | `events:` | **Gap**: `listen()`, `subscribe()`, `dispatch()`, `until()`. |
| **Queues & Jobs**<br>`docs/repos/laravel/docs/queues.md` | `Illuminate\Queue\QueueManager`<br>`Illuminate\Bus\Dispatcher` | Tier 1 | **Missing** | `queues:`, `bus:` | **Gap**: `push()`, `later()`, `dispatch()`, `dispatchSync()`. |
| **Mail & Notifications**<br>`docs/repos/laravel/docs/mail.md`<br>`notifications.md` | `Illuminate\Mail\MailManager`<br>`Illuminate\Notifications\ChannelManager` | Tier 1 | **Missing** | `mail:`, `notifications:` | **Gap**: `send()`, `to()`, notification channel routing. |
| **Broadcasting**<br>`docs/repos/laravel/docs/broadcasting.md` | `Illuminate\Broadcasting\BroadcastManager` | Tier 1 | **Missing** | `broadcasting:` | **Gap**: channel routes, broadcaster driver configuration. |

### Domain 11: Operations, Console, Storage, Logging & Systems

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped vs. Gap |
|---|---|---|---|---|---|
| **Artisan Console**<br>`docs/repos/laravel/docs/artisan.md` | `Illuminate\Console\Application` | Tier 1 | **Missing** | `commands:` | **Gap**: declarative custom command registration (`declaration:migrate`, `declaration:validate`, and the install tooling ship as internal package commands, not as a manifest surface). |
| **Task Scheduling**<br>`docs/repos/laravel/docs/scheduling.md` | `Illuminate\Console\Scheduling\Schedule` | Tier 1 | **Missing** | `schedule:` | **Gap**: `command()`, `job()`, `call()`, `daily()`, `hourly()`. |
| **Cache Manager**<br>`docs/repos/laravel/docs/cache.md` | `Illuminate\Cache\CacheManager`<br>`Illuminate\Contracts\Cache\Repository` | Tier 1 | **Missing** | `cache:` | **Gap**: store configuration, cache tagging, declarative memoization keys. |
| **Filesystem & Storage**<br>`docs/repos/laravel/docs/filesystem.md` | `Illuminate\Filesystem\FilesystemManager` | Tier 1 | **Missing** | `storage:` | **Gap**: `disk()`, `build()`, disk driver configuration. |
| **Localization & Translation**<br>`docs/repos/laravel/docs/localization.md` | `Illuminate\Translation\Translator` | Tier 1 | **Missing** | `lang:` | **Gap**: `addLines()`, `addJsonPath()`, `setLocale()`. |
| **Logging & Context**<br>`docs/repos/laravel/docs/logging.md`<br>`context.md` | `Illuminate\Log\LogManager`<br>`Illuminate\Log\Context\Repository` | Tier 1 | **Missing** | `logging:`, `context:` | **Gap**: `channel()`, `Context::add()`. |
| **Application Telemetry & Monitoring (Pulse)**<br>`docs/repos/laravel/docs/pulse.md` | `Laravel\Pulse\Pulse` | Tier 1 | **Missing** | `pulse:` | **Gap**: recorders configuration, slow query thresholds, user resolvers. |

### Domain 12: Processes, Concurrency & Extensibility

| Subsystem & Doc Reference | System of Record (Laravel Class) | Tier | Status | Manifest Key / Seam Class | Mapped vs. Gap |
|---|---|---|---|---|---|
| **Processes & Concurrency**<br>`docs/repos/laravel/docs/processes.md`<br>`concurrency.md` | `Illuminate\Process\Factory`<br>`Illuminate\Concurrency\ConcurrencyManager` | Tier 1 | **Missing** | `process:`, `concurrency:` | **Gap**: `run()`, `pool()`, `concurrency()->run()`. |
| **HTTP Client**<br>`docs/repos/laravel/docs/http-client.md` | `Illuminate\Http\Client\Factory` | Tier 1 | **Missing** | `http:` | **Gap**: `baseUrl()`, `withHeaders()`, `macro()`. |
| **Exception Handling**<br>`docs/repos/laravel/docs/errors.md` | `Illuminate\Contracts\Debug\ExceptionHandler` | Tier 1 | **Missing** | `exceptions:` | **Gap**: `renderable()`, `reportable()`, `dontFlash()`. |
| **Feature Flags**<br>`docs/repos/laravel/docs/pennant.md` | `Laravel\Pennant\FeatureManager` | Tier 1 | **Missing** | `features:` | **Gap**: `define()`, feature-based route middleware. |
| **Full-Text Search (Scout)**<br>`docs/repos/laravel/docs/scout.md` | `Laravel\Scout\Searchable` | Tier 1 | **Missing** | `scout:` | **Gap**: `search()`, searchable array definitions, index configuration. |