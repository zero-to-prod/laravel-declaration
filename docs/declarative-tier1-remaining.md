# Tier 1 API Map — Remaining Work

Source of truth: `vendor/laravel/framework/src/Illuminate` (`laravel/framework` v13.33.0), verified against package source (`src/`) and feature tests (`tests/Feature/`). Companion to the current-state map in [declarative-framework-api-mapping.md](declarative-framework-api-mapping.md); this document lists only unmapped Tier 1 surface. Tier 2 seam work (`DeclaredAction`, `DeclaredModel` synthesis autoloader, `DeclaredJson`) is out of scope here and tracked in [declarative-request-to-view-roadmap.md](declarative-request-to-view-roadmap.md).

Design rules apply: **key = native method name** (Rule 1), **pass references through untouched** (Rule 2), **fail where Laravel fails** (Rule 3), **no cross-subsystem orchestration** (Rule 5).

---

## 1. Partially Mapped — Close the Remaining Native Surface

### 1.1 `pagination:` (`src/Pagination.php`, `Providers/PaginationDeclarationServiceProvider.php`) — **closed**

Implemented by [declarative-pagination.md](declarative-pagination.md): all five presets (`useTailwind`, `useBootstrap`, `useBootstrapThree`, `useBootstrapFour`, `useBootstrapFive`) plus `defaultView`/`defaultSimpleView` are mapped; the provider dispatches the `#[Preset]` attribute-selected properties dynamically (`Paginator::{$method}()`, the `Kernel`/`Router` `selected()` pattern).

Decided non-goal (runtime plumbing, stays unmapped): the remaining `AbstractPaginator` statics (`resolveCurrentPath`, `currentPageResolver`, `queryStringResolver`, …) — a manifest should not declare them.

### 1.2 `gate:` (`src/Gate.php`, `Providers/GateDeclarationServiceProvider.php`)

Mapped: `define`, `policy` (applied via `callAfterResolving(GateContract::class, …)`).

| Missing native method | Signature | Purpose |
|---|---|---|
| `Gate::before()` | `before(callable $callback): self` | Intercept all authorization checks |
| `Gate::after()` | `after(callable $callback): self` | Post-decision callback |
| `Gate::resource()` | `resource(string $name, string $class, ?array $abilities = null): self` | Register a policy under the conventional ability map (`viewAny`/`view`/`create`/`update`/`delete`) |
| `Gate::allowIf()` | `allowIf($condition, $message = null, $code = null)` | Allow-list a check |
| `Gate::denyIf()` | `denyIf($condition, $message = null, $code = null)` | Deny-list a check |
| `Gate::guessPolicyNamesUsing()` | `guessPolicyNamesUsing(callable $callback): self` | Override policy-name resolution |

Reference strings resolve through the container as the mapped `define`/`policy` entries already do.

### 1.3 `db:` (`src/Database.php`, `Providers/DatabaseDeclarationServiceProvider.php`)

Mapped: `connection` (listener scoping), `listen`.

| Missing native method | Signature | Purpose |
|---|---|---|
| `transaction()` | `transaction(Closure $callback, $attempts = 1)` — `Concerns/ManagesTransactions`, available on `Connection` and `DatabaseManager` | Atomic execution boundary — prerequisite for the `DeclaredAction` write seam |
| `Connection::statement()` | `statement(string $query, array $bindings = []): bool` | Declarative statement execution |
| `Connection::unprepared()` | `unprepared(string $query): bool` | Raw statement execution |
| `Connection::beforeExecuting()` | `beforeExecuting(Closure $callback)` | Pre-execution hook |

### 1.4 `responses:` (`src/Response.php`, `Providers/ResponseDeclarationServiceProvider.php`)

Mapped: `macro`.

Gap: declarative response *forms* on `Illuminate\Contracts\Routing\ResponseFactory` — `make()`, `view()`, `json()`, `noContent()`, `stream()`, `download()`. Until mapped, response creation stays a Tier 2 seam concern (`DeclaredView` already returns through `ResponseFactory::make()`).

### 1.5 `queries:` (`src/Query.php`)

Mapped: dynamic clause dispatch onto `Builder`/`Relation` with native `BadMethodCallException` propagation; roots `model` / `relation`.

Gap:
1. **Rule 5 tension**: the `relation` root resolves `$parameters[$param]` inside Tier 1 evaluation. Contextual argument binding belongs to Tier 2 seams; Tier 1 should accept the resolved owner model as an argument.
2. **Subquery closures / raw expressions**: subquery builders and `whereRaw`/`selectRaw` bindings are strings-only; there is no declarative shape for closure composition (closures exceed the strings-only manifest contract — consistent with the conditional-rules resolution in [declarative-validator.md](declarative-validator.md)).

---

## 2. Missing Manifest Keys — Full Subsystems

Each row is a `[ ]` checklist item in [declarative-framework-api-mapping.md](declarative-framework-api-mapping.md). Proposed key follows Rule 1 (key = native method name, or the native subsystem noun).

### Domain 2

| Proposed key | System of record | Native surface |
|---|---|---|
| `csrf:` | `Illuminate\Foundation\Http\Middleware\ValidateCsrfToken` | URI exclusions (`except`) |
| `precognition:` | `Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests` | Precognitive validation headers, route middleware binding |

### Domain 3

| Proposed key | System of record | Native surface |
|---|---|---|
| `url:` | `Illuminate\Routing\UrlGenerator` | `signedRoute()`, `temporarySignedRoute()`, `forceScheme()`, `forceRootUrl()`, `defaults()` |
| `rate_limiter:` | `Illuminate\Cache\RateLimiter` | `for($name, Closure)`, `attempt()` — one call per named limiter per Rule 2 |

### Domain 6

| Proposed key | System of record | Native surface |
|---|---|---|
| `redirect:` | `Illuminate\Routing\Redirector`, `Illuminate\Http\RedirectResponse` | `route()`, `to()`, `back()`, `away()`, `action()`; chaining `with()`, `withCookies()`, `withInput()`, `withErrors()` — prerequisite for the `DeclaredAction` PRG lifecycle ([declarative-action.md](declarative-action.md)) |
| `cookie:` | `Illuminate\Cookie\CookieJar` | `make()`, `forever()`, `forget()`, queueing |
| `resources:` | `Illuminate\Http\Resources\Json\JsonResource` | Declarative model-to-JSON transformation and collection wrapping |

### Domain 7

| Proposed key | System of record | Native surface |
|---|---|---|
| `queries:` / `queries.table` | `Illuminate\Database\Query\Builder` | Table-level direct queries (`DB::table(...)`) bypassing Eloquent models |
| `seeds:` | `Illuminate\Database\Seeder`, `Illuminate\Database\Eloquent\Factories\Factory` | Record insertion, factory sequence definitions |

### Domain 9

| Proposed key | System of record | Native surface |
|---|---|---|
| `auth:` | `Illuminate\Auth\AuthManager` | `guard()`, `provider()`, `shouldUse()`, default driver |
| `session:` | `Illuminate\Session\SessionManager`, `Illuminate\Session\Store` | `flash()`, `now()`, `reflash()`, `keep()`, `put()`, `get()` |
| `hashing:`, `encryption:` | `Illuminate\Hashing\HashManager`, `Illuminate\Encryption\Encrypter` | `make()`, `encrypt()`, `decrypt()` |
| `sanctum:` | `Laravel\Sanctum\HasApiTokens`, `Laravel\Sanctum\Sanctum` | Token abilities, expiration, stateful domains |

### Domain 10

| Proposed key | System of record | Native surface |
|---|---|---|
| `events:` | `Illuminate\Events\Dispatcher` | `listen()`, `subscribe()`, `dispatch()`, `until()` |
| `queues:`, `bus:` | `Illuminate\Queue\QueueManager`, `Illuminate\Bus\Dispatcher` | `push()`, `later()`, `dispatch()`, `dispatchSync()` |
| `mail:` | `Illuminate\Mail\MailManager` | `send()`, `to()` |
| `notifications:` | `Illuminate\Notifications\ChannelManager` | Channel routing |
| `broadcasting:` | `Illuminate\Broadcasting\BroadcastManager` | Channel routes, broadcaster driver configuration |

### Domain 11

| Proposed key | System of record | Native surface |
|---|---|---|
| `commands:` | `Illuminate\Console\Application` | Custom command registration |
| `schedule:` | `Illuminate\Console\Scheduling\Schedule` | `command()`, `job()`, `call()`, `daily()`, `hourly()`, … |
| `cache:` | `Illuminate\Cache\CacheManager`, `Illuminate\Contracts\Cache\Repository` | Store configuration, tagging |
| `storage:` | `Illuminate\Filesystem\FilesystemManager` | `disk()`, `build()`, driver configuration |
| `image:` | `Illuminate\Image\ImageManager` | Image sources `fromBytes()`, `fromStream()`, `fromBase64()`, `fromPath()`, `fromStorage()`, `fromUpload()`, `fromUrl()`; default driver via `config('images.default')`; custom drivers via `extend()` |
| `redis:` | `Illuminate\Redis\RedisManager`, `Illuminate\Redis\Connections\Connection` | `connection()`, `connections()`, `purge()`, `extend()`, `enableEvents()`/`disableEvents()` |
| `lang:` | `Illuminate\Translation\Translator` | `addLines()`, `addJsonPath()`, `setLocale()` |
| `logging:`, `context:` | `Illuminate\Log\LogManager`, `Illuminate\Log\Context\Repository` | `channel()`, `Context::add()` |
| `pulse:` | `Laravel\Pulse\Pulse` | Recorders, slow query thresholds, user resolvers |

### Domain 12

| Proposed key | System of record | Native surface |
|---|---|---|
| `process:`, `concurrency:` | `Illuminate\Process\Factory`, `Illuminate\Concurrency\ConcurrencyManager` | `run()`, `pool()`, `concurrency()->run()` |
| `http:` | `Illuminate\Http\Client\Factory` | `baseUrl()`, `withHeaders()`, `macro()` |
| `exceptions:` | `Illuminate\Contracts\Debug\ExceptionHandler` | `renderable()`, `reportable()`, `dontFlash()` |
| `features:` | `Laravel\Pennant\FeatureManager` | `define()`, feature-based route middleware |
| `scout:` | `Laravel\Scout\Searchable` | `search()`, searchable arrays, index configuration |

---

## 3. Decided Non-Goals (Not Remaining Work)

Recorded so the gap list above stays honest — these are **not** open Tier 1 items:

- `router.patterns()` — plural batch of the mapped `pattern()` ([declarative-router.md](declarative-router.md) §2.6).
- `routes` verb dispatch keys (`routes.get`/`routes.post`/…) — `methods:` covers verbs natively ([declarative-route-registrars.md](declarative-route-registrars.md) §2.4).
- `models.relations`, `casts()` method dispatch, `Attribute` accessors/mutators, local scopes, `booted()`, `prunable()` — class-body concerns per [declarative-model-configuration.md](declarative-model-configuration.md).
- `Factory::exists()` as a declarative guard — a `bool` is not renderable; reachable at query time via `$__env->exists()` ([declarative-view-factory.md](declarative-view-factory.md)).
- `AbstractPaginator` runtime statics (`resolveCurrentPath`, `currentPageResolver`, `queryStringResolver`, …) — runtime plumbing, not declaration surface.