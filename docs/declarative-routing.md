# Declarative Routing — `Illuminate\Routing\Route` API & Manifest Schema

> Manifest forms in this document are the pre-engine block shapes; see docs/general-purpose-migration-plan.md §2.1 and README for the current forms.

Source of truth: `vendor/laravel/framework/src/Illuminate/Routing/Route.php` (`laravel/framework` v13.33.0).

Goal: a `routes:` block in `tests/Fixtures/manifest/app.yml` whose **keys map 1:1 onto `Route` method names** and whose **values map 1:1 onto those methods' signatures**, so a provider can register every route with a small, fixed dispatch loop (§2.5).

---

## 1. Public API of `Route::class`

### 1.1 Constructor

```php
public function __construct($methods, $uri, $action)
// $methods: array|string        — HTTP verb(s): 'GET' or ['GET', 'POST'] — ONE Route instance answers every listed verb
// $uri:     string              — URI pattern, e.g. "users/{user}" (optional params: "{id?}")
// $action:  callable|array|null — Closure, [Class, 'method'], or action array (['uses' => 'Class@method', ...]);
//                                 null = placeholder Closure that throws LogicException("Route for [...] has no action.")
//                                 Bare strings ('Class@method', invokable 'Class') are converted to ['uses' => ...] by Router::addRoute(), not by the constructor.
```

Verbs are stored as given (no case normalization); `Router::match()` uppercases, `Router::addRoute()` does not. Lowercase `get` never matches `MethodValidator` and does not get `HEAD`.

**Manifest restriction:** each declared route uses **exactly one verb** — a single `Route` instance. Different verbs on the same path need separate entries because middleware and behavior differ per verb.

`GET` implies `HEAD` (added automatically). The `prefix` key of the action array is consumed here.

### 1.2 Public properties

| Property | Type | Notes |
|---|---|---|
| `$uri` | `string` | URI pattern |
| `$methods` | `array` | HTTP verbs (GET implies HEAD) |
| `$action` | `array` | Parsed action array: `uses`, `controller`, `as`, `middleware`, `domain`, `prefix`, `where`, `missing`, `excluded_middleware`, `scope_bindings`, `metadata`, `can` |
| `$isFallback` | `bool` | Fallback flag |
| `$controller` | `mixed` | Resolved controller instance |
| `$defaults` | `array` | Route parameter defaults |
| `$wheres` | `array` | Regex constraints `{param: regex}` |
| `$parameters` | `array\|null` | Matched parameters (set by `bind()`) |
| `$parameterNames` | `array\|null` | Names from `{...}` placeholders |
| `$computedMiddleware` | `array\|null` | Cached gathered middleware |
| `$compiled` | `\Symfony\Component\Routing\CompiledRoute\|null` | `null` until `matches()`/`bind()`/`prepareForSerialization()` compiles it |
| `$validators` (static) | `array` | Match validators chain |

### 1.3 Public methods

**Identity / introspection**

| Method | Signature | Returns |
|---|---|---|
| `uri()` | `(): string` | URI |
| `methods()` | `(): array` | HTTP verbs |
| `getName()` | `(): ?string` | Route name (`action['as']`) |
| `getDomain()` | `(): ?string` | Domain without scheme |
| `getPrefix()` | `(): ?string` | Route prefix |
| `getActionName()` | `(): string` | `'Class@method'` or `'Closure'` |
| `getActionMethod()` | `(): string` | Method part of action |
| `getAction($key = null)` | `(?string $key = null): mixed` | Action array or one key |
| `named(...$patterns)` | `(string ...$patterns): bool` | Name matches glob pattern(s) |
| `httpOnly()` / `httpsOnly()` / `secure()` | `(): bool` | Scheme restrictions |
| `getControllerClass()` | `(): ?string` | Controller FQCN |
| `getController()` | `(): mixed` | Resolved controller instance; `null` for Closure actions |
| `getCompiled()` | `(): ?CompiledRoute` | Compiled route; `null` until compiled |
| `getOptionalParameterNames()` | `(): array<string, null>` | `{param?}` names |
| `getValidators()` | `(static): array` | Validator chain (Uri/Method/Scheme/Host) |
| `toSymfonyRoute()` | `(): SymfonyRoute` | Symfony representation |
| `getMetadata($key = null, $default = null)` | `(?string $key = null, $default = null): mixed` | Route metadata |

**Builder (fluent, return `$this`)** — these are the declaration targets.

| Method | Signature | Effect |
|---|---|---|
| `name($name)` | `(\BackedEnum\|string $name): $this` | Sets/appends `action['as']` |
| `uses($action)` | `(\Closure\|array\|string $action): $this` | Sets handler (`Class@method`, `[C, 'm']`, Closure) |
| `middleware($middleware = null)` | `(array\|string\|null $middleware = null): $this\|array` | **Appends** `action['middleware']`; extra string args collected via `func_get_args()`; `null` returns current |
| `withoutMiddleware($middleware)` | `(array\|string $middleware): $this` | Appends `action['excluded_middleware']` (single parameter — extra args are dropped) |
| `can($ability, $models = [])` | `(\UnitEnum\|string $ability, array\|string $models = []): $this` | Adds `can:{ability}` or `can:{ability},{models}` middleware |
| `domain($domain = null)` | `(\BackedEnum\|string\|null $domain = null): $this\|?string` | Sets `action['domain']`; merges `{field:param}` binding fields |
| `prefix($prefix)` | `(string\|null $prefix): $this` | Prepends to URI + `action['prefix']`; via `setUri()` **resets** binding fields (drops domain binding fields) |
| `where($name, $expression = null)` | `(array\|string $name, ?string $expression = null): $this` | Adds regex constraints (`['id' => '[0-9]+']`) |
| `setWheres(array $wheres)` | `(array $wheres): $this` | Bulk `where` (merges, does not replace) |
| `whereNumber` / `whereAlpha` / `whereAlphaNumeric` / `whereUuid` / `whereUlid` | `(array\|string $parameters): $this` | Preset constraints (trait `CreatesRegularExpressionRouteConstraints`) |
| `whereIn($parameters, array $values)` | `(array\|string $parameters, array $values): $this` | Enumerated-value constraint (same trait) |
| `defaults($key, $value)` | `(string $key, mixed $value): $this` | Sets one default |
| `setDefaults(array $defaults)` | `(array $defaults): $this` | Replaces defaults |
| `missing($missing)` | `(\Closure $missing): $this` | Stored in `action['missing']`; `SubstituteBindings` calls `$missing($request, $exception)` on `ModelNotFoundException` — must be a real callable (a class-string is not) |
| `fallback()` | `(): $this` | Marks as fallback route |
| `setFallback($isFallback)` | `(bool $isFallback): $this` | Sets fallback flag |
| `scopeBindings()` | `(): $this` | `action['scope_bindings'] = true` |
| `withoutScopedBindings()` | `(): $this` | `action['scope_bindings'] = false` |
| `withTrashed($withTrashed = true)` | `(bool $withTrashed = true): $this` | Allow soft-deleted model binding |
| `block($lockSeconds = 10, $waitSeconds = 10)` | `(int\|null $lockSeconds = 10, int\|null $waitSeconds = 10): $this` | Session lock; `null, null` = no blocking |
| `withoutBlocking()` | `(): $this` | Alias of `block(null, null)` |
| `metadata(array $metadata)` | `(array $metadata): $this` | Recursively merges `action['metadata']` (`RouteGroup::mergeMetadata`) |
| `setMetadata(array $metadata)` | `(array $metadata): $this` | Replaces metadata |
| `setUri($uri)` | `(string $uri): $this` | Replaces URI (re-parses binding fields) |
| `setAction(array $action)` | `(array $action): $this` | Replaces action array (re-applies `domain`, `can`) |
| `setBindingFields(array $bindingFields)` | `(array $bindingFields): $this` | Sets `{field:param}` binding fields |
| `setRouter(Router $router)` / `setContainer(Container $container)` | `($x): $this` | Wire dependencies |
| `when(...)` / `unless(...)` | `($value = null, ?callable $callback = null, ?callable $default = null): $this\|mixed` | Trait `Conditionable` |

Static (trait `Macroable`): `macro()`, `mixin()`, `hasMacro()`, `flushMacros()`. Static (trait `FiltersControllerMiddleware`): `methodExcludedByOptions()`.

**Binding-state / read-back (runtime, not declaration targets)**

| Method | Signature |
|---|---|
| `bind(Request $request)` | `($request): $this` — binds matched params |
| `hasParameters()` / `hasParameter($name)` | `(): bool` / `(string $name): bool` |
| `parameter($name, $default = null)` | `(string $name, $default = null): mixed` |
| `originalParameter($name, $default = null)` | `(string $name, $default = null): ?string` |
| `setParameter($name, $value)` | `(string $name, $value): void` |
| `forgetParameter($name)` | `(string $name): void` |
| `parameters()` / `originalParameters()` / `parametersWithoutNulls()` | `(): array` (throws `LogicException` if unbound) |
| `parameterNames()` | `(): array` — compiled from domain + URI; works unbound |
| `signatureParameters($conditions = [])` | `(array\|string $conditions = []): array` — reflection on action signature (string = `subClass`) |
| `bindingFieldFor($parameter)` / `bindingFields()` | `(int\|string $p): ?string` / `(): array` |
| `parentOfParameter($parameter)` | `(string $parameter): ?mixed` |
| `allowsTrashedBindings()` | `(): bool` |
| `enforcesScopedBindings()` / `preventsScopedBindings()` | `(): bool` |
| `locksFor()` / `waitsFor()` | `(): ?int` |
| `gatherMiddleware()` | `(): array` — route + controller middleware, de-duplicated |
| `controllerMiddleware()` / `excludedControllerMiddleware()` / `excludedMiddleware()` | `(): array` |
| `getMissing()` | `(): ?\Closure` |
| `matches(Request $request, $includingMethod = true)` | `($request, bool $includingMethod = true): bool` |
| `run()` | `(): mixed` — dispatches controller or callable |
| `flushController()` | `(): void` |
| `controllerDispatcher()` | `(): \Illuminate\Routing\Contracts\ControllerDispatcher` |
| `resolveMethodDependencies(array $parameters, ReflectionFunctionAbstract $reflector)` | `(): array` — trait `ResolvesRouteDependencies` |
| `prepareForSerialization()` | `(): void` — serializes Closures for route cache |
| `__get($key)` | `($key): mixed` — dynamic parameter access |

---

## 2. Manifest schema proposal (`app.yml`)

### 2.1 Design rule

> **Every key in a route object is a `Route` method name. Every value is the argument(s) of that method's signature.** Three reserved keys (`path`, `methods`, `action`) feed `Router::addRoute()`; everything else is applied to the returned `Route` by one of four fixed dispatch shapes (§2.5).

This keeps registration a small loop and guarantees the YAML can never drift from the underlying API.

### 2.2 Callable values

YAML has no `::class` constant — a class is written as its **bare FQCN string**. Any value that is a callable in the PHP signature accepts one of:

| YAML form | PHP equivalent |
|---|---|
| `App\Http\Controllers\UserController@index` | `'App\Http\Controllers\UserController@index'` string callback |
| `App\Http\Controllers\UserController` | `__invoke` controller (FQCN required; must define `__invoke`) |
| `[App\Http\Controllers\UserController, index]` | `[UserController::class, 'index']` array callable |

Global function names (e.g. `strlen`) are **not** valid actions: `RouteAction::makeInvokable()` throws `UnexpectedValueException`.

### 2.3 Full example

```yaml
providers:
  - class: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\AppServiceProvider
routes:
  # simple: path + methods + action are the Router::addRoute() args
  - path: "/"
    methods: GET
    action: App\Http\Controllers\HomeController
    name: home

  # every remaining key = Route method name; value = its signature
  - path: "users/{user}"
    methods: GET
    action: [App\Http\Controllers\UserController, show]
    name: users.show                      # -> name(string $name)
    prefix: api                           # -> prefix(string $prefix)  (apply before domain)
    domain: "{account}.example.com"       # -> domain(string $domain)
    middleware:                           # -> middleware(array $middleware)
      - auth:sanctum
      - verified
    withoutMiddleware: [web]              # -> withoutMiddleware(array|string $middleware)
    can:                                  # -> can(ability: ..., models: ...)  named args
      ability: view
      models: user                        # route parameter name -> bound model instance
    where:                                # -> where(array $name)
      user: '[0-9]+'
    setDefaults:                          # -> setDefaults(array $defaults)
      user: 1
    missing: App\Http\Handlers\UserMissing # -> missing(Closure) — provider wraps the invokable class in a Closure
    scopeBindings: true                    # zero-arg flag: true -> scopeBindings(), false -> skipped
    withTrashed: true                      # -> withTrashed(bool $withTrashed)
    block:                                 # -> block(lockSeconds: ..., waitSeconds: ...)  named args
      lockSeconds: 10
      waitSeconds: 5
    metadata:                              # -> metadata(array $metadata)
      group: admin

  # same path, different verb -> separate route, separate behavior
  - path: "users/{user}"
    methods: PUT
    action: [App\Http\Controllers\UserController, update]
    name: users.update
    middleware: [auth:sanctum]
    can:
      ability: update
      models: user

  - path: "users/{user}"
    methods: DELETE
    action: [App\Http\Controllers\UserController, destroy]
    name: users.destroy
    middleware: [auth:sanctum]

  # fallback route: an action is required (null action = LogicException at dispatch)
  - path: "{any}"
    methods: GET
    action: App\Http\Controllers\FallbackController
    where:
      any: '.*'                            # mirrors Router::fallback(); without it {any} matches one segment only
    fallback: true
```

### 2.4 Key → method → signature map

| YAML key | Route method | Value shape (YAML) | PHP signature | Dispatch (§2.5) |
|---|---|---|---|---|
| `path` | `Router::addRoute` (arg 2) | `string` | `$uri` | reserved |
| `methods` | `Router::addRoute` (arg 1) | `string` | `$methods` — exactly one verb, uppercased by the provider | reserved |
| `action` | `Router::addRoute` (arg 3) | callable (§2.2) | `$action` | reserved |
| `name` | `name` | `string` | `(\BackedEnum\|string $name)` | value |
| `prefix` | `prefix` | `string` | `(?string $prefix)` | value |
| `domain` | `domain` | `string` | `(\BackedEnum\|string $domain)` | value |
| `middleware` | `middleware` | `string \| list<string>` | `(array\|string $middleware)` | value |
| `withoutMiddleware` | `withoutMiddleware` | `string \| list<string>` | `(array\|string $middleware)` | value |
| `can` | `can` | `{ability: string, models?: string \| list<string>}` | `($ability, $models = [])` | named |
| `where` | `where` | `map<param, regex>` | `(array $name)` | value |
| `setDefaults` | `setDefaults` | `map<param, value>` | `(array $defaults)` | value |
| `missing` | `missing` | invokable FQCN | `(\Closure $missing)` | closure |
| `fallback` | `fallback` | `bool` | `(): $this` | flag |
| `scopeBindings` | `scopeBindings` | `bool` | `(): $this` | flag |
| `withoutScopedBindings` | `withoutScopedBindings` | `bool` | `(): $this` | flag |
| `withTrashed` | `withTrashed` | `bool` | `(bool $withTrashed = true)` | value |
| `block` | `block` | `{lockSeconds?: ?int, waitSeconds?: ?int}` | `(?int $lockSeconds = 10, ?int $waitSeconds = 10)` | named |
| `withoutBlocking` | `withoutBlocking` | `bool` | `(): $this` | flag |
| `metadata` | `metadata` | `map<string, mixed>` | `(array $metadata)` | value |

Unknown keys must be a validation error (fail-fast). **`DataModel` does not do this** — `Provider::from([... 'bogus' => 1])` silently ignores the key — so the route model must diff input keys against this table and throw.

### 2.5 Registration algorithm (for the provider)

```php
$router = $this->app->make(Router::class);

foreach ($manifest->routes as $route) {
    $instance = $router->addRoute(                 // Router::addRoute($methods, $uri, $action): Route
        strtoupper($route->methods), $route->path, $route->action,
    );

    foreach ($route->builders() as $method => $value) {  // every key except path/methods/action
        match ($method) {
            'fallback', 'scopeBindings', 'withoutScopedBindings', 'withoutBlocking'
                => $value ? $instance->{$method}() : null,                         // flag
            'can', 'block'
                => $instance->{$method}(...$value),                               // named args
            'missing'
                => $instance->missing(static fn ($request, $e) => app($value)($request, $e)), // closure
            default
                => $instance->{$method}($value),                                  // value: one positional arg
        };
    }
}
```

`$route->builders()` is the single seam: a `DataModel`-based `Route` class (sibling of `src/Provider.php`) that keeps reserved keys as properties and exposes the rest as an ordered `map<methodName, value>`. Ordering follows YAML document order, except `prefix` must run before `domain` (`prefix()` → `setUri()` resets binding fields, discarding `{field:param}` fields parsed from the domain).

Do **not** spread every value (`...Arr::wrap($value)`): string-keyed maps become PHP named arguments, so `where`/`setDefaults`/`metadata` throw `Error: Unknown named parameter`, `withoutMiddleware` drops every item after the first, and `fallback: false` / `scopeBindings: false` still set the flag.

### 2.6 Notes / non-goals

- `run()`, `bind()`, `matches()`, parameter accessors, serialization helpers, and `setRouter`/`setContainer` are **runtime** API — intentionally not expressible in YAML.
- `middleware` appends (Laravel semantics); declaring it once per route in YAML is the expected usage.
- `methods` is a **single verb** (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS`, or `HEAD`). `ANY` is not a verb — `Router::any()` expands to `Router::$verbs`; a literal `'ANY'` route never matches. A separate `HEAD` entry on a `GET` path overwrites the `GET` route's implicit `HEAD` in `RouteCollection`. One route entry = one `Route` instance = one behavior/middleware set; sharing a path across verbs means separate entries (replaces the current fixture's `methods: [{method: get, middleware: []}]` per-verb structure; its lowercase `get` must be uppercased).
- `can` is expanded to `can:{ability}` / `can:{ability},{models}` middleware by `Route::can()` — declared as a map because its signature is two-parameter. `models` entries are route parameter names (resolved to bound models by `Authorize::getModel()`) or FQCNs (passed as class strings, e.g. for `create`/`viewAny`).
- `missing` Closures are serialized by `prepareForSerialization()` for `route:cache`; the wrapper Closure captures only the class-string, so it is cache-safe.

---