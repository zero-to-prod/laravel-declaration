# Declarative HTTP Kernel — `Illuminate\Foundation\Http\Kernel` Middleware Pipeline, Groups, Priority & Manifest Schema

Source of truth: `vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php` (`laravel/framework` v13.33.0), with `Illuminate/Contracts/Http/Kernel.php`, `Illuminate/Routing/Router.php`, `Illuminate/Routing/Pipeline.php`, `Illuminate/Routing/SortedMiddleware.php`, `Illuminate/Routing/MiddlewareNameResolver.php`, `Illuminate/Foundation/Configuration/ApplicationBuilder.php`, `Illuminate/Foundation/Configuration/Middleware.php`, `Illuminate/Foundation/Events/Terminating.php`, `Illuminate/Foundation/Http/Events/RequestHandled.php`, and `Illuminate/Foundation/Application.php`.

Grounding documentation: `docs/repos/laravel/docs/lifecycle.md`, `docs/repos/laravel/docs/middleware.md`, `docs/repos/laravel/docs/routing.md`, and `docs/declarative-request-to-view-roadmap.md` §3 Phase 5.

Goal: a `kernel:` block in `manifest/app.yml` whose **keys map 1:1 onto `Kernel` method names** and whose **values map 1:1 onto those methods' signatures**. The HTTP Kernel is the **system of record** for HTTP request orchestration in Laravel: it holds the global middleware pipeline, route middleware groups, middleware aliases, priority sorting order, and request duration lifecycle handlers. The provider applies the declaration to the container's bound `Illuminate\Contracts\Http\Kernel` instance via `callAfterResolving(KernelContract::class, ...)`. This lifecycle position guarantees that declared middleware configurations apply after `ApplicationBuilder::withMiddleware()` initializes Laravel's default stacks, ensuring that route middleware mutations automatically invoke `Kernel::syncMiddlewareToRouter()` to keep `Illuminate\Routing\Router` in sync (§1.1, §1.4).

---

## 1. Public API of `Kernel::class`

### 1.1 Lifecycle position (when the declaration runs)

```
// 1. Application initialization (bootstrap/app.php)
new Application($basePath);
ApplicationBuilder::withKernels()                  $app->singleton(HttpKernelContract::class, HttpKernel::class)
ApplicationBuilder::withMiddleware($callback)     $app->afterResolving(HttpKernel::class, fn ($k) => ... default middleware ...)

// 2. Provider registration & boot
registerConfiguredProviders()                     eager providers: register()
$app->boot()                                      bootProviders()
    LaravelDeclarationProvider::boot()
        $this->callAfterResolving(                <- queues afterResolving hook for KernelContract::class
            KernelContract::class,
            fn ($kernel) => registerKernel(...)
        )

// 3. Request entry point (public/index.php or test runner $this->get('/'))
$kernel = $app->make(HttpKernelContract::class)   <- Kernel resolves HERE
    Kernel::__construct($app, $router)            Kernel.php:123: syncMiddlewareToRouter()
    ApplicationBuilder afterResolving callback    ApplicationBuilder.php:289:
                                                      $kernel->setGlobalMiddleware(...)
                                                      $kernel->setMiddlewareGroups(...)
                                                      $kernel->setMiddlewareAliases(...)
                                                      $kernel->setMiddlewarePriority(...)
    LaravelDeclarationProvider afterResolving    <- the `kernel:` block is applied HERE:
                                                      $kernel->setGlobalMiddleware(...) / pushMiddleware(...) / prependMiddleware(...)
                                                      $kernel->setMiddlewareGroups(...) / appendMiddlewareToGroup(...) / prependMiddlewareToGroup(...)
                                                      $kernel->setMiddlewareAliases(...)
                                                      $kernel->setMiddlewarePriority(...) / prependTo... / appendTo... / addTo...Before / After
                                                      $kernel->whenRequestLifecycleIsLongerThan(...)
                                                      -> group, alias, priority, and setGlobalMiddleware mutators call syncMiddlewareToRouter() (Kernel.php:519)

// 4. Request handling
$kernel->handle($request)                         Kernel.php:137
    $this->requestStartedAt = Carbon::now()
    $request->enableHttpMethodParameterOverride()
    sendRequestThroughRouter($request)            Kernel.php:164
        $app->instance('request', $request)
        Request::clearResolvedInstance()
        bootstrap()                               $app->bootstrapWith($bootstrappers) (if not already bootstrapped)
        Pipeline::send($request)
            ->through(globalMiddleware)           Kernel.php:177: global middleware executes in array order
            ->then(dispatchToRouter())
                Router::dispatch($request)        Router.php:798
                    gatherRouteMiddleware()       Route middleware resolved, exclusions stripped,
                                                  sorted by SortedMiddleware using Kernel::$middlewarePriority
                    Route action executes         Controller / Closure / DeclaredView
    RequestHandled event dispatched               Kernel.php:151

// 5. Termination & lifecycle handlers
$kernel->terminate($request, $response)           Kernel.php:211
    Terminating event dispatched
    terminateMiddleware($request, $response)      Kernel.php:243: calls terminate() on terminable route + global middleware
    $app->terminate()                             calls $app->terminating() callbacks
    request duration handlers evaluated           Kernel.php:227: checks requestStartedAt diffInMilliseconds against threshold
    $this->requestStartedAt = null
```

Consequences, each verified against `laravel/framework` v13.33.0:

1. **Applied after Laravel's default middleware builder.** `ApplicationBuilder::withMiddleware()` registers its container `afterResolving` callback inside `bootstrap/app.php`, before any service provider boots. `LaravelDeclarationProvider::boot()` registers its `callAfterResolving(KernelContract::class, ...)` hook during provider booting. Because `Container::$afterResolvingCallbacks` executes in FIFO order (`Container.php:1510`), Laravel's default global stack (`TrimStrings`, `ConvertEmptyStringsToNull`, etc.) and default groups (`web`, `api`) are initialized first. The `kernel:` declaration then mutates or replaces them deterministically.
2. **Router synchronization is automatic and durable.** `Router::middlewareGroup()`, `aliasMiddleware()`, and `$middlewarePriority` are kept in sync by `Kernel::syncMiddlewareToRouter()` (Kernel.php:519). As established in `declarative-request-to-view-roadmap.md` §3 Phase 5, declaring middleware on `Router` directly is fragile because any subsequent call on `Kernel` rewrites the Router's state. Declaring on `Kernel` ensures that every mutation invokes `syncMiddlewareToRouter()`, making the Router's state durable.
3. **Global middleware runs outside priority sorting.** `Pipeline` pipes the incoming request through `$this->middleware` in direct list order before calling `dispatchToRouter()` (Kernel.php:177). Global middleware is not passed through `SortedMiddleware`. Route middleware, by contrast, is gathered per matched route, flattened from groups and aliases, filtered by `withoutMiddleware`, and sorted strictly by `$this->middlewarePriority` (Router.php:832).
4. **Console commands and Octane workers resolve once.** In long-lived environments (Laravel Octane, queue workers, PHP CLI), the `KernelContract` singleton is resolved once. If resolved prior to `LaravelDeclarationProvider::boot()`, `ServiceProvider::callAfterResolving()` invokes the configuration closure immediately (ServiceProvider.php:314). When `terminate()` runs, `$this->requestStartedAt` is reset to `null` (Kernel.php:234), preventing state leakage across requests.

---

### 1.2 Properties

**Declaration targets.** Every property represents durable HTTP request middleware state or lifecycle hooks on `Illuminate\Foundation\Http\Kernel`.

| Property | Type | Default | Mutated by method | Read by |
|---|---|---|---|---|
| `$middleware` | `array<int, class-string>` | `[]` | `pushMiddleware()`, `prependMiddleware()`, `setGlobalMiddleware()` | `getGlobalMiddleware()`, `hasMiddleware()`, `sendRequestThroughRouter()`, `terminateMiddleware()` |
| `$middlewareGroups` | `array<string, array<int, class-string>>` | `[]` | `appendMiddlewareToGroup()`, `prependMiddlewareToGroup()`, `setMiddlewareGroups()` | `getMiddlewareGroups()`, `syncMiddlewareToRouter()` |
| `$middlewareAliases` | `array<string, class-string>` | `[]` | `setMiddlewareAliases()` | `getMiddlewareAliases()`, `syncMiddlewareToRouter()` |
| `$middlewarePriority` | `array<int, class-string>` | `[HandlePrecognitiveRequests::class, EncryptCookies::class, ...]` (11 framework classes) | `setMiddlewarePriority()`, `prependToMiddlewarePriority()`, `appendToMiddlewarePriority()`, `addToMiddlewarePriorityBefore()`, `addToMiddlewarePriorityAfter()` | `getMiddlewarePriority()`, `syncMiddlewareToRouter()` |
| `$requestLifecycleDurationHandlers` | `array<int, array{threshold: float\|int, handler: callable}>` | `[]` | `whenRequestLifecycleIsLongerThan()` | `terminate()` |

**Not declaration targets**

| Property | Type | Why no key |
|---|---|---|
| `$app` | `Application` | Container instance, passed via constructor or `setApplication()`. Bound in container. |
| `$router` | `Router` | Router instance, passed via constructor. Bound in container. |
| `$bootstrappers` | `string[]` | Framework bootstrap classes (`LoadEnvironmentVariables`, `LoadConfiguration`, etc.). Executed before service providers register or boot; mutating them in a manifest provider is dead code (§2.6). |
| `$routeMiddleware` | `array` | Deprecated in Laravel 10/11/12/13 in favor of `$middlewareAliases` (Kernel.php:69). Read by `getMiddlewareAliases()`. |
| `$requestStartedAt` | `Carbon\|null` | Ephemeral runtime timestamp initialized in `handle()` and wiped in `terminate()`. |

---

### 1.3 Public methods

**Declaration targets**

| Method | Signature | Effect |
|---|---|---|
| `pushMiddleware` | `(string $middleware): $this` | Appends `$middleware` to the end of the global `$middleware` stack if not already present (`Kernel.php:362`). |
| `prependMiddleware` | `(string $middleware): $this` | Prepends `$middleware` to the start of the global `$middleware` stack if not already present (`Kernel.php:347`). |
| `setGlobalMiddleware` | `(array $middleware): $this` | Replaces the entire global `$middleware` stack wholesale and calls `syncMiddlewareToRouter()` (`Kernel.php:591`). |
| `appendMiddlewareToGroup` | `(string $group, string $middleware): $this` | Appends `$middleware` to group `$group` if not already present. Calls `syncMiddlewareToRouter()`. Throws `InvalidArgumentException` if group does not exist (`Kernel.php:404`). |
| `prependMiddlewareToGroup` | `(string $group, string $middleware): $this` | Prepends `$middleware` to group `$group` if not already present. Calls `syncMiddlewareToRouter()`. Throws `InvalidArgumentException` if group does not exist (`Kernel.php:380`). |
| `setMiddlewareGroups` | `(array $groups): $this` | Replaces all `$middlewareGroups` wholesale and calls `syncMiddlewareToRouter()` (`Kernel.php:616`). |
| `setMiddlewareAliases` | `(array $aliases): $this` | Replaces all `$middlewareAliases` and calls `syncMiddlewareToRouter()` (`Kernel.php:653`). |
| `setMiddlewarePriority` | `(array $priority): $this` | Replaces the entire `$middlewarePriority` list wholesale and calls `syncMiddlewareToRouter()` (`Kernel.php:668`). |
| `prependToMiddlewarePriority` | `(string $middleware): $this` | Prepends `$middleware` to `$middlewarePriority` if not already present. Calls `syncMiddlewareToRouter()` (`Kernel.php:425`). |
| `appendToMiddlewarePriority` | `(string $middleware): $this` | Appends `$middleware` to `$middlewarePriority` if not already present. Calls `syncMiddlewareToRouter()` (`Kernel.php:442`). |
| `addToMiddlewarePriorityBefore` | `(array\|string $before, string $middleware): $this` | Inserts `$middleware` immediately before `$before` in `$middlewarePriority`. Calls `syncMiddlewareToRouter()` (`Kernel.php:460`). |
| `addToMiddlewarePriorityAfter` | `(array\|string $after, string $middleware): $this` | Inserts `$middleware` immediately after `$after` in `$middlewarePriority`. Calls `syncMiddlewareToRouter()` (`Kernel.php:472`). |
| `whenRequestLifecycleIsLongerThan` | `($threshold, callable $handler): void` | Registers `$handler` to be invoked in `terminate()` if request handling duration exceeds `$threshold` (milliseconds, `DateTimeInterface`, or `CarbonInterval`) (`Kernel.php:272`). |

**Not declaration targets**

| Method | Signature | Why no key |
|---|---|---|
| `handle` | `($request): Response` | Runtime HTTP request entry point. |
| `bootstrap` | `(): void` | Runtime bootstrap trigger called by `sendRequestThroughRouter()`. |
| `terminate` | `($request, $response): void` | Runtime HTTP response termination and cleanup hook. |
| `hasMiddleware` | `(string $middleware): bool` | Read-only query against `$this->middleware`. |
| `getGlobalMiddleware` | `(): array` | Read-only inspection method. |
| `getMiddlewareGroups` | `(): array` | Read-only inspection method. |
| `getMiddlewareAliases` | `(): array` | Read-only inspection method. |
| `getRouteMiddleware` | `(): array` | Deprecated read-only alias for `getMiddlewareAliases()`. |
| `getMiddlewarePriority` | `(): array` | Read-only inspection method. |
| `requestStartedAt` | `(): ?Carbon` | Ephemeral runtime state getter. |
| `getApplication` / `setApplication` | `(): Application` / `(Application): $this` | Container binding management. |

---

### 1.4 How a declaration reaches the Kernel, Router, and Pipeline

```php
// Kernel.php:123 — instantiation syncs defaults to Router
public function __construct(Application $app, Router $router)
{
    $this->app = $app;
    $this->router = $router;

    $this->syncMiddlewareToRouter();
}

// Kernel.php:519 — called by every mutator
protected function syncMiddlewareToRouter()
{
    $this->router->middlewarePriority = $this->middlewarePriority;

    foreach ($this->middlewareGroups as $key => $middleware) {
        $this->router->middlewareGroup($key, $middleware);
    }

    foreach (array_merge($this->routeMiddleware, $this->middlewareAliases) as $key => $middleware) {
        $this->router->aliasMiddleware($key, $middleware);
    }
}

// Kernel.php:164 — pipeline sends request through global middleware
protected function sendRequestThroughRouter($request)
{
    $this->app->instance('request', $request);
    Request::clearResolvedInstance();
    $this->bootstrap();

    return (new Pipeline($this->app))
        ->send($request)
        ->through($this->app->shouldSkipMiddleware() ? [] : $this->middleware)
        ->then($this->dispatchToRouter());
}

// Router.php:832 — route middleware gathered and sorted by priority
public function gatherRouteMiddleware(Route $route)
{
    return $this->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());
}

// Router.php:885
protected function sortMiddleware(Collection $middlewares)
{
    return (new SortedMiddleware($this->middlewarePriority, $middlewares))->all();
}

// Kernel.php:211 — termination executes terminable middleware and duration handlers
public function terminate($request, $response)
{
    $this->app['events']->dispatch(new Terminating);
    $this->terminateMiddleware($request, $response);
    $this->app->terminate();

    if ($this->requestStartedAt === null) {
        return;
    }

    $this->requestStartedAt->setTimezone($this->app['config']->get('app.timezone') ?? 'UTC');

    foreach ($this->requestLifecycleDurationHandlers as ['threshold' => $threshold, 'handler' => $handler]) {
        $end ??= Carbon::now();

        if ($this->requestStartedAt->diffInMilliseconds($end) > $threshold) {
            $handler($this->requestStartedAt, $request, $response);
        }
    }

    $this->requestStartedAt = null;
}
```

Consequences, each verified against `laravel/framework` v13.33.0:

1. **`setMiddlewareAliases` replaces `$kernel->middlewareAliases` wholesale.** Passing an alias map replaces `$this->middlewareAliases` on the Kernel and calls `Router::aliasMiddleware()` on each key. Route definitions referencing `middleware: ['subscribed']` resolve to the configured class string (Router.php:832). On the Router, previously aliased classes persist because `Router::aliasMiddleware()` adds to `$this->middleware` rather than clearing it.
2. **`appendMiddlewareToGroup` / `prependMiddlewareToGroup` enforce defined groups.** Appending to a group not already defined throws `InvalidArgumentException: The [{group}] middleware group has not been defined.` (Kernel.php:382, 406). To declare an entirely new middleware group, `setMiddlewareGroups` must be used first or the group must be seeded by Laravel's defaults (`web`, `api`).
3. **`addToMiddlewarePriorityBefore` / `addToMiddlewarePriorityAfter` position middleware relative to anchors.** `Kernel::addToMiddlewarePriorityRelative()` locates the target anchor middleware, splices the new middleware into the specified index, and resyncs `Router::$middlewarePriority`. `SortedMiddleware` guarantees that when both middleware are assigned to a route, the higher priority middleware executes first regardless of assignment order on the route (SortedMiddleware.php:51).
4. **`whenRequestLifecycleIsLongerThan` receives request context.** Duration handlers receive `(Carbon $startedAt, Request $request, Response $response)`. The handler is resolved through `Container::call()`, supporting dependency injection and invokable classes.
5. **Terminable middleware executes without explicit declaration.** Any middleware class implementing `terminate($request, $response)` resolved in either global middleware or gathered route middleware is called during `$kernel->terminate()` (Kernel.php:258).

---

### 1.5 The PHP this replaces

#### Laravel 11/12/13 (`bootstrap/app.php`)
```php
use App\Http\Middleware\EnforceJson;
use App\Http\Middleware\EnsureTokenIsValid;
use App\Http\Middleware\EnsureUserIsSubscribed;
use App\Http\Middleware\FirstGlobal;
use App\Http\Middleware\TrackWebActivity;
use App\Listeners\ReportSlowRequest;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->prepend(FirstGlobal::class);
    $middleware->append(EnsureTokenIsValid::class);

    $middleware->appendToGroup('web', [
        TrackWebActivity::class,
    ]);

    $middleware->alias([
        'subscribed' => EnsureUserIsSubscribed::class,
    ]);

    $middleware->appendToPriorityList(
        after: SubstituteBindings::class,
        append: EnforceJson::class,
    );
})
```

#### Laravel 10 and prior (`app/Http/Kernel.php`)
```php
namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $middleware = [
        \App\Http\Middleware\FirstGlobal::class,
        \App\Http\Middleware\EnsureTokenIsValid::class,
    ];

    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\TrackWebActivity::class,
        ],
    ];

    protected $middlewareAliases = [
        'subscribed' => \App\Http\Middleware\EnsureUserIsSubscribed::class,
    ];
}
```

---

## 2. Manifest schema proposal

### 2.1 Design rule

Every key in `kernel:` is a method name on `Illuminate\Foundation\Http\Kernel`. A list value represents sequential single-argument method calls (e.g. `pushMiddleware`), a map value represents dual-argument or associative method calls (e.g. `appendMiddlewareToGroup`, `setMiddlewareAliases`), and a nullable scalar or list represents a replacement setter (e.g. `setGlobalMiddleware`). No custom DSL keywords exist.

### 2.2 Values

- `pushMiddleware`: `list<class-string>`. Sequentially calls `$kernel->pushMiddleware($middleware)`.
- `prependMiddleware`: `list<class-string>`. Sequentially calls `$kernel->prependMiddleware($middleware)`.
- `setGlobalMiddleware`: `list<class-string>`. Replaces the entire global stack via `$kernel->setGlobalMiddleware($middleware)`.
- `appendMiddlewareToGroup`: `map<string, class-string | list<class-string>>`. Appends each class to the named group via `$kernel->appendMiddlewareToGroup($group, $class)`.
- `prependMiddlewareToGroup`: `map<string, class-string | list<class-string>>`. Prepends each class to the named group via `$kernel->prependMiddlewareToGroup($group, $class)`.
- `setMiddlewareGroups`: `map<string, list<class-string>>`. Replaces all groups via `$kernel->setMiddlewareGroups($groups)`.
- `setMiddlewareAliases`: `map<string, class-string>`. Sets or overrides aliases via `$kernel->setMiddlewareAliases($aliases)`.
- `setMiddlewarePriority`: `list<class-string>`. Replaces the priority order via `$kernel->setMiddlewarePriority($priority)`.
- `prependToMiddlewarePriority`: `list<class-string>`. Prepends to priority order via `$kernel->prependToMiddlewarePriority($class)`.
- `appendToMiddlewarePriority`: `list<class-string>`. Appends to priority order via `$kernel->appendToMiddlewarePriority($class)`.
- `addToMiddlewarePriorityBefore`: `map<class-string, class-string | list<class-string>>`. Maps anchor `$before` to `$middleware`, calling `$kernel->addToMiddlewarePriorityBefore($before, $class)`.
- `addToMiddlewarePriorityAfter`: `map<class-string, class-string | list<class-string>>`. Maps anchor `$after` to `$middleware`, calling `$kernel->addToMiddlewarePriorityAfter($after, $class)`.
- `whenRequestLifecycleIsLongerThan`: `map<int|float|string, class-string | reference>`. Key is threshold in milliseconds or interval string; value is invokable reference or callable class string.

### 2.3 Full example

```yaml
# yaml-language-server: $schema=./manifest.schema.json

kernel:
  prependMiddleware:
    - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\GlobalFirstMiddleware

  pushMiddleware:
    - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\GlobalLastMiddleware

  appendMiddlewareToGroup:
    web:
      - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\TrackWebActivity
    api:
      - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\EnforceJsonResponse

  prependMiddlewareToGroup:
    web:
      - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\WebMaintenanceBypass

  setMiddlewareAliases:
    subscribed: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\EnsureUserIsSubscribed
    token_auth: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\EnsureTokenIsValid

  prependToMiddlewarePriority:
    - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\UltraHighPriorityMiddleware

  appendToMiddlewarePriority:
    - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\UltraLowPriorityMiddleware

  addToMiddlewarePriorityBefore:
    Illuminate\Routing\Middleware\SubstituteBindings: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\PreSubstituteBindingsMiddleware

  addToMiddlewarePriorityAfter:
    Illuminate\Routing\Middleware\SubstituteBindings: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\PostSubstituteBindingsMiddleware

  whenRequestLifecycleIsLongerThan:
    250: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\SlowRequestReporter
```

---

### 2.4 Key → member → signature map

| YAML key | Kernel member | Target type | Call invoked | Laravel doc counterpart |
|---|---|---|---|---|
| `pushMiddleware` | `pushMiddleware` | `list<class-string>` | `$kernel->pushMiddleware($m)` | `$middleware->append($m)` |
| `prependMiddleware` | `prependMiddleware` | `list<class-string>` | `$kernel->prependMiddleware($m)` | `$middleware->prepend($m)` |
| `setGlobalMiddleware` | `setGlobalMiddleware` | `list<class-string>` | `$kernel->setGlobalMiddleware($list)` | `$middleware->use($list)` |
| `appendMiddlewareToGroup` | `appendMiddlewareToGroup` | `map<string, class-string \| list<class-string>>` | `$kernel->appendMiddlewareToGroup($grp, $m)` | `$middleware->appendToGroup($grp, $m)` |
| `prependMiddlewareToGroup` | `prependMiddlewareToGroup` | `map<string, class-string \| list<class-string>>` | `$kernel->prependMiddlewareToGroup($grp, $m)` | `$middleware->prependToGroup($grp, $m)` |
| `setMiddlewareGroups` | `setMiddlewareGroups` | `map<string, list<class-string>>` | `$kernel->setMiddlewareGroups($grps)` | `$middleware->group($name, $list)` |
| `setMiddlewareAliases` | `setMiddlewareAliases` | `map<string, class-string>` | `$kernel->setMiddlewareAliases($aliases)` | `$middleware->alias($aliases)` |
| `setMiddlewarePriority` | `setMiddlewarePriority` | `list<class-string>` | `$kernel->setMiddlewarePriority($priority)` | `$middleware->priority($list)` |
| `prependToMiddlewarePriority` | `prependToMiddlewarePriority` | `list<class-string>` | `$kernel->prependToMiddlewarePriority($m)` | `$middleware->prependToPriorityList(...)` |
| `appendToMiddlewarePriority` | `appendToMiddlewarePriority` | `list<class-string>` | `$kernel->appendToMiddlewarePriority($m)` | `$middleware->appendToPriorityList(...)` |
| `addToMiddlewarePriorityBefore` | `addToMiddlewarePriorityBefore` | `map<class-string, class-string \| list<class-string>>` | `$kernel->addToMiddlewarePriorityBefore($before, $m)` | `$middleware->prependToPriorityList(before: ...)` |
| `addToMiddlewarePriorityAfter` | `addToMiddlewarePriorityAfter` | `map<class-string, class-string \| list<class-string>>` | `$kernel->addToMiddlewarePriorityAfter($after, $m)` | `$middleware->appendToPriorityList(after: ...)` |
| `whenRequestLifecycleIsLongerThan` | `whenRequestLifecycleIsLongerThan` | `map<int\|string, class-string>` | `$kernel->whenRequestLifecycleIsLongerThan($t, $h)` | Request lifecycle duration threshold |

---

### 2.5 Registration algorithm (for the provider)

The provider registers a callback using `$this->callAfterResolving(KernelContract::class, ...)` inside `LaravelDeclarationProvider::boot()`.

```php
// src/LaravelDeclarationProvider.php
if ($Manifest->kernel !== null) {
    $this->callAfterResolving(
        KernelContract::class,
        fn (KernelContract $kernel) => $this->registerKernel($Manifest->kernel, $kernel)
    );
}

private function registerKernel(Kernel $Kernel, KernelContract $kernel): void
{
    if (! $kernel instanceof HttpKernel) {
        return;
    }

    foreach (Kernel::selected(Setter::class) as $method) {
        if ($Kernel->{$method} !== null) {
            $kernel->{$method}($Kernel->{$method});
        }
    }

    foreach (Kernel::selected(Append::class) as $method) {
        foreach ($Kernel->{$method} as $middleware) {
            $kernel->{$method}($middleware);
        }
    }

    foreach (Kernel::selected(Prepend::class) as $method) {
        foreach (array_reverse($Kernel->{$method}) as $middleware) {
            $kernel->{$method}($middleware);
        }
    }

    foreach ($Kernel->appendMiddlewareToGroup as $group => $middlewares) {
        foreach ((array) $middlewares as $middleware) {
            $kernel->appendMiddlewareToGroup($group, $middleware);
        }
    }

    foreach ($Kernel->prependMiddlewareToGroup as $group => $middlewares) {
        foreach (array_reverse((array) $middlewares) as $middleware) {
            $kernel->prependMiddlewareToGroup($group, $middleware);
        }
    }

    foreach ($Kernel->addToMiddlewarePriorityBefore as $before => $middlewares) {
        foreach ((array) $middlewares as $middleware) {
            $kernel->addToMiddlewarePriorityBefore($before, $middleware);
        }
    }

    foreach ($Kernel->addToMiddlewarePriorityAfter as $after => $middlewares) {
        foreach (array_reverse((array) $middlewares) as $middleware) {
            $kernel->addToMiddlewarePriorityAfter($after, $middleware);
        }
    }

    foreach ($Kernel->whenRequestLifecycleIsLongerThan as $threshold => $handler) {
        $kernel->whenRequestLifecycleIsLongerThan(
            is_numeric($threshold) ? +$threshold : CarbonInterval::make($threshold),
            $this->wrapDurationHandler($handler)
        );
    }
}

private function wrapDurationHandler(string $handler): Closure
{
    $callback = $this->reference($handler);

    return fn ($startedAt, $request, $response): mixed => $this->app->call($callback, [
        'startedAt' => $startedAt,
        'request' => $request,
        'response' => $response,
    ]);
}
```

---

### 2.6 Notes / non-goals

- **Route-level middleware remains on `routes:`**. Individual routes assign middleware via `routes[].middleware` (declarative-routing.md §2.4). The `kernel:` block configures the pipeline, groups, aliases, and ordering rules that route definitions reference.
- **Bootstrappers cannot be configured.** `$bootstrappers` in `Illuminate\Foundation\Http\Kernel` are consumed by `Application::bootstrapWith()` during initial application boot. By the time service providers execute `register()` and `boot()`, all bootstrappers have already executed. Changing `$bootstrappers` at provider runtime is dead code.
- **Console Kernel is distinct.** `Illuminate\Foundation\Console\Kernel` handles CLI commands and schedules (`artisan`). It does not run HTTP pipelines, middleware groups, or `syncMiddlewareToRouter()`. A future `console:` block would map onto `ConsoleKernel`.
- **Exception rendering stays in Exception Handler.** Exceptions caught during `Kernel::handle()` delegate to `Illuminate\Contracts\Debug\ExceptionHandler` (`reportException` / `renderException`). This is configured via `ApplicationBuilder::withExceptions()` or provider exception bindings, not `HttpKernel`.
- **Validation.** Value validation occurs through `manifest.schema.json` and the `Kernel::validate()` pre-hook. Unknown keys trigger a `LogicException`. Unknown middleware classes are resolved by Laravel's container at request execution time, raising Laravel's native `BindingResolutionException`.

---

## 3. Implementation plan

### 1. `src/Kernel.php`
Create the DataModel for the `kernel:` block:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use LogicException;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Attributes\Key;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Kernel
{
    use DataModel;

    public const string pushMiddleware = 'pushMiddleware';

    /** @var list<class-string> */
    #[Key, Describe([Describe::pre => [self::class, 'validate'], Describe::default => []])]
    public array $pushMiddleware;

    public const string prependMiddleware = 'prependMiddleware';

    /** @var list<class-string> */
    #[Key, Describe([Describe::default => []])]
    public array $prependMiddleware;

    public const string setGlobalMiddleware = 'setGlobalMiddleware';

    /** @var list<class-string>|null */
    #[Key, Describe([Describe::nullable => true])]
    public ?array $setGlobalMiddleware;

    public const string appendMiddlewareToGroup = 'appendMiddlewareToGroup';

    /** @var array<string, list<class-string>|class-string> */
    #[Key, Describe([Describe::default => []])]
    public array $appendMiddlewareToGroup;

    public const string prependMiddlewareToGroup = 'prependMiddlewareToGroup';

    /** @var array<string, list<class-string>|class-string> */
    #[Key, Describe([Describe::default => []])]
    public array $prependMiddlewareToGroup;

    public const string setMiddlewareGroups = 'setMiddlewareGroups';

    /** @var array<string, list<class-string>>|null */
    #[Key, Describe([Describe::nullable => true])]
    public ?array $setMiddlewareGroups;

    public const string setMiddlewareAliases = 'setMiddlewareAliases';

    /** @var array<string, class-string>|null */
    #[Key, Describe([Describe::nullable => true])]
    public ?array $setMiddlewareAliases;

    public const string setMiddlewarePriority = 'setMiddlewarePriority';

    /** @var list<class-string>|null */
    #[Key, Describe([Describe::nullable => true])]
    public ?array $setMiddlewarePriority;

    public const string prependToMiddlewarePriority = 'prependToMiddlewarePriority';

    /** @var list<class-string> */
    #[Key, Describe([Describe::default => []])]
    public array $prependToMiddlewarePriority;

    public const string appendToMiddlewarePriority = 'appendToMiddlewarePriority';

    /** @var list<class-string> */
    #[Key, Describe([Describe::default => []])]
    public array $appendToMiddlewarePriority;

    public const string addToMiddlewarePriorityBefore = 'addToMiddlewarePriorityBefore';

    /** @var array<class-string, list<class-string>|class-string> */
    #[Key, Describe([Describe::default => []])]
    public array $addToMiddlewarePriorityBefore;

    public const string addToMiddlewarePriorityAfter = 'addToMiddlewarePriorityAfter';

    /** @var array<class-string, list<class-string>|class-string> */
    #[Key, Describe([Describe::default => []])]
    public array $addToMiddlewarePriorityAfter;

    public const string whenRequestLifecycleIsLongerThan = 'whenRequestLifecycleIsLongerThan';

    /** @var array<int|string, class-string|string> */
    #[Key, Describe([Describe::default => []])]
    public array $whenRequestLifecycleIsLongerThan;

    /** @param  array<array-key, mixed>  $context */
    public static function validate(mixed $value, array $context): void
    {
        $unknown = array_diff(array_keys($context), self::selected(Key::class));

        if ($unknown !== []) {
            throw new LogicException(
                'The `kernel` block declares unknown key(s): '.implode(', ', $unknown).
                '. Every key must be an `Illuminate\Foundation\Http\Kernel` method name.'
            );
        }
    }
}
```

---

### 2. `src/Manifest.php`
Add `$kernel` property and constant:

```php
public const string kernel = 'kernel';

#[Describe([Describe::nullable => true])]
public ?Kernel $kernel;
```

---

### 3. `../src/LaravelDeclarationProvider.php`
Register the hook in `boot()`:

```php
if ($Manifest->kernel !== null) {
    $this->callAfterResolving(
        \Illuminate\Contracts\Http\Kernel::class,
        fn (\Illuminate\Contracts\Http\Kernel $kernel) => $this->registerKernel($Manifest->kernel, $kernel)
    );
}
```

Implement `registerKernel()` and `wrapDurationHandler()` as specified in §2.5.

---

### 4. `manifest.schema.json`
Add `"kernel"` property to root schema and the `kernel` definition under `definitions`:

```json
"kernel": {
  "description": "HTTP Kernel configuration mapped 1:1 onto Illuminate\\Foundation\\Http\\Kernel methods.",
  "type": ["object", "null"],
  "additionalProperties": false,
  "properties": {
    "pushMiddleware": {
      "description": "-> pushMiddleware($middleware): Appends to the global middleware stack.",
      "type": "array",
      "items": { "$ref": "#/definitions/classString" }
    },
    "prependMiddleware": {
      "description": "-> prependMiddleware($middleware): Prepends to the global middleware stack.",
      "type": "array",
      "items": { "$ref": "#/definitions/classString" }
    },
    "setGlobalMiddleware": {
      "description": "-> setGlobalMiddleware($middleware): Replaces the global middleware stack.",
      "type": "array",
      "items": { "$ref": "#/definitions/classString" }
    },
    "appendMiddlewareToGroup": {
      "description": "-> appendMiddlewareToGroup($group, $middleware): Appends to a route middleware group.",
      "type": "object",
      "additionalProperties": {
        "anyOf": [
          { "$ref": "#/definitions/classString" },
          { "type": "array", "items": { "$ref": "#/definitions/classString" } }
        ]
      }
    },
    "prependMiddlewareToGroup": {
      "description": "-> prependMiddlewareToGroup($group, $middleware): Prepends to a route middleware group.",
      "type": "object",
      "additionalProperties": {
        "anyOf": [
          { "$ref": "#/definitions/classString" },
          { "type": "array", "items": { "$ref": "#/definitions/classString" } }
        ]
      }
    },
    "setMiddlewareGroups": {
      "description": "-> setMiddlewareGroups($groups): Replaces all route middleware groups.",
      "type": "object",
      "additionalProperties": {
        "type": "array",
        "items": { "$ref": "#/definitions/classString" }
      }
    },
    "setMiddlewareAliases": {
      "description": "-> setMiddlewareAliases($aliases): Defines or overrides middleware aliases.",
      "type": "object",
      "additionalProperties": { "$ref": "#/definitions/classString" }
    },
    "setMiddlewarePriority": {
      "description": "-> setMiddlewarePriority($priority): Replaces the priority order of route middleware.",
      "type": "array",
      "items": { "$ref": "#/definitions/classString" }
    },
    "prependToMiddlewarePriority": {
      "description": "-> prependToMiddlewarePriority($middleware): Prepends to middleware priority list.",
      "type": "array",
      "items": { "$ref": "#/definitions/classString" }
    },
    "appendToMiddlewarePriority": {
      "description": "-> appendToMiddlewarePriority($middleware): Appends to middleware priority list.",
      "type": "array",
      "items": { "$ref": "#/definitions/classString" }
    },
    "addToMiddlewarePriorityBefore": {
      "description": "-> addToMiddlewarePriorityBefore($before, $middleware): Inserts middleware before target anchor.",
      "type": "object",
      "additionalProperties": {
        "anyOf": [
          { "$ref": "#/definitions/classString" },
          { "type": "array", "items": { "$ref": "#/definitions/classString" } }
        ]
      }
    },
    "addToMiddlewarePriorityAfter": {
      "description": "-> addToMiddlewarePriorityAfter($after, $middleware): Inserts middleware after target anchor.",
      "type": "object",
      "additionalProperties": {
        "anyOf": [
          { "$ref": "#/definitions/classString" },
          { "type": "array", "items": { "$ref": "#/definitions/classString" } }
        ]
      }
    },
    "whenRequestLifecycleIsLongerThan": {
      "description": "-> whenRequestLifecycleIsLongerThan($threshold, $handler): Registers duration threshold handler.",
      "type": "object",
      "additionalProperties": { "$ref": "#/definitions/closure" }
    }
  }
}
```

---

### 5. `composer-require-checker.json`
Whitelist `"Illuminate\\Foundation\\Http\\Kernel"` and `"Illuminate\\Contracts\\Http\\Kernel"` if required.

---

### 6. Fixtures
Create test middleware classes in `tests/Fixtures/App/Middleware/`:
- `GlobalFirstMiddleware.php`: sets `X-Global-First` header on response.
- `GlobalLastMiddleware.php`: sets `X-Global-Last` header on response.
- `TrackWebActivity.php`: terminable middleware appending to static spy log.
- `EnsureTokenIsValid.php`: checks `token` parameter, returns 401 if invalid.
- `EnsureUserIsSubscribed.php`: checks `subscribed` header.
- `SlowRequestReporter.php`: duration handler recording slow request events into static spy log.
- `tests/Fixtures/manifest/kernel.yml`: complete YAML manifest containing every `kernel:` key.

---

### 7. Tests (`tests/Feature/KernelRegistrationTest.php`)
Verify:
1. Global middleware execution: `prependMiddleware` runs before `pushMiddleware`, verifying pipeline order.
2. Wholesale replacement: `setGlobalMiddleware` completely replaces default global middleware.
3. Group mutations: `appendMiddlewareToGroup` and `prependMiddlewareToGroup` modify `web` and `api` stacks.
4. Group replacement: `setMiddlewareGroups` defines new custom groups.
5. Aliases: `setMiddlewareAliases` registers aliases usable on route definitions.
6. Priority ordering: `addToMiddlewarePriorityBefore` and `addToMiddlewarePriorityAfter` change execution order in `Router::gatherRouteMiddleware()`.
7. Lifecycle duration handler: `whenRequestLifecycleIsLongerThan` executes when request duration exceeds threshold.
8. Terminable middleware: `terminate()` calls `terminate()` on terminable middleware instances.
9. Negative validation: unknown keys in `kernel:` trigger `LogicException`.
10. Schema validation: `laravel-declaration:validate` passes `kernel.yml`.

---

### 8. Verification & docs
1. Run `composer check` (`pint --test`, `rector --dry-run`, `phpstan analyse`, `pest --coverage --min=100`, `bc-check`).
2. Update `README.md` adding `## Kernel` section.
3. Update `docs/declarative-request-to-view-roadmap.md` marking Phase 5 completed.

---

### Sources

1. HTTP Kernel implementation, middleware pipeline, group sync, priority list, and lifecycle handlers — [Foundation/Http/Kernel.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Foundation/Http/Kernel.php)
2. HTTP Kernel interface contract — [Contracts/Http/Kernel.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Contracts/Http/Kernel.php)
3. Router middleware resolution, gathering, and priority sorting — [Routing/Router.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/Router.php); sorted middleware collection algorithm — [Routing/SortedMiddleware.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/SortedMiddleware.php); middleware name resolution — [Routing/MiddlewareNameResolver.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/MiddlewareNameResolver.php)
4. Pipeline execution — [Routing/Pipeline.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/Pipeline.php)
5. Modern application builder and middleware configuration bridge — [Foundation/Configuration/ApplicationBuilder.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php); middleware configuration collector — [Foundation/Configuration/Middleware.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Foundation/Configuration/Middleware.php)
6. Terminating and request handled events — [Foundation/Events/Terminating.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Foundation/Events/Terminating.php), [Foundation/Http/Events/RequestHandled.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Foundation/Http/Events/RequestHandled.php)
7. Request lifecycle documentation — [docs/repos/laravel/docs/lifecycle.md](repos/laravel/docs/lifecycle.md); middleware documentation — [docs/repos/laravel/docs/middleware.md](repos/laravel/docs/middleware.md)
8. Roadmap Phase 5 specification — [docs/declarative-request-to-view-roadmap.md](declarative-request-to-view-roadmap.md)
