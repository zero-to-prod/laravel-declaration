# Declarative Router Configuration — `Router` Middleware Registry, Resource Globals & Matched Listeners & Manifest Schema

Source of truth: `vendor/laravel/framework/src/Illuminate/Routing/Router.php` (`laravel/framework` v13.33.0), with `Illuminate/Routing/ResourceRegistrar.php`, `Illuminate/Foundation/Http/Kernel.php`, `Illuminate/Foundation/Configuration/ApplicationBuilder.php`, `Illuminate/Foundation/Configuration/Middleware.php`, `Illuminate/Routing/MiddlewareNameResolver.php`, `Illuminate/Routing/Events/RouteMatched.php`, `Illuminate/Events/Dispatcher.php`, `Illuminate/Routing/Route.php` and `Illuminate/Routing/AbstractRouteCollection.php`.

Grounding documentation: [declarative-router.md](declarative-router.md) (`pattern`), [declarative-router-bindings.md](declarative-router-bindings.md) (`model` / `bind`), [declarative-kernel.md](declarative-kernel.md) (the `kernel:` middleware stack), [declarative-request-to-view-roadmap.md](declarative-request-to-view-roadmap.md) §2 Design Rules, and [declarative-tier1-gap-inventory.md](declarative-tier1-gap-inventory.md) §2.1 — the Tier 1 gap this document resolves.

Goal: close the Tier 1 gap inventory §2.1 row ("Router Configuration & Binders — `router:` — `[/]` — Gap remains — `[/]` (valid)") by mapping the remaining `Illuminate\Routing\Router` configuration methods onto the existing `router:` block. Every **key is a `Router` method name**, every **value is that method's argument(s)**, and the provider applies every new key through **attribute-selected dynamic dispatch** (§2.5): one loop per dispatch shape, zero per-key code. Of the inventory's ten proposed keys, nine are mapped and `patterns()` is resolved as a decided non-goal ([declarative-router.md](declarative-router.md) §2.6 — the map form of `pattern` is that loop). After implementation, inventory §1 row 2 reclassifies `[/]` → `[x]` (§3.7).

---

## 1. Public API of `Router::class` (middleware registry, resource globals, matched listeners)

### 1.1 Lifecycle position (when the declaration runs, when it is read)

The `router:` block touches two registries on the `Router` (`$middleware`, `$middlewareGroups`) and one registry outside it (`ResourceRegistrar`'s statics), plus one event subscription. Three writers contend for the middleware registries; the declaration runs between the first and the third:

```php
// bootstrap/app.php
ApplicationBuilder::withKernels()               $app->singleton(HttpKernelContract::class, HttpKernel::class)
ApplicationBuilder::withMiddleware($callback)   $app->afterResolving(HttpKernel::class, fn ($kernel) =>
                                                    $kernel->setGlobalMiddleware(...) / setMiddlewareGroups(...) /
                                                    setMiddlewareAliases(...) / setMiddlewarePriority(...)
                                                    -> every setter calls syncMiddlewareToRouter())
ApplicationBuilder::withRouting(...)            AppRouteServiceProvider::loadRoutesUsing($using);
                                                $app->booting(register(AppRouteServiceProvider::class))
                                                    // Illuminate\Foundation\Support\Providers\RouteServiceProvider, imported as AppRouteServiceProvider

// public/index.php (or the test runner's first request)
$kernel = $app->make(HttpKernel::class)         Kernel::__construct -> syncMiddlewareToRouter() (defaults still empty),
                                                then the afterResolving callback above:
                                                framework default groups/aliases/priority synced to the router
$kernel->handle($request)
    bootstrapWith([RegisterProviders, BootProviders, ...])
        RegisterProviders                       LaravelDeclarationProvider::register(): the declaration providers register
        BootProviders -> $app->boot()           providers boot in registration order (DefaultProviders):
            RouterDeclarationServiceProvider    <- the `router:` block is applied HERE (3rd):
                                                        pattern / model / bind / middlewareGroup / aliasMiddleware   #[Binding]
                                                        singularResourceParameters / resourceParameters / resourceVerbs #[Setter]
                                                        prependMiddlewareToGroup                                        #[PrependTo]
                                                        pushMiddlewareToGroup / removeMiddlewareFromGroup               #[AppendTo]
                                                        matched                                                         #[Append]
            KernelDeclarationServiceProvider    (9th) every declared `kernel:` middleware key: each setter/mutator
                                                    calls syncMiddlewareToRouter() (Kernel.php:519)
            ProvidersDeclarationServiceProvider declared providers register
            RoutesDeclarationServiceProvider    manifest routes created
        app providers boot                      AppServiceProvider::boot(), then AppRouteServiceProvider's booted()
                                                callback: routes/*.php (Route::resource() reads the ResourceRegistrar statics HERE)

// dispatch, per request (Router.php:793)
runRoute($request, $route)
    $this->events->dispatch(new RouteMatched($route, $request))   <- `matched` listeners run HERE (Router.php:797)
    runRouteWithinStack($route, $request)
        gatherRouteMiddleware()     Route::middleware names resolved at dispatch: groups from Router::$middlewareGroups
                                    (nested expansion, MiddlewareNameResolver), aliases from Router::$middleware,
                                    sorted by Router::$middlewarePriority (written by the kernel sync)
        SubstituteBindings etc.     route middleware executes AFTER the matched listeners
```

Consequences, each verified against v13.33.0:

1. **`router:` middleware keys are durable.** The kernel's automatic sync (`Kernel::__construct` + `ApplicationBuilder::withMiddleware()`'s `afterResolving` setters) completes when the kernel resolves — before the bootstrap sequence that boots providers. `RouterDeclarationServiceProvider::boot()` therefore mutates the router's registries after the last automatic sync, and nothing re-syncs afterwards: `Kernel::syncMiddlewareToRouter()` fires only from kernel setters/mutators, and `KernelDeclarationServiceProvider` invokes those only for declared non-null `kernel:` middleware keys.
2. **`kernel:` middleware keys win per registry key.** `KernelDeclarationServiceProvider` boots 9th, after `RouterDeclarationServiceProvider`'s 3rd (DefaultProviders order). Every declared `kernel:` middleware key whose setter or mutator syncs — `setGlobalMiddleware`, `setMiddlewareGroups`, `setMiddlewareAliases`, `setMiddlewarePriority`, `appendMiddlewareToGroup`, `prependMiddlewareToGroup`, `prependToMiddlewarePriority`, `appendToMiddlewarePriority` — rewrites each kernel-known group and alias key on the router. So a group or alias declared on **both** blocks takes the kernel's value, and a router-level mutation of a kernel-known group is overwritten.
3. **The sync never touches keys absent from the kernel.** `syncMiddlewareToRouter()` loops `$this->middlewareGroups` and `$this->middlewareAliases` — the kernel's own arrays. A group or alias declared only under `router:` is invisible to the kernel's arrays, so it survives every later sync. `router:` is therefore the durable seam for a group the kernel does not define; `kernel:` is the owner of the groups it does define.
4. **Console/artisan: the HTTP kernel may never resolve.** `artisan route:list` and console-driven route creation go through the same `Router` singleton, whose registries start empty. `router:` middleware keys apply unconditionally in `boot()` — no kernel resolution is required. They are the only declarative way a group or alias exists in that context unless a `kernel:` middleware key is declared.
5. **Registration order is the tiebreaker.** `config/laravel-declaration.php`'s `providers` list governs boot order. `DefaultProviders` orders `RouterDeclarationServiceProvider` 3rd and `KernelDeclarationServiceProvider` 9th; a custom `DefaultProviders::replace()` order flips the precedence of consequence 2 (last sync wins).
6. **`matched` listeners fire before route middleware.** `Router::runRoute()` dispatches `RouteMatched` before `runRouteWithinStack()` (Router.php:797). `SubstituteBindings` is route middleware, so `$event->route->parameters()` holds raw strings in a matched listener. Bindings resolve later, in middleware.
7. **Resource global statics are read at resource-registration time.** `Router::resource()` / `singleton()` build routes through `ResourceRegistrar`, which reads `static::$verbs['create']` / `['edit']` for the create/edit URIs (ResourceRegistrar.php:316, 378, 435, 494) and `getResourceWildcard()` (ResourceRegistrar.php:615) for parameter names. Declared before the resource routes are created, the globals reach them — from `routes/*.php`, declared providers, and (when it lands) `routes.resources` (gap inventory §2.2).

### 1.2 Properties

| Property | Type | Set by | Read by |
|---|---|---|---|
| `Router::$middleware` (protected) | `array<string, class-string\|string>` | `aliasMiddleware()` | `getMiddleware()`, `MiddlewareNameResolver::resolve()` (alias lookup at dispatch) |
| `Router::$middlewareGroups` (protected) | `array<string, array<int, class-string\|string>>` | `middlewareGroup()`, `pushMiddlewareToGroup()`, `prependMiddlewareToGroup()`, `removeMiddlewareFromGroup()`, `flushMiddlewareGroups()` | `getMiddlewareGroups()`, `hasMiddlewareGroup()`, `MiddlewareNameResolver::resolve()`, `Kernel::syncMiddlewareToRouter()` (writes it) |
| `Router::$middlewarePriority` (public) | `array<int, class-string>` | `Kernel::syncMiddlewareToRouter()` (direct property write) | `Router::gatherRouteMiddleware()` / `SortedMiddleware` |
| `ResourceRegistrar::$singularParameters` (static) | `bool` (default `true`) | `singularResourceParameters()` | `ResourceRegistrar::getResourceWildcard()` |
| `ResourceRegistrar::$parameterMap` (static) | `array<string, string>` | `resourceParameters()` | `ResourceRegistrar::getResourceWildcard()` |
| `ResourceRegistrar::$verbs` (static) | `array{create: string, edit: string}` | `resourceVerbs()` | the `addCreate`/`addEdit` route builders (ResourceRegistrar.php:316, 378) |
| `Router::$events` (protected) | `Illuminate\Contracts\Events\Dispatcher` | constructor | `matched()` → `listen(RouteMatched::class, $callback)` |

### 1.3 Public methods

**Declaration targets**

| Method | Signature | Effect |
|---|---|---|
| `middlewareGroup` | `($name, array $middleware): $this`. `@param string $name, array $middleware` | `$middlewareGroups[$name] = $middleware` — defines the group, replacing any prior value. Nested group names are expanded at dispatch; a group referencing itself throws `LogicException: [x] middleware group is referencing itself.` |
| `aliasMiddleware` | `($name, $class): $this`. `@param string $name, string $class` | `$middleware[$name] = $class` — registers a route middleware alias usable in any route's `middleware` list (with an optional `:params` suffix at the route) |
| `pushMiddlewareToGroup` | `($group, $middleware): $this`. `@param string $group, string $middleware` | Appends to the group, **creating the group when missing** (unlike the kernel mutator, which throws), and skipping a middleware already `in_array` the group |
| `prependMiddlewareToGroup` | `($group, $middleware): $this` | `array_unshift` onto the group; skipped when the middleware is already in the group (native `in_array` guard); **silent no-op when the group is missing** (no throw, no creation) |
| `removeMiddlewareFromGroup` | `($group, $middleware): $this` | `unset` by flipped-key lookup; **silent no-op when the group is missing or the middleware is not in it** (there is no remove on the kernel at all — this method exists only on the router) |
| `singularResourceParameters` | `($singular = true): void`. `@param bool $singular` | `ResourceRegistrar::singularParameters($singular)` — `false` pluralizes resource parameter names globally (`{posts}`, not `{post}`); overridden per resource by a `parameters` option |
| `resourceParameters` | `(array $parameters = []): void`. `@param array<string, string> $parameters` | `ResourceRegistrar::setParameters($parameters)` — **replaces** the global parameter map wholesale (`{resource: paramName}`) |
| `resourceVerbs` | `(array $verbs = []): array\|null`. `@param array{create?: string, edit?: string} $verbs` | Getter/setter hybrid: `[]` returns the map; a non-empty map `array_merge`s over the statics — localizes `create`/`edit` URI segments |
| `matched` | `($callback): void`. `@param string\|callable $callback` | `$this->events->listen(RouteMatched::class, $callback)` — one listener per call; listeners fire at dispatch, in registration order, with `(RouteMatched $event)` |

**Not declaration targets**

| Method / property | Why no key |
|---|---|
| `patterns` | `($patterns): void` is `foreach ($patterns as $key => $pattern) $this->pattern($key, $pattern)` — the map form of `pattern` **is** that loop. Two keys for one effect would break "one key = one method"; `patterns:` stays an unknown key. Decided non-goal ([declarative-router.md](declarative-router.md) §2.6), reaffirmed here against the inventory §2.1 proposal |
| `hasMiddlewareGroup`, `getMiddlewareGroups`, `getMiddleware`, `getPatterns` | runtime read-back |
| `flushMiddlewareGroups` | destructive flush; a manifest declares state, it does not wipe it |
| `$middlewarePriority` (public property) | no setter method on `Router`; written by `Kernel::syncMiddlewareToRouter()`. `kernel.setMiddlewarePriority` owns it (declarative-kernel.md §1.3) |
| `getBindingCallback`, `substituteBindings`, `substituteImplicitBindings` | [declarative-router-bindings.md](declarative-router-bindings.md) §1.3 |

### 1.4 How each declared value resolves

```php
// Router.php:1043 / :1078 — aliasMiddleware / middlewareGroup write the registries verbatim
public function aliasMiddleware($name, $class)   { $this->middleware[$name] = $class;   return $this; }
public function middlewareGroup($name, array $m) { $this->middlewareGroups[$name] = $m; return $this; }

// MiddlewareNameResolver.php:19 — group expansion + alias lookup happen at DISPATCH, not declaration
public static function resolve($name, $map, $middlewareGroups)
{
    if (isset($middlewareGroups[$name])) return static::parseMiddlewareGroup($name, $map, $middlewareGroups);
    [$name, $parameters] = array_pad(explode(':', $name, 2), 2, null);
    return ($map[$name] ?? $name).(! is_null($parameters) ? ':'.$parameters : '');
}
// A name that is neither group, alias nor class is returned as-is and fails at pipeline
// instantiation: `BindingResolutionException: Target class [x] does not exist`.

// ResourceRegistrar.php:615 — the resource globals are read when a resource route is created
public function getResourceWildcard($value)
{
    if (isset($this->parameters[$value])) { $value = $this->parameters[$value]; }        // per-resource option wins
    elseif (isset(static::$parameterMap[$value])) { $value = static::$parameterMap[$value]; }   // then the global map
    elseif ($this->parameters === 'singular' || static::$singularParameters) {
        $value = Str::singular($value);                                                  // then the global flag
    }
    return str_replace('-', '_', $value);
}

// Events/Dispatcher.php:481 — `matched()` strings resolve through the event dispatcher
public function makeListener($listener, $wildcard = false)
{
    if (is_string($listener)) return $this->createClassListener($listener, $wildcard);
    ...
}

// Events/Dispatcher.php:526
protected function createClassCallable($listener)
{
    [$class, $method] = $this->parseClassCallable($listener);   // Str::parseCallback($listener, 'handle')
    if (! method_exists($class, $method)) { $method = '__invoke'; }   // bare invokable class falls back
    if ($this->handlerShouldBeQueued($class)) {
        return $this->createQueuedHandlerCallable($class, $method);   // a ShouldQueue listener is queued
    }
    $listener = $this->container->make($class);                     // constructor DI
    return $this->handlerShouldBeDispatchedAfterDatabaseTransactions($listener)
            && ! in_array($method, ['creating', 'updating', 'saving', 'deleting', 'restoring', 'forceDeleting'])
        ? $this->createCallbackForListenerRunningAfterCommits($listener, $method)   // after-commit listeners defer
        : [$listener, $method];
}
```

Consequences, each verified against v13.33.0:

1. **Middleware strings pass through untouched.** A declared group may mix class-strings, registered aliases, and `alias:params` forms (`throttle:60,1`) — resolution is deferred to dispatch and may reference aliases registered later in the same boot (`router.aliasMiddleware`) or by the kernel.
2. **`matched` accepts exactly the event dispatcher's string forms.** `Class` calls `make(Class)->handle($event)`; a bare class without `handle` falls back to `__invoke($event)` — unlike `Router::bind()` strings, which require `bind()` and never fall back ([declarative-router-bindings.md](declarative-router-bindings.md) §1.4.1). `Class@method` calls `make(Class)->method($event)`. `Class::method` fails (`BindingResolutionException: Target class [A::m] does not exist`). A listener implementing `Illuminate\Contracts\Queue\ShouldQueue` is queued instead of called inline, and one implementing `Illuminate\Contracts\Events\ShouldDispatchAfterCommit` defers until open database transactions commit.
3. **One argument, the event.** `RouteMatched` is dispatched as a single object (Router.php:797), so the listener method receives `(RouteMatched $event)` — `$event->route` (matched, unbound parameters) and `$event->request`.
4. **`resourceParameters` replaces; `resourceVerbs` merges.** `setParameters($parameters)` assigns the static wholesale — declare the complete map in one entry. `verbs($verbs)` `array_merge`s — later calls extend earlier ones. A per-resource `parameters` option beats the global map, which beats `singularResourceParameters`.
5. **The statics are process-global.** `ResourceRegistrar` state has no per-instance reset; a declaration is in force for every resource route created afterwards for the life of the process, including tests that share the PHP process.

### 1.5 The PHP this replaces

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    Route::middlewareGroup('tenant', ['auth', \App\Http\Middleware\TenantIdentified::class]);
    Route::aliasMiddleware('subscribed', \App\Http\Middleware\EnsureSubscription::class);
    Route::prependMiddlewareToGroup('web', \App\Http\Middleware\TenantLocate::class);
    Route::pushMiddlewareToGroup('api', \App\Http\Middleware\RequestTracing::class);
    Route::removeMiddlewareFromGroup('api', \App\Http\Middleware\StatefulGuard::class);
    Route::singularResourceParameters(false);
    Route::resourceParameters(['users' => 'member']);
    Route::resourceVerbs(['create' => 'new', 'edit' => 'change']);
    Route::matched(\App\Listeners\LogMatched::class);          // or LogMatched::class.'@record'
}
```

```yaml
router:
  middlewareGroup:                                             # -> middlewareGroup($name, $middleware), one call per entry
    tenant:
      - auth
      - App\Http\Middleware\TenantIdentified
  aliasMiddleware:                                             # -> aliasMiddleware($name, $class), one call per entry
    subscribed: App\Http\Middleware\EnsureSubscription
  prependMiddlewareToGroup:                                    # -> prependMiddlewareToGroup($group, $middleware), one call per item
    web: [App\Http\Middleware\TenantLocate]
  pushMiddlewareToGroup:                                       # -> pushMiddlewareToGroup($group, $middleware), one call per item
    api: [App\Http\Middleware\RequestTracing]
  removeMiddlewareFromGroup:                                   # -> removeMiddlewareFromGroup($group, $middleware), one call per item
    api: [App\Http\Middleware\StatefulGuard]
  singularResourceParameters: false                            # -> singularResourceParameters(false), one call
  resourceParameters:                                          # -> resourceParameters($parameters), one call with the whole map
    users: member
  resourceVerbs:                                               # -> resourceVerbs($verbs), one call with the whole map
    create: new
    edit: change
  matched:                                                     # -> matched($callback), one call per item
    - App\Listeners\LogMatched@handle
```

---

## 2. Manifest schema proposal

### 2.1 Design rule

Unchanged from [declarative-router.md](declarative-router.md) §2.1: **every key in `router:` is a `Router` method name, and every value is that method's argument(s).** A map is one call per entry and its key is the first argument; a list is one call per item; a Laravel-defined map form is one call with the whole value. Each new property carries one of the five existing dispatch-shape attributes (§2.5) — the provider stays attribute-driven, with no per-key code.

### 2.2 Values

| Key | Value | Native behavior | Rejected forms |
|---|---|---|---|
| `middlewareGroup` | `map<name, list<middleware>>` | `middlewareGroup($name, $middleware)` — defines/replaces the group | scalar value → schema rejects (native `TypeError: array given`) |
| `aliasMiddleware` | `map<alias, class-string>` | `aliasMiddleware($alias, $class)` — registers a route middleware alias | a bare alias with no class; duplicate alias silently replaces (`$middleware[$alias] = $class`) |
| `pushMiddlewareToGroup` | `map<group, list<middleware>\|middleware>` | one call per item; creates the group when missing; dedupes | an item already in the group is skipped (native `in_array` guard) |
| `prependMiddlewareToGroup` | `map<group, list<middleware>\|middleware>` | one call per item, **reversed** so a declared list lands in declared order at the group's head; an item already in the group is skipped (native `in_array` guard) | prepending to a missing group is a silent no-op — create it with `middlewareGroup` or `pushMiddlewareToGroup` first |
| `removeMiddlewareFromGroup` | `map<group, list<middleware>\|middleware>` | one call per item; no-op when the group or the middleware is absent | — |
| `singularResourceParameters` | `bool` (default: Laravel's `true`; absent key → no call) | `singularResourceParameters($bool)` — `false` pluralizes resource parameters | a string → schema rejects |
| `resourceParameters` | `map<resource, paramName>` (absent → no call) | `resourceParameters($map)` — **replaces** the static map; declare the full map | non-string value → schema rejects |
| `resourceVerbs` | `map<create\|edit, localized>`, at least one entry (absent → no call) | `resourceVerbs($map)` — `array_merge` over the statics; the `[]` getter form is unreachable (§2.6) | empty map → schema rejects (`minProperties`) |
| `matched` | `list<Class \| Class@method>` (absent → no call) | `matched($callback)` per item — `RouteMatched` listeners; default method `handle`, bare invokables fall back to `__invoke`, `ShouldQueue` listeners are queued | `Class::method`, an enum, a `.php` file → schema pattern rejects; missing `handle`/`__invoke` fails at first match with Laravel's own exception |

Write class-strings plain or single-quoted: `"App\Http\Middleware\Tenant"` throws `ParseException: Found unknown escape character "\T"`.

### 2.3 Full example

```yaml
router:                                          # ——— Router surface ———
  pattern:                                       # declarative-router.md
    id: '[0-9]+'
  model:                                         # declarative-router-bindings.md
    user: App\Models\User
  bind:                                          # declarative-router-bindings.md
    team: App\Routing\Teams@bySlug
  middlewareGroup:                               # -> middlewareGroup($name, $middleware), one call per entry
    tenant:                                      # route `middleware: [tenant]` expands to these, in order
      - auth                                     # an alias synced by the kernel, or the kernel's default aliases
      - App\Http\Middleware\TenantIdentified
  aliasMiddleware:                               # -> aliasMiddleware($name, $class), one call per entry
    subscribed: App\Http\Middleware\EnsureSubscription
  pushMiddlewareToGroup:                         # -> pushMiddlewareToGroup($group, $middleware), one call per item
    api:                                         # appends; creates `api` when missing (it exists by default)
      - App\Http\Middleware\RequestTracing
  prependMiddlewareToGroup:                      # -> prependMiddlewareToGroup($group, $middleware), one call per item
    web:                                         # lands at the head of `web` in declared order
      - App\Http\Middleware\TenantLocate
  removeMiddlewareFromGroup:                     # -> removeMiddlewareFromGroup($group, $middleware), one call per item
    api:                                         # a middleware only the router knows how to remove
      - App\Http\Middleware\StatefulGuard
  singularResourceParameters: false              # -> singularResourceParameters(false), one call: {posts}, not {post}
  resourceParameters:                            # -> resourceParameters($parameters), one call with the whole map
    posts: item                                  # replaces the map: {item}, not {posts}
  resourceVerbs:                                 # -> resourceVerbs($verbs), one call with the whole map
    create: nuevo                                # posts.create URI: posts/nuevo
  matched:                                       # -> matched($callback), one call per item
    - App\Listeners\LogMatched@handle            # called with (RouteMatched $event) at dispatch

routes:
  addRoute:                                      # Router::addRoute($methods, $uri, $action) per entry
    - uri: "posts/{item}"
      methods: GET
      action: App\Http\Controllers\PostController
      middleware: [tenant]                       # group expansion at dispatch
      metadata:
        request: show-post

    - uri: "account"
      methods: GET
      action: App\Http\Controllers\AccountController
      middleware: [subscribed]                   # the declared alias
```

### 2.4 Key → method → signature map (whole `router:` block)

| YAML key | `Router` method | Value shape (YAML) | Attribute | Dispatch (§2.5) | Read | Absent → |
|---|---|---|---|---|---|---|
| `pattern` | `pattern` | `map<param, regex>` | `#[Binding]` | `$Router->pattern($key, $pattern)` per entry | route creation | no global pattern |
| `model` | `model` | `map<param, class-string>` | `#[Binding]` | `$Router->model($key, $class)` per entry | dispatch | implicit binding only |
| `bind` | `bind` | `map<param, Class \| Class@method>` | `#[Binding]` | `$Router->bind($key, $binder)` per entry | dispatch | implicit binding only |
| `middlewareGroup` | `middlewareGroup` | `map<name, list<middleware>>` | `#[Binding]` | `$Router->middlewareGroup($name, $middleware)` per entry | dispatch | no router-level group |
| `aliasMiddleware` | `aliasMiddleware` | `map<alias, class-string>` | `#[Binding]` | `$Router->aliasMiddleware($name, $class)` per entry | dispatch | kernel aliases only |
| `prependMiddlewareToGroup` | `prependMiddlewareToGroup` | `map<group, list<middleware> \| middleware>` | `#[PrependTo]` | `$Router->prependMiddlewareToGroup($group, $middleware)` per item, reversed | dispatch | group unchanged |
| `pushMiddlewareToGroup` | `pushMiddlewareToGroup` | `map<group, list<middleware> \| middleware>` | `#[AppendTo]` | `$Router->pushMiddlewareToGroup($group, $middleware)` per item | dispatch | group unchanged |
| `removeMiddlewareFromGroup` | `removeMiddlewareFromGroup` | `map<group, list<middleware> \| middleware>` | `#[AppendTo]` | `$Router->removeMiddlewareFromGroup($group, $middleware)` per item | dispatch | group unchanged |
| `singularResourceParameters` | `singularResourceParameters` | `bool` | `#[Setter]` | `$Router->singularResourceParameters($bool)` — one call | resource creation | Laravel default (`true`) |
| `resourceParameters` | `resourceParameters` | `map<resource, paramName>` | `#[Setter]` | `$Router->resourceParameters($map)` — one call, whole map | resource creation | framework default map (`[]`) |
| `resourceVerbs` | `resourceVerbs` | `map<verb, localized>` | `#[Setter]` | `$Router->resourceVerbs($map)` — one call, whole map | resource creation | framework default verbs |
| `matched` | `matched` | `list<Class \| Class@method>` | `#[Append]` | `$Router->matched($callback)` per item | dispatch | no matched listener |

Fixed loop order is §2.5's: `#[Binding]` → `#[Setter]` → `#[PrependTo]` → `#[AppendTo]` → `#[Append`, and inside each shape the declaration order above. Determinism cases: `middlewareGroup` (definition) runs before the mutation trio, so `pushMiddlewareToGroup` on a group also defined by `middlewareGroup` appends to the declared definition; `pushMiddlewareToGroup` is declared before `removeMiddlewareFromGroup`, so a push of a middleware that a later remove entry also names nets to a removal; `prependMiddlewareToGroup` and `pushMiddlewareToGroup` touch opposite ends of the group, so their relative order is unobservable except for duplicate dedupe. `patterns` remains an unknown key ([declarative-router.md](declarative-router.md) §2.6).

### 2.5 Registration algorithm (for the provider) — attribute-selected dynamic dispatch

`src/Router.php` gains nine properties, each decorated with an **existing** dispatch-shape attribute. No new attribute is needed: the shapes already exist on the `Kernel` declaration ([declarative-kernel.md](declarative-kernel.md)) and are reused verbatim, exactly as `addToMiddlewarePriorityBefore` reuses `#[AppendTo]` and `addToMiddlewarePriorityAfter` reuses `#[PrependTo]` (`src/Kernel.php`).

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Append;use ZeroToProd\LaravelDeclaration\Attributes\Attributes\AppendTo;use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Binding;use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Key;use ZeroToProd\LaravelDeclaration\Attributes\Attributes\PrependTo;use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Setter;use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Router
{
    use DataModel;

    public const string pattern = 'pattern';

    /** @var array<string, string> */
    #[Key, Binding, Describe([Describe::default => []])]
    public array $pattern;

    public const string model = 'model';

    /** @var array<string, string> */
    #[Key, Binding, Describe([Describe::default => []])]
    public array $model;

    public const string bind = 'bind';

    /** @var array<string, string> */
    #[Key, Binding, Describe([Describe::default => []])]
    public array $bind;

    public const string middlewareGroup = 'middlewareGroup';

    /** @var array<string, list<class-string|string>> one `Router::middlewareGroup($name, $middleware)` per entry */
    #[Key, Binding, Describe([Describe::default => []])]
    public array $middlewareGroup;

    public const string aliasMiddleware = 'aliasMiddleware';

    /** @var array<string, class-string|string> one `Router::aliasMiddleware($name, $class)` per entry */
    #[Key, Binding, Describe([Describe::default => []])]
    public array $aliasMiddleware;

    public const string prependMiddlewareToGroup = 'prependMiddlewareToGroup';

    /** @var array<string, list<class-string|string>|class-string|string> one call per item, reversed (declaration order lands at the group head) */
    #[Key, PrependTo, Describe([Describe::default => []])]
    public array $prependMiddlewareToGroup;

    public const string pushMiddlewareToGroup = 'pushMiddlewareToGroup';

    /** @var array<string, list<class-string|string>|class-string|string> one call per item; creates the group when missing */
    #[Key, AppendTo, Describe([Describe::default => []])]
    public array $pushMiddlewareToGroup;

    public const string removeMiddlewareFromGroup = 'removeMiddlewareFromGroup';

    /** @var array<string, list<class-string|string>|class-string|string> one call per item; silent no-op when absent */
    #[Key, AppendTo, Describe([Describe::default => []])]
    public array $removeMiddlewareFromGroup;

    public const string singularResourceParameters = 'singularResourceParameters';

    /** one `Router::singularResourceParameters($singular)` call; absent key -> Laravel default (true) */
    #[Key, Setter, Describe([Describe::nullable => true])]
    public ?bool $singularResourceParameters;

    public const string resourceParameters = 'resourceParameters';

    /** @var array<string, string>|null one `Router::resourceParameters($parameters)` call with the whole map (replaces) */
    #[Key, Setter, Describe([Describe::nullable => true])]
    public ?array $resourceParameters;

    public const string resourceVerbs = 'resourceVerbs';

    /** @var array<string, string>|null one `Router::resourceVerbs($verbs)` call with the whole map (merges) */
    #[Key, Setter, Describe([Describe::nullable => true])]
    public ?array $resourceVerbs;

    public const string matched = 'matched';

    /** @var list<class-string|class-string@method> one `Router::matched($callback)` per item */
    #[Key, Append, Describe([Describe::default => []])]
    public array $matched;
}
```

`src/Providers/RouterDeclarationServiceProvider.php` gains four loops, one per dispatch shape, mirroring `KernelDeclarationServiceProvider`'s structure. The method-name loop variables are the manifest's keys; the provider never names a specific `Router` method:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Routing\Router;use Illuminate\Support\ServiceProvider;use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Append;use ZeroToProd\LaravelDeclaration\Attributes\Attributes\AppendTo;use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Binding;use ZeroToProd\LaravelDeclaration\Attributes\Attributes\PrependTo;use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Setter;use ZeroToProd\LaravelDeclaration\Manifest;use ZeroToProd\LaravelDeclaration\Router as RouterDeclaration;

/** @internal */
class RouterDeclarationServiceProvider extends ServiceProvider
{
    public function boot(?Manifest $Manifest = null, ?Router $Router = null): void
    {
        if (! $Manifest?->router instanceof RouterDeclaration || ! $Router instanceof Router) {
            return;
        }

        foreach (RouterDeclaration::selected(Binding::class) as $method) {
            foreach ($Manifest->router->{$method} as $key => $value) {
                $Router->{$method}($key, $value);                 // map entry: key is the first argument
            }
        }

        foreach (RouterDeclaration::selected(Setter::class) as $method) {
            if ($Manifest->router->{$method} !== null) {
                $Router->{$method}($Manifest->router->{$method});  // one call with the whole value
            }
        }

        foreach (RouterDeclaration::selected(PrependTo::class) as $method) {
            foreach ($Manifest->router->{$method} as $group => $middlewares) {
                foreach (array_reverse((array) $middlewares) as $middleware) {
                    $Router->{$method}($group, $middleware);       // reversed: declared order lands at the head
                }
            }
        }

        foreach (RouterDeclaration::selected(AppendTo::class) as $method) {
            foreach ($Manifest->router->{$method} as $group => $middlewares) {
                foreach ((array) $middlewares as $middleware) {
                    $Router->{$method}($group, $middleware);       // one call per (group, middleware) pair
                }
            }
        }

        foreach (RouterDeclaration::selected(Append::class) as $method) {
            foreach ($Manifest->router->{$method} as $callback) {
                $Router->{$method}($callback);                     // one call per list item
            }
        }
    }
}
```

That is the whole implementation. Each loop is shape-generic: `Router::selected(...)` reflects the properties carrying the attribute (declaration order), and the call arguments are the manifest's values. The provider adds no transformation, wrapper or reference resolution — the value is the argument, and phpstan checks every callable path against `Router`'s signatures.

### 2.6 Notes / non-goals

- **`patterns` stays an unknown key.** The inventory §2.1 row proposes `router.patterns`, but `patterns($patterns)` is `foreach` over `pattern()`, and the `pattern` map form is that loop ([declarative-router.md](declarative-router.md) §2.6). Two keys for one effect invites the `pattern`/`patterns` typo; hydration ignores unknown keys and `laravel-declaration:validate`'s schema (`additionalProperties: false`) rejects the likeliest typo.
- **Precedence with `kernel:` middleware keys** (§1.1, consequences 1–3): for any registry key the kernel also declares, `kernel:` wins, because `KernelDeclarationServiceProvider` boots later and every kernel setter/mutator syncs. Router-only groups/aliases and router-level mutations of router-only groups are durable. The `router:` middleware keys are the native seam for the console/artisan context (§1.1, consequence 4) and for group mutation of a group the kernel does not define.
- **Supersession.** [declarative-router.md](declarative-router.md) §2.6's "middleware is not a `router` key" bullet was decided before the `kernel:` block shipped (roadmap Phase 5). This document supersedes it: the `kernel:` setters exist, the inventory §2.1 proposes the router-level seam, and the precedence rules above make both blocks composable. [declarative-kernel.md](declarative-kernel.md) §1.1 consequence 2's "declaring middleware on `Router` directly is fragile" narrows accordingly: fragile **only** for keys the kernel also declares (or when a custom provider order puts the kernel provider earlier).
- **No `removeMiddlewareFromGroup` on the kernel.** `Foundation\Http\Kernel` has append/prepend mutators only; `Router::removeMiddlewareFromGroup` is the sole remove seam, which is why `kernel.removeMiddlewareFromGroup` cannot exist and this key cannot migrate.
- **`route:cache` split.** The resource globals bake into cached URIs (`AbstractRouteCollection::compile()` stores `'uri' => $route->uri()`, the expanded form, line 178) — treat them like `pattern`: re-run `route:cache` after editing. The middleware registry keys and `matched` are not route state (groups/aliases resolve at dispatch; listeners live on the dispatcher) — no re-cache, like `model`/`bind`.
- **`resourceVerbs` getter unreachable.** The manifest always passes a non-empty map (schema `minProperties: 1`), so `resourceVerbs()` never runs in its getter form. Reading back is runtime work, not a declaration.
- **`resourceParameters` replaces wholesale.** `ResourceRegistrar::setParameters()` assigns the static. To extend the framework's map rather than replace it, declare the entries you know; the map has no defaults to preserve.
- **Statics are process-global.** The `ResourceRegistrar` statics outlive the application instance (§1.4, consequence 5); tests that create resource routes should boot a manifest that declares what they assert.
- **`matched` is not `bind`.** `matched` strings resolve through `Dispatcher::createClassCallable()` (default `handle`, `__invoke` fallback, `ShouldQueue` queuing), not `RouteBinding::forCallback()` (default `bind`, no fallback). A bare invokable class works for `matched` and fails for `bind`.
- **Listeners see unbound parameters.** `RouteMatched` dispatches before route middleware (§1.1, consequence 6): `$event->route->parameters()` are raw strings. For bound models, use a route-level `missing`/action or a composer instead.
- **Group item syntax.** Group/mutation items are plain middleware names, not FQCNs: they may be aliases with parameters (`throttle:60,1`), which is why the schema items are `type: string` while the kernel's `setMiddlewareGroups` schema is stricter (`classString`) — the kernel schema can be relaxed in a follow-up; this document does not change it.
- **`resourceParameters` / `resourceVerbs` / `singularResourceParameters` need resource routes to be observable.** `routes.resources` (gap inventory §2.2) is the manifest-native consumer; until it lands, the globals reach `routes/*.php` and declared providers calling `Route::resource()` (§1.1, consequence 7). The tests (§3.9) exercise `Router::resource()` directly, which is the API these keys configure.

---

## 3. Implementation plan

1. **`src/Router.php`**. Replace the file with §2.5's full listing (three existing properties unchanged, nine added in key order after `bind`).

2. **`src/Providers/RouterDeclarationServiceProvider.php`**. Replace the file with §2.5's full listing (guard unchanged, four shape loops added beside the `Binding` loop).

3. **`manifest.schema.json`**. Extend `definitions.router.properties` after `"bind"`:

   ```json
   "middlewareGroup": {
     "description": "-> middlewareGroup($name, $middleware): Defines/replaces a route middleware group on the router. Items are middleware class-strings, aliases, or `alias:params` forms; resolved at dispatch. A `kernel:` middleware key wins when both blocks declare the same group.",
     "type": "object",
     "additionalProperties": {
       "type": "array",
       "items": { "type": "string" }
     }
   },
   "aliasMiddleware": {
     "description": "-> aliasMiddleware($name, $class): Registers a route middleware alias on the router, usable in any route's `middleware` list (with an optional `:params` suffix).",
     "type": "object",
     "additionalProperties": { "$ref": "#/definitions/classString" }
   },
   "prependMiddlewareToGroup": {
     "description": "-> prependMiddlewareToGroup($group, $middleware): One call per item, reversed so declared order lands at the group head. Silent no-op when the group is missing.",
     "type": "object",
     "additionalProperties": {
       "anyOf": [
         { "type": "string" },
         { "type": "array", "items": { "type": "string" } }
       ]
     }
   },
   "pushMiddlewareToGroup": {
     "description": "-> pushMiddlewareToGroup($group, $middleware): One call per item; appends to the group, creating it when missing. No-op when already present.",
     "type": "object",
     "additionalProperties": {
       "anyOf": [
         { "type": "string" },
         { "type": "array", "items": { "type": "string" } }
       ]
     }
   },
   "removeMiddlewareFromGroup": {
     "description": "-> removeMiddlewareFromGroup($group, $middleware): One call per item; removes the middleware from the group. Silent no-op when the group or the middleware is missing. The only remove seam in Laravel (the kernel has none).",
     "type": "object",
     "additionalProperties": {
       "anyOf": [
         { "type": "string" },
         { "type": "array", "items": { "type": "string" } }
       ]
     }
   },
   "singularResourceParameters": {
     "description": "-> singularResourceParameters($singular): false pluralizes resource parameter names globally ({posts}). Absent: Laravel's default (true). Baked into route:cache.",
     "type": "boolean"
   },
   "resourceParameters": {
     "description": "-> resourceParameters($parameters): One call with the whole map; replaces the global resource parameter map ({resource: paramName}). Baked into route:cache.",
     "type": ["object", "null"],
     "additionalProperties": { "type": "string" }
   },
   "resourceVerbs": {
     "description": "-> resourceVerbs($verbs): One call with the whole map; array_merges the resource verbs (create/edit) used in resource URIs. Baked into route:cache.",
     "type": ["object", "null"],
     "minProperties": 1,
     "additionalProperties": { "type": "string" }
   },
   "matched": {
     "description": "-> matched($callback): One call per item; registers a RouteMatched listener (fires at dispatch, before route middleware). `Class` (method `handle`, bare invokables fall back to `__invoke`) or `Class@method`, called with ($event).",
     "type": "array",
     "items": {
       "type": "string",
       "pattern": "^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*(@[A-Za-z_][A-Za-z0-9_]*)?$"
     }
   }
   ```

   The `matched` item pattern is `bind`'s from [declarative-router-bindings.md](declarative-router-bindings.md) §3.3 (optional `@method`, no `::`), because both pass through Laravel's own string-resolution and `Class::method` can never resolve. Checked with `JsonSchema\Validator`, it accepts `App\Listeners\LogMatched` and `App\Listeners\LogMatched@handle`, and it rejects `App\Listeners\LogMatched::handle`, `App\Listeners\LogMatched@` and `app/listeners/log.php`.

4. **`README.md`, `## Router`**. Extend the structure block and add one paragraph after the `bind` paragraph:

   ````markdown
   `middlewareGroup` is `Router::middlewareGroup($name, $middleware)`, `aliasMiddleware` is
   `Router::aliasMiddleware($name, $class)`, `pushMiddlewareToGroup` / `prependMiddlewareToGroup`
   / `removeMiddlewareFromGroup` are the group-mutation trio (one call per item), and `matched`
   registers `RouteMatched` listeners (one call per item; `Class` uses `handle`, bare invokables
   fall back to `__invoke`, called with `($event)` before route middleware). Items may be aliases
   (`throttle:60,1`). `kernel:` middleware keys win for any group/alias the kernel also declares
   (its setters re-sync the router); router-only keys persist, and `router:` keys work even when
   the HTTP kernel never resolves (console). `singularResourceParameters` / `resourceParameters`
   / `resourceVerbs` are `ResourceRegistrar` global statics for resource routes: re-run
   `route:cache` after editing them, unlike the middleware and `matched` keys.
   ````

   ```yaml
   router:
     pattern:                    # -> pattern($key, $pattern), one call per entry
       id: '[0-9]+'              # every {id} of every route created afterwards
       account: '[a-z]+'         # domain parameters too: {account}.example.com
     model:                      # -> model($key, $class), one call per entry
       user: App\Models\User     # {user} -> User::resolveRouteBinding($value); 404 when null
     bind:                       # -> bind($key, $binder), one call per entry
       post: App\Routing\PostBinder     # make(PostBinder)->bind($value, $route)
       team: App\Routing\Teams@bySlug   # make(Teams)->bySlug($value, $route)
     middlewareGroup:            # -> middlewareGroup($name, $middleware), one call per entry
       tenant:                   # middleware: [tenant] expands to these at dispatch
         - auth
         - App\Http\Middleware\TenantIdentified
     aliasMiddleware:            # -> aliasMiddleware($name, $class), one call per entry
       subscribed: App\Http\Middleware\EnsureSubscription
     prependMiddlewareToGroup:   # -> prependMiddlewareToGroup($group, $middleware), one call per item
       web: [App\Http\Middleware\TenantLocate]
     pushMiddlewareToGroup:      # -> pushMiddlewareToGroup($group, $middleware), one call per item
       api: [App\Http\Middleware\RequestTracing]
     removeMiddlewareFromGroup:  # -> removeMiddlewareFromGroup($group, $middleware), one call per item
       api: [App\Http\Middleware\StatefulGuard]
     singularResourceParameters: false   # -> singularResourceParameters(false): {posts}, not {post}
     resourceParameters:         # -> resourceParameters($parameters), one call with the whole map
       posts: item
     resourceVerbs:              # -> resourceVerbs($verbs), one call with the whole map
       create: nuevo
     matched:                    # -> matched($callback), one call per item
       - App\Listeners\LogMatched@handle
   ```

5. **`docs/declarative-router.md`**. In §1.3's "Not declaration targets" table, add a row: `middlewareGroup` / `aliasMiddleware` / `pushMiddlewareToGroup` / `prependMiddlewareToGroup` / `removeMiddlewareFromGroup` — "declaration targets: declarative-router-configuration.md §1.3". In §2.4, change "`model` / `bind`: declarative-router-bindings.md §2.4." to also name "the middleware and resource keys: declarative-router-configuration.md §2.4". In §2.6, replace the "Middleware ... is not a `router` key" bullet with "- **Middleware** (`middlewareGroup`, `aliasMiddleware`, the group-mutation trio) and the `ResourceRegistrar` globals and `matched`: [declarative-router-configuration.md](declarative-router-configuration.md) — the `kernel:` middleware keys win for any registry key they also declare (§2.6 there)."

6. **`docs/declarative-kernel.md`**. In §1.1 consequence 2, after "making the Router's state durable", append: "The converse holds for router-only registry keys: `syncMiddlewareToRouter()` rewrites only kernel-known group/alias keys, so keys declared under `router:` alone survive every sync ([declarative-router-configuration.md](declarative-router-configuration.md) §1.1)."

7. **`docs/declarative-tier1-gap-inventory.md`**. §1 row 2 → `[/]` becomes `[x]` ("Resolved by declarative-router-configuration.md; `patterns()` a decided non-goal"). §2.1: mark each table row — `patterns()` "decided non-goal (declarative-router.md §2.6)"; the other nine "mapped by declarative-router-configuration.md". §4 item 3: the `router:` half is closed, the `routes:` half (§2.2) remains.

8. **`docs/declarative-framework-api-mapping.md`**. Line 61 row → `[x]` with "Mapped: `pattern`, `model`, `bind`, `middlewareGroup`, `aliasMiddleware`, `pushMiddlewareToGroup`, `prependMiddlewareToGroup`, `removeMiddlewareFromGroup`, `singularResourceParameters`, `resourceParameters`, `resourceVerbs`, `matched` (`patterns` a decided non-goal)". Line 315's audit row: same replacement for its `**Gap**` cell.

9. **Fixtures**:

   `tests/Fixtures/App/Middleware/TenantIdentified.php`, `GroupPrependedFirst.php`, `GroupPrependedSecond.php`, `GroupPushed.php`, `KernelAppended.php`, `RouterWeb.php`, `AcmeGroupMember.php` — each records into the existing `MiddlewareLog` and passes through:

   ```php
   <?php

   declare(strict_types=1);

   namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware;

   use Closure;
   use Illuminate\Http\Request;
   use Symfony\Component\HttpFoundation\Response;

   /** Declared in `router.middlewareGroup.tenant`; records its group position at dispatch. */
   class TenantIdentified
   {
       public function handle(Request $request, Closure $next): Response
       {
           MiddlewareLog::record(self::class);

           return $next($request);
       }
   }
   ```

   `tests/Fixtures/App/Routing/MatchedListener.php` and `InvokableMatchedListener.php`:

   ```php
   <?php

   declare(strict_types=1);

   namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing;

   use Illuminate\Routing\Events\RouteMatched;
   use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\MiddlewareLog;

   /** `matched: [MatchedListener]`: the dispatcher's default method is `handle`. */
   final class MatchedListener
   {
       public function handle(RouteMatched $event): void
       {
           MiddlewareLog::record(self::class.'@'.$event->route->uri());
       }
   }

   /** `matched: [InvokableMatchedListener]`: no `handle`, so the dispatcher falls back to `__invoke`. */
   final class InvokableMatchedListener
   {
       public function __invoke(RouteMatched $event): void
       {
           MiddlewareLog::record(self::class.'@'.$event->route->uri());
       }
   }
   ```

   Append to `tests/Fixtures/manifest/router.yml` (under `router:`):

   ```yaml
     middlewareGroup:
       tenant:
         - auth
         - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\TenantIdentified
     aliasMiddleware:
       subscribed: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\EnsureUserIsSubscribed
     prependMiddlewareToGroup:
       tenant:
         - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\GroupPrependedFirst
         - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\GroupPrependedSecond
     pushMiddlewareToGroup:
       tenant:
         - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\GroupPushed
     removeMiddlewareFromGroup:
       web:
         - Illuminate\Cookie\Middleware\EncryptCookies
     singularResourceParameters: false
     resourceParameters:
       posts: item
     resourceVerbs:
       create: nuevo
     matched:
       - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\MatchedListener
       - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\InvokableMatchedListener
   ```

   New fixture `tests/Fixtures/manifest/router-middleware.yml`, for the `kernel:` precedence case:

   ```yaml
   # yaml-language-server: $schema=./../../../manifest.schema.json

   router:
     middlewareGroup:
       web: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\RouterWeb]
       acme: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\AcmeGroupMember]

   kernel:
     appendMiddlewareToGroup:
       web: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\KernelAppended]
   ```

   Append to `tests/Fixtures/manifest/router.yml` (under `routes:`):

   ```yaml
     - uri: "tenant"
       methods: GET
       action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockController
       middleware: [tenant, subscribed]

     - uri: "unsubscribed"
       methods: GET
       action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockController
       middleware: [subscribed]
   ```

10. **Tests**, appended to `tests/Feature/RouterRegistrationTest.php` (the expected values follow from §1.1's lifecycle and §1.3's native effects):

    ```php
    it('registers a middleware group and an alias on the router', function () use ($manifest): void {
        $this->withConfig(['laravel-declaration.manifest' => $manifest]);

        expect(app(Router::class)->getMiddlewareGroups()['tenant'])->toBe([
            'auth',
            TenantIdentified::class,
            GroupPrependedFirst::class,
            GroupPrependedSecond::class,
            GroupPushed::class,
        ])->and(app(Router::class)->getMiddleware()['subscribed'])->toBe(EnsureUserIsSubscribed::class);
    });

    it('expands a declared group and alias at dispatch in declared order', function () use ($manifest): void {
        $this->withConfig(['laravel-declaration.manifest' => $manifest]);

        MiddlewareLog::reset();

        $this->get('/tenant', ['X-Subscribed' => '1'])->assertOk();

        expect(MiddlewareLog::entries())->toBe([
            TenantIdentified::class,
            GroupPrependedFirst::class,
            GroupPrependedSecond::class,
            GroupPushed::class,
            EnsureUserIsSubscribed::class,
        ]);
    });

    it('enforces a declared alias without its header', function () use ($manifest): void {
        $this->withConfig(['laravel-declaration.manifest' => $manifest]);

        $this->get('/unsubscribed')->assertForbidden();
    });

    it('removes a kernel-known middleware from a group', function () use ($manifest): void {
        $this->withConfig(['laravel-declaration.manifest' => $manifest]);

        expect(app(Router::class)->getMiddlewareGroups()['web'])
            ->not->toContain(EncryptCookies::class);
    });

    it('runs matched listeners before route middleware with raw parameters', function () use ($manifest): void {
        $this->withConfig(['laravel-declaration.manifest' => $manifest]);

        MiddlewareLog::reset();

        $this->get('/posts/5')->assertOk();

        expect(MiddlewareLog::entries())->toContain(
            MatchedListener::class.'@posts/{id}',
            InvokableMatchedListener::class.'@posts/{id}',
        );
    });

    it('pluralizes, renames and localizes resource routes', function () use ($manifest): void {
        $this->withConfig(['laravel-declaration.manifest' => $manifest]);

        $Router = app(Router::class);
        $Router->resource('categories', MockController::class);
        $Router->resource('posts', MockController::class);

        expect($Router->getRoutes()->getByName('categories.show')->uri())->toBe('categories/{categories}')
            ->and($Router->getRoutes()->getByName('posts.show')->uri())->toBe('posts/{item}')
            ->and($Router->getRoutes()->getByName('posts.create')->uri())->toBe('posts/nuevo');
    });
    ```

    New file `tests/Feature/RouterMiddlewarePrecedenceTest.php`:

    ```php
    <?php

    declare(strict_types=1);

    use Illuminate\Routing\Router;
    use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\AcmeGroupMember;
    use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\KernelAppended;
    use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\RouterWeb;

    $manifest = __DIR__.'/../Fixtures/manifest/router-middleware.yml';

    it('lets kernel middleware keys win for a registry key both blocks declare', function () use ($manifest): void {
        $this->withConfig([
            'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
            'laravel-declaration.manifest' => $manifest,
        ]);

        expect(app(Router::class)->getMiddlewareGroups()['web'])
            ->toContain(KernelAppended::class)
            ->not->toContain(RouterWeb::class);
    });

    it('keeps router-only groups alive across kernel syncs', function () use ($manifest): void {
        $this->withConfig([
            'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
            'laravel-declaration.manifest' => $manifest,
        ]);

        expect(app(Router::class)->getMiddlewareGroups()['acme'])->toBe([AcmeGroupMember::class]);
    });
    ```

    Extend the existing attribute-selection test and the absent-block test:

    ```php
    it('selects binding properties via attributes', function (): void {
        expect(RouterDeclaration::selected(Binding::class))->toBe([
            RouterDeclaration::pattern,
            RouterDeclaration::model,
            RouterDeclaration::bind,
            RouterDeclaration::middlewareGroup,
            RouterDeclaration::aliasMiddleware,
        ])->and(RouterDeclaration::selected(Setter::class))->toBe([
            RouterDeclaration::singularResourceParameters,
            RouterDeclaration::resourceParameters,
            RouterDeclaration::resourceVerbs,
        ])->and(RouterDeclaration::selected(PrependTo::class))->toBe([
            RouterDeclaration::prependMiddlewareToGroup,
        ])->and(RouterDeclaration::selected(AppendTo::class))->toBe([
            RouterDeclaration::pushMiddlewareToGroup,
            RouterDeclaration::removeMiddlewareFromGroup,
        ])->and(RouterDeclaration::selected(Append::class))->toBe([
            RouterDeclaration::matched,
        ]);
    });
    ```

    Also add to `tests/Feature/ValidateCommandTest.php`:

    ```php
    test('laravel-declaration:validate accepts the extended router block', function (): void {
        $this->artisan('laravel-declaration:validate', ['--manifest' => __DIR__.'/../Fixtures/manifest/router.yml'])
            ->expectsOutputToContain('is valid')
            ->assertSuccessful();
    });

    test('laravel-declaration:validate rejects a non-boolean singularResourceParameters', function (): void {
        $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
        file_put_contents($file, <<<'YAML'
            router:
              singularResourceParameters: "nope"
            YAML);

        $this->artisan('laravel-declaration:validate', ['--manifest' => $file])
            ->expectsOutputToContain('singularResourceParameters')
            ->assertFailed();
    });
    ```

11. **`composer check`**: lint, rector, phpstan, 100% coverage, bc-check. The four new loops are covered by the fixture tests, the `null` guard of the `Setter` loop by the `requests.yml` absent-block case, and the attribute selection by the extended selection test. The new public API is additive (`Router` properties + `Manifest::$router` shape unchanged) for `bc-check`. The MCP `api` tool reflects `src/`, so it lists the nine new `Router` properties, and the `readme` tool serves the updated README.

12. **Roadmap**. In [declarative-request-to-view-roadmap.md](declarative-request-to-view-roadmap.md) §1, add stage 23 — "Router Middleware Registry, Resource Globals & Matched Listeners | `Illuminate\Routing\Router` | `router.*` | Completed" — and extend §3.1's "Routing & Model Binding" bullet with the new keys.

---

### Sources

1. `middlewareGroup()`, `aliasMiddleware()`, `pushMiddlewareToGroup()`, `prependMiddlewareToGroup()`, `removeMiddlewareFromGroup()`, `flushMiddlewareGroups()`, `matched()`, `singularResourceParameters()`, `resourceParameters()`, `resourceVerbs()`, `runRoute()` (RouteMatched dispatch) — [Router.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/Router.php)
2. `singularParameters()`, `setParameters()`, `verbs()`, `getResourceWildcard()`, the `static::$verbs` read sites — [ResourceRegistrar.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/ResourceRegistrar.php)
3. `syncMiddlewareToRouter()`, the constructor sync, the setter/mutator syncs — [Foundation/Http/Kernel.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Foundation/Http/Kernel.php)
4. `withKernels()` (singleton kernels), `withMiddleware()` (`afterResolving` → default stacks) — [Foundation/Configuration/ApplicationBuilder.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php)
5. Group expansion, alias lookup, self-reference `LogicException` — [MiddlewareNameResolver.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/MiddlewareNameResolver.php)
6. `RouteMatched` payload — [Routing/Events/RouteMatched.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/Events/RouteMatched.php)
7. String listener resolution (`handle` default, `__invoke` fallback, `ShouldQueue` queuing, container `make`) — [Events/Dispatcher.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Events/Dispatcher.php)
8. `compile()` bakes expanded `uri` and `wheres` (resource globals freeze at cache time; middleware/listeners do not) — [AbstractRouteCollection.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/AbstractRouteCollection.php)
9. Dispatch-shape attribute precedent (`addToMiddlewarePriorityBefore` → `#[AppendTo]`) — `src/Kernel.php`, `src/Providers/KernelDeclarationServiceProvider.php`
10. Global constraints and explicit binding belong in `boot()` — [docs/repos/laravel/docs/routing.md](repos/laravel/docs/routing.md#parameters-global-constraints), [laravel.com/docs/routing#route-middleware-groups](https://laravel.com/docs/routing#route-middleware-groups)