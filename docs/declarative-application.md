# Declarative Application — `Illuminate\Foundation\Application` API & Manifest Schema

Source of truth: `vendor/laravel/framework/src/Illuminate/Foundation/Application.php` (`laravel/framework` v13.33.0), with `Illuminate/Container/Container.php`, `Illuminate/Container/BoundMethod.php`, and `Illuminate/Foundation/Configuration/ApplicationBuilder.php`.

Goal: an `app:` block in `manifest/app.yml` whose **keys map 1:1 onto `Application` method names** and whose **values map 1:1 onto those methods' signatures**, so the provider applies the whole block with a small, fixed set of loops (§2.5). `Application` **is** the container; its declarable surface is container bindings (§1.3), paths, lifecycle hooks and the locale. The root-level `config`, `providers`, `routes` and `requests` blocks are their own documents and are not repeated here; `app:` holds only the `Application` surface.

---

## 1. Public API of `Application::class`

### 1.1 Lifecycle position (when the declarations run)

```php
new Application($basePath);                        // registerBaseBindings, base providers, core aliases

// bootstrap() runs, in order:
//   LoadEnvironmentVariables -> environmentPath()/environmentFile() consumed here
//   LoadConfiguration        -> config_path() consumed here; `config` repository filled
//   ... then registerConfiguredProviders()

registerConfiguredProviders();                     // Illuminate\* first, then package providers
                                                   // -> each provider's register()
                                                   // -> fireAppCallbacks($registeredCallbacks)   LAST

$app->boot();                                      // fireAppCallbacks($bootingCallbacks)
                                                   // -> each provider's boot()
                                                   // -> booted = true
                                                   // -> fireAppCallbacks($bootedCallbacks)       LAST

// response sent, then:
$app->terminate();                                 // $this->call($terminatingCallbacks[$i])      container call
```

A declaration applied in `LaravelDeclarationProvider::register()` is therefore visible to:

- every provider's `boot()`, whatever the provider order,
- the `register()` of every declared provider (`providers` registers from `boot()`),
- everything runtime: `make()`, `app()`, method injection.

It is **not** visible to:

- providers that registered earlier (framework `Illuminate\*` providers, package discovery) which already resolved instances in `register()`. A later `bind()` drops the stale instance (`dropStaleInstances`), but objects already handed out keep it; an `extend()` on a resolved instance is applied immediately and fires `rebound`,
- state consumed during bootstrap before any provider runs: the config file location (`config_path()`), the `.env` location (`environmentPath()`/`environmentFile()`), and the bootstrapper callbacks (`beforeBootstrapping`, `afterBootstrapping`, `afterLoadingEnvironment`) — see §2.6.

Laravel's own imperative counterpart is `ApplicationBuilder`: `withBindings()`, `withSingletons()` and `withScopedSingletons()` wrap the maps in `registered()` callbacks and apply them with plain `$app->bind/singleton/scoped` loops. The manifest applies the same calls, in the same phase.

### 1.2 Properties

All state is protected and set through the methods of §1.3 — there is nothing to declare directly.

| Property | Set by |
|---|---|
| `$basePath`, `$appPath`, `$bootstrapPath`, `$configPath`, `$databasePath`, `$langPath`, `$publicPath`, `$storagePath` | `setBasePath()` / `use*Path()` |
| `$environmentPath`, `$environmentFile` | `useEnvironmentPath()` / `loadEnvironmentFrom()` |
| `$registeredCallbacks`, `$bootingCallbacks`, `$bootedCallbacks`, `$terminatingCallbacks` | `registered()` / `booting()` / `booted()` / `terminating()` |

### 1.3 Public methods

**Container (inherited from `Container` — the primary declaration targets)**

| Method | Signature | Effect |
|---|---|---|
| `bind` | `($abstract, $concrete = null, $shared = false): void` | `$concrete = null` is a **self-binding** (concrete = abstract); drops any stale instance |
| `bindIf` | `($abstract, $concrete = null, $shared = false): void` | only `bind`s when `! $this->bound($abstract)` |
| `singleton` | `($abstract, $concrete = null): void` | `bind(..., shared: true)` |
| `singletonIf` | `($abstract, $concrete = null): void` | only when unbound |
| `scoped` | `($abstract, $concrete = null): void` | shared binding flushed between requests (`forgetScopedInstances`), Octane-safe |
| `scopedIf` | `($abstract, $concrete = null): void` | only when unbound |
| `instance` | `($abstract, $instance): mixed` | binds an existing value; fires `rebound` if previously bound |
| `alias` | `($abstract, $alias): void` | `$abstract` **first**, `$alias` second; `$app->make($alias)` resolves through it |
| `extend` | `($abstract, Closure $closure): void` | the Closure receives `($instance, $app)`; if the instance is already resolved it is applied and rebound immediately |

The `$concrete` argument accepts a class-string or a `Closure` — not `Class@method` (`Container::build()` reflects it). This is why the manifest's reference system (§2.2) resolves a Closure through a `.php` file.

**Paths**

| Method | Signature | Effect |
|---|---|---|
| `setBasePath` | `(string $basePath): $this` | root of every other path; re-runs `bindPathsInContainer()` |
| `useAppPath` / `useBootstrapPath` / `useConfigPath` / `useDatabasePath` / `useLangPath` / `usePublicPath` / `useStoragePath` | `(string $path): $this` | sets the property **and** rebinds the `path.*` container instance (`path`, `path.config`, ...) |
| `useEnvironmentPath` / `loadEnvironmentFrom` | `(string $path): $this` / `(string $file): $this` | consumed by `LoadEnvironmentVariables` — too late to take effect (§2.6) |
| `path` / `basePath` / `bootstrapPath` / `configPath` / `databasePath` / `langPath` / `publicPath` / `storagePath` / `resourcePath` / `viewPath` | `($path = ''): string` | getters — `app_path()`, `base_path()`, ... helpers call these |

**Lifecycle hooks**

| Method | Signature | Invocation |
|---|---|---|
| `registered` | `(callable $callback): void` | `fireAppCallbacks`: `$callback($this)` — end of `registerConfiguredProviders()`, after every `register()` |
| `booting` | `(callable $callback): void` | `$callback($this)` — first thing in `boot()` |
| `booted` | `(callable $callback): void` | `$callback($this)` — last thing in `boot()`; **fires immediately** if the app already booted |
| `terminating` | `(callable\|string $callback): $this` | `$this->call($callback)` in `terminate()` — a native container call with **no named arguments** (pure DI); an invokable class-string passes through untouched |

**Locale**

| Method | Signature | Effect |
|---|---|---|
| `setLocale` | `($locale): void` | `config->set('app.locale')` **and** `$this['translator']->setLocale()` **and** dispatches `LocaleUpdated` |
| `getLocale` | `(): string` | `config('app.locale')` |
| `isLocale` | `($locale): bool` | loose comparison with `getLocale()` |

**Runtime (not declaration targets)**

`version`, `environment`, `isLocal`, `isProduction`, `runningInConsole`, `runningConsoleCommand`, `runningUnitTests`, `hasDebugModeEnabled`, `isBooted`, `hasBeenBooted`, `make`, `bound`, `resolved`, `boot`, `register`, `registerConfiguredProviders`, `getProvider(s)`, `resolveProvider`, deferred-service methods (`addDeferredServices`, `isDeferredService`, ...), `handle`, `handleRequest`, `handleCommand`, `shouldSkipMiddleware`, `abort`, `terminate`, `isDownForMaintenance`, `maintenanceMode`, `flush`, `getNamespace`, `provideFacades`, `registerCoreContainerAliases`, `configurationIsCached`, `routesAreCached`, `eventsAreCached`, `getCached*Path`.

---

## 2. Manifest schema proposal

### 2.1 Design rule

> **Every key in the `app:` block is an `Application` (or inherited `Container`) method name. Every value is the argument(s) of that method's signature.** A map gives one call per entry (`abstract => concrete/alias/value`); a list gives one self-binding per entry; a reference value is resolved per §2.2.

This is the same rule as declarative-routing (§2.1): the YAML can never drift from the underlying API, because a key *is* the method it calls.

### 2.2 PHP references

YAML has no `::class` constant, `Closure` or `new`. A callable or object is written as a **reference string**, resolved through `Container::call()` / `require`:

| YAML form | Resolved as |
|---|---|
| `App\Hooks\FlushMetrics` | invokable class: `make()` + `__invoke()` (`BoundMethod` defaults to `__invoke` when `method_exists($callback, '__invoke')`) |
| `App\Hooks\Metrics@handle` | `make()` + instance method (`BoundMethod::isCallableWithAtSign`) |
| `App\Hooks\Metrics::flush` | static method (native PHP callable string) |
| `App\Hooks\flush_metrics` | namespaced function. PSR-4 autoloads classes only — load the file through Composer `autoload.files` |
| `hooks/flush_metrics.php` | **a PHP file**: `require`d once, its **return value is used** — the natural home for Closures |

There is no array form (`[Class, 'method']` is called statically by `Container::call`, as in declarative-requests §2.2).

**The `.php` form.** A reference ending in `.php` is required exactly once (memoized by absolute path) and its return value is used wherever a value is expected — a `Closure` for `extend` and lifecycle hooks, a `Closure` concrete for `bind`/`singleton`/`scoped`, an instance or scalar for `instance`. The path is resolved through `base_path()` unless it is already absolute. The file is plain PHP with no package dependency:

```php
// app/extensions/store-cache.php — the file IS the extender
return static function (Illuminate\Cache\Repository $store, Application $app): Illuminate\Cache\Repository {
    // $app->call() injects these by type-hint...

    return $store;
};
```

Function references via `autoload.files` (declarative-requests §2.2) work identically here — both forms feed the same `Container::call()`.

**Parameters.** Slots invoked through `Container::call()` pass named arguments, and `BoundMethod::addDependencyForCallParameter()` matches by **name** first — name them `$app` and `$instance`:

- every lifecycle reference (`registered`, `booting`, `booted`) receives `['app' => $app]` plus DI;
- every `extend` reference receives `['instance' => $instance, 'app' => $app]` plus DI;
- a `terminating` reference receives **no named arguments** — Laravel's `terminate()` calls `$this->call($callback)` directly, so everything is DI.

**Value or reference.** For `bind`/`singleton`/`scoped`/`terminating` a string ending in `.php` is required once; every other string is passed through as the class-string Laravel expects. For `instance`, a class-string or interface-string is `make()`d eagerly, a `.php` reference is required, and every other value is bound exactly as YAML decoded it.

### 2.3 Full example

```yaml
config:                                         # root-level blocks: their own documents
  app:
    name: Tenant Console

providers:
  - class: App\Providers\AppServiceProvider

app:                                            # ——— Application surface (this document) ———
  bind:                                         # -> bind(string $abstract, string $concrete)
    App\Contracts\Pdf: App\Services\DomPdf
    App\Contracts\Slugger: app/binders/slugger.php   # .php file: the returned Closure is $concrete
  bindIf:                                       # -> bindIf(): skipped when something is already bound
    App\Contracts\Cache: App\Services\RedisCache
  singleton:                                    # -> singleton(string $abstract, string $concrete)
    App\Services\TenantContext: App\Services\TenantContext
    - App\Services\ReportBuilder                # list item -> self-binding singleton('App\Services\ReportBuilder')
  singletonIf:
    App\Services\SlowWarmup: App\Services\SlowWarmup
  scoped:                                       # -> scoped(): shared per request, flushed between them
    App\Services\RequestLog: App\Services\RequestLog
  scopedIf:
    App\Services\BudgetGuard: App\Services\BudgetGuard

  instance:                                     # -> instance(string $abstract, mixed $instance)
    app.signature: "1.0"                        # literal scalar, bound as-is
    App\Contracts\Clock: App\Services\Clock     # class-string -> make()d eagerly
    app.rate_limiter: app/instances/limiter.php # .php file: the returned instance is bound as-is

  alias:                                        # -> alias(string $abstract, string $alias)  — abstract FIRST
    App\Services\TenantContext: context         # app('context') resolves the singleton

  extend:                                       # -> extend(string $abstract, Closure)  (.php file IS the Closure)
    cache.store: app/extensions/store-cache.php

  useAppPath: src                               # -> useAppPath(string $path): rebinds the `path` instance
  useDatabasePath: database                     # -> useDatabasePath(string $path)

  setLocale: fr                                 # -> setLocale(string $locale)

  registered: app/hooks/registered.php          # fires at the end of registerConfiguredProviders()
  booting: App\Hooks\WarmConnections            # fires first in boot()
  booted: app/hooks/booted.php                  # fires last in boot()
  terminating: App\Hooks\FlushMetrics           # fires in terminate(), pure DI
```

### 2.4 Key → method → signature map

| YAML key (under `app:`) | `Application`/`Container` method | Value shape (YAML) | Dispatch (§2.5) |
|---|---|---|---|
| `bind` | `bind` | `map<abstract, class-string \| .php file>` | map entry → `$app->bind($abstract, $concrete)` |
| `bindIf` | `bindIf` | same | `$app->bindIf($abstract, $concrete)` |
| `singleton` | `singleton` | `map<abstract, concrete>` \| `list<abstract>` | string key → `$app->singleton($abstract, $concrete)`; int key → `$app->singleton($concrete)` (mirrors `ApplicationBuilder::withSingletons`) |
| `singletonIf` | `singletonIf` | same | same shape |
| `scoped` | `scoped` | same | same shape |
| `scopedIf` | `scopedIf` | same | same shape |
| `instance` | `instance` | `map<abstract, value \| class-string \| .php file>` | value rule (§2.2) → `$app->instance($abstract, $value)` |
| `alias` | `alias` | `map<abstract, alias>` | `$app->alias($abstract, $alias)` |
| `extend` | `extend` | `map<abstract, reference>` | wrapped in `Closure ($instance, $app)` → `$app->call(...)` |
| `useAppPath` | `useAppPath` | `string` | `$app->useAppPath($path)` |
| `useBootstrapPath` | `useBootstrapPath` | `string` | `$app->useBootstrapPath($path)` |
| `useConfigPath` | `useConfigPath` | `string` | `$app->useConfigPath($path)` |
| `useDatabasePath` | `useDatabasePath` | `string` | `$app->useDatabasePath($path)` |
| `useLangPath` | `useLangPath` | `string` | `$app->useLangPath($path)` |
| `usePublicPath` | `usePublicPath` | `string` | `$app->usePublicPath($path)` |
| `useStoragePath` | `useStoragePath` | `string` | `$app->useStoragePath($path)` |
| `setLocale` | `setLocale` | `string` | `$app->setLocale($locale)` |
| `registered` | `registered` | `list<reference>` | wrapped in `Closure ($app)` → `$app->call(...)` |
| `booting` | `booting` | `list<reference>` | wrapped in `Closure ($app)` → `$app->call(...)` |
| `booted` | `booted` | `list<reference>` | wrapped in `Closure ($app)` → `$app->call(...)` |
| `terminating` | `terminating` | `list<reference>` | `.php` resolved eagerly; otherwise passed through — Laravel's own `Container::call` |

Fixed order, mirroring Laravel's semantics: `bind` → `bindIf` → `singleton` → `singletonIf` → `scoped` → `scopedIf` → `instance` → `alias` → `extend` → paths → `setLocale` → `registered` → `booting` → `booted` → `terminating`. Each call overwrites; when two groups name the same abstract, the later group wins (that is Laravel's own behavior for repeated `bind`/`instance` calls).

Unknown keys must be a validation error (fail-fast). **`DataModel` does not do this** — as in declarative-routing §2.4, the model must diff input keys against this table and throw.

### 2.5 Registration algorithm (for the provider)

A new `DataModel`, `src/App.php`, hydrated from the `app:` key by a new `Manifest` property. `Manifest` owns the root-level blocks; `App` owns only this surface:

```php
// src/Manifest.php
public const string app = 'app';

/** The `Application` surface; null when the manifest has no `app:` block */
#[Describe([Describe::nullable => true])]
public ?App $app;
```

`nullable`, not `default`: `DataModel` calls a callable default as `(null, $context, ...)`, and `App::from()`'s second parameter is `$instance`, so `Describe::default => [App::class, 'from']` would hydrate into the context array.

Its properties are plain arrays and strings because values are user data:

```php
// src/App.php
public const string bind = 'bind';

/** @var array<string, string> abstract => class-string or `.php` file */
#[Describe([Describe::default => []])]
public array $bind;

// bindIf, singleton, singletonIf, scoped, scopedIf: same shape, Describe::default => []
// instance, alias, extend: same shape, Describe::default => []
// useAppPath ... useStoragePath: #[Describe([Describe::nullable => true])] public ?string $useAppPath;
// setLocale: ?string
// registered, booting, booted, terminating: #[Describe([Describe::default => []])] public array $registered; // ...
```

In `LaravelDeclarationProvider::register()`, after `registerConfig()`:

```php
$this->registerApplication($Manifest);
```

```php
private function registerApplication(Manifest $Manifest): void
{
    $app = $this->app;
    $App = $Manifest->app ?? App::from();

    foreach (['bind' => 'bind', 'bindIf' => 'bindIf', 'singleton' => 'singleton', 'singletonIf' => 'singletonIf',
              'scoped' => 'scoped', 'scopedIf' => 'scopedIf'] as $key => $method) {
        foreach ($App->{$key} as $abstract => $concrete) {
            is_string($abstract) ? $app->{$method}($abstract, $this->concrete($concrete)) : $app->{$method}($this->concrete($concrete));
        }
    }

    foreach ($App->instance as $abstract => $value) {
        $app->instance($abstract, $this->instantiate($value));
    }

    foreach ($App->alias as $abstract => $alias) {
        $app->alias($abstract, $alias);
    }

    foreach ($App->extend as $abstract => $reference) {
        $app->extend($abstract, $this->wrapExtender($reference));
    }

    foreach (['useAppPath', 'useBootstrapPath', 'useConfigPath', 'useDatabasePath',
              'useLangPath', 'usePublicPath', 'useStoragePath'] as $method) {
        if (($path = $App->{$method}) !== null) {
            $app->{$method}($path);
        }
    }

    if ($App->setLocale !== null) {
        $app->setLocale($App->setLocale);
    }

    foreach ($App->registered as $reference) { $app->registered($this->wrapCallback($reference)); }
    foreach ($App->booting as $reference)    { $app->booting($this->wrapCallback($reference)); }
    foreach ($App->booted as $reference)     { $app->booted($this->wrapCallback($reference)); }
    foreach ($App->terminating as $reference) { $app->terminating($this->concrete($reference)); }
}

/** A `.php` reference is required once, memoized; anything else passes through. */
private function concrete(string $reference): mixed
{
    return str_ends_with($reference, '.php') ? $this->fileValue($reference) : $reference;
}

private function instantiate(mixed $value): mixed
{
    if (is_string($value) && str_ends_with($value, '.php')) {
        return $this->fileValue($value);
    }

    return is_string($value) && (class_exists($value) || interface_exists($value)) ? $this->app->make($value) : $value;
}

private function wrapExtender(string $reference): Closure
{
    $value = $this->concrete($reference);

    return static function (mixed $instance, mixed $app) use ($value): mixed {
        return $app->call($value, ['instance' => $instance, 'app' => $app]);
    };
}

private function wrapCallback(string $reference): Closure
{
    $value = $this->concrete($reference);

    return static function (mixed $app) use ($value): mixed {
        return $app->call($value, ['app' => $app]);
    };
}

private function fileValue(string $file): mixed
{
    static $loaded = [];

    $path = is_file($file) ? $file : base_path($file);

    return $loaded[$path] ??= require $path;
}
```

`is_string($abstract)` distinguishes the map form (string key = `$abstract` present) from the list form (int key = self-binding) — the exact ternary `ApplicationBuilder::withSingletons()` uses. The wrapper Closures capture only strings, so nothing here holds unserializable state at declaration time.

### 2.6 Notes / non-goals

- **Bootstrap-consumed keys, by design.** `useConfigPath` rebinds `path.config`, but `LoadConfiguration` already read `config_path()` — config files are not reloaded. `useEnvironmentPath`/`loadEnvironmentFrom` are consumed by `LoadEnvironmentVariables` before any provider: **non-goals**. `beforeBootstrapping`/`afterBootstrapping`/`afterLoadingEnvironment` callbacks fire during bootstrap, before this provider registers: **non-goals**.
- **`app.env`.** `detectEnvironment()` runs during bootstrap; `config: {app: {env: ...}}` (declarative-configuration §2.6) is the declaration point. `environment()`/`isLocal()`/`isProduction()` read `$this['env']` — runtime, not declarable here.
- **`terminating` is DI-only.** `Application::terminate()` calls `$this->call($callback)` with no named arguments, so a terminating reference receives its dependencies by type-hint alone. It runs after the response is sent — never use it to alter it.
- **`booted` fires immediately when already booted** (Laravel's own behavior). Within a normal request lifecycle the app is not yet booted when this provider registers, so declared hooks fire in the same `boot()`.
- **`instance` class-string ambiguity.** A literal scalar that happens to equal a class name (`instance: {tag: App\Services\Clock}`) is `make()`d. Bind such literals through a `.php` file returning the string if the distinction matters.
- **No Closure literals in YAML — by design.** The `.php` file form is the only way to declare a Closure (concrete, extender, hook). It keeps the manifest decodable by plain YAML parsers and keeps Closures in real, autoloadable, reviewable PHP.
- **`alias` direction.** The map is `{abstract: alias}` — signature order (`alias($abstract, $alias)`), same rule as `bind`. It is deliberately the inverse of `config/app.php`'s facade `aliases` map (`alias => class`), which is `AliasLoader`, a different API.
- **`config.app.locale` vs `setLocale`.** The config block writes the repository only; the translator and `LocaleUpdated` listeners keep their old locale. `setLocale` is the declaration that propagates (§1.3).
- **`config:cache` / caching.** Nothing here is cached by `config:cache` beyond what declarative-configuration already covers; bindings and hooks re-apply every boot, with no snapshot to invalidate.
- **Deferred services.** `addDeferredServices()` is a **non-goal** for now: package discovery covers deferred providers, and the deferred-service lifecycle interacts with `ProviderRepository` caching.
- **Not a validation layer.** Values pass through as YAML decoded them; nothing re-evaluates strings. A binding that names an unloaded class fails at resolve time with Laravel's own `BindingResolutionException` / `ReflectionException`, at first `make()`.

---

## 3. Implementation plan

1. **`src/App.php`** + **`src/Manifest.php`** — create `App` (`final readonly`, `use DataModel`) with the `DataModel` properties of §2.5 (`bind`, `bindIf`, `singleton`, `singletonIf`, `scoped`, `scopedIf`, `instance`, `alias`, `extend`, the seven `use*Path` properties, `setLocale`, `registered`, `booting`, `booted`, `terminating`), each with a `public const string <name> = '<name>';` key, matching the existing `Provider`/`Request` style; add the nullable `Manifest::$app` of §2.5.
2. **`src/LaravelDeclarationProvider.php`** — add `registerApplication(Manifest $Manifest)` and the four helpers (`concrete`, `instantiate`, `wrapExtender`, `wrapCallback`, `fileValue`) of §2.5; call it from `register()` right after `registerConfig($Manifest)`.
3. **Validation** — extend the unknown-key check used for routes/requests (declarative-routing §2.4) to the new `App` keys so a typo fails fast instead of hydrating silently.
4. **Fixtures** — `tests/Fixtures/App/Application/`: `DomPdf`, `RedisCache`, `TenantContext`, `ReportBuilder`, `Clock`, a `Binders/slugger.php` returning a Closure, `Extensions/store-cache.php`, `Instances/limiter.php`, `Hooks/registered.php`, `Hooks/booted.php`, `Hooks/FlushMetrics`, `Hooks/WarmConnections`; extend the `app:` block of `tests/Fixtures/manifest/app.yml` with the `app:` block of §2.3.
5. **Tests** — `tests/Feature/ApplicationRegistrationTest.php`:
   - `bind` resolves the interface to the impl with distinct instances per resolve; `singleton` shares one; `scoped` shares within a request and flushes with `forgetScopedInstances`;
   - `bindIf`/`singletonIf`/`scopedIf` are skipped when a binding already exists;
   - `instance` binds the literal scalar, eagerly `make()`s the class-string, and binds the `.php` file's return value;
   - `alias` resolves through `app('context')`;
   - `extend` transforms `cache.store` via the `.php` extender;
   - `useAppPath` rebinds `app_path()` and the `path` container instance;
   - `setLocale` updates config, the translator, and dispatches `LocaleUpdated`;
   - lifecycle hooks fire in order (`registered` → `booting` → `booted`) and `terminating` fires on kernel terminate, with DI injection;
   - a `.php` reference is required exactly once across two boots.
6. **`composer check`** — lint, rector, phpstan, coverage, bc-check must pass; the new `App` class and `Manifest::$app` are public API, so `bc-check` snapshots them.

---

### Sources

1. `Application` binds, hooks, locale, paths — [vendor/laravel/framework/src/Illuminate/Foundation/Application.php](https://github.com/laravel/framework/blob/master/src/Illuminate/Foundation/Application.php)
2. Container `bind`/`bindIf`/`singleton`/`singletonIf`/`scoped`/`scopedIf`/`instance`/`alias`/`extend` — [vendor/laravel/framework/src/Illuminate/Container/Container.php](https://github.com/laravel/framework/blob/master/src/Illuminate/Container/Container.php)
3. Reference invocation semantics (`__invoke` default, `Class@method`) — [vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php](https://github.com/laravel/framework/blob/master/src/Illuminate/Container/BoundMethod.php)
4. Laravel's own declarative precedent (`withBindings`, `withSingletons`, `withScopedSingletons`) — [vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php](https://github.com/laravel/framework/blob/master/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php)
5. Request lifecycle and `terminate()` — [laravel.com/docs/lifecycle](https://laravel.com/docs/lifecycle), [laravel.com/docs/container](https://laravel.com/docs/container)
