# Declarative Application — `Illuminate\Foundation\Application` API & Manifest Schema

Source of truth: `vendor/laravel/framework/src/Illuminate/Foundation/Application.php` (`laravel/framework` v13.33.0), with `Illuminate/Container/Container.php`, `Illuminate/Container/BoundMethod.php`, `Illuminate/Foundation/ProviderRepository.php` and `Illuminate/Foundation/Configuration/ApplicationBuilder.php`.

Goal: an `app:` block in `manifest/app.yml` whose **keys map 1:1 onto `Application` method names** and whose **values map 1:1 onto those methods' signatures**, so the provider applies the whole block with a small, fixed set of loops (§2.5), in the same phase as Laravel's own `ApplicationBuilder::withBindings()` (§1.1). `Application` **is** the container; its declarable surface is container bindings (§1.3), paths, lifecycle hooks and the locale. The root-level `config`, `providers`, `routes` and `requests` blocks are their own documents and are not repeated here; `app:` holds only the `Application` surface.

---

## 1. Public API of `Application::class`

### 1.1 Lifecycle position (when the declarations run)

```php
new Application($basePath);                        // setBasePath() -> bindPathsInContainer(), base bindings, base providers, core aliases

// Kernel::bootstrap() runs, in order:
//   LoadEnvironmentVariables -> environmentPath()/environmentFile() consumed here
//   LoadConfiguration        -> configPath() and getCachedConfigPath() (under bootstrapPath()) consumed here;
//                               ./config/*.php evaluated, so every storage_path()/database_path()/... in them is fixed now
//   HandleExceptions, RegisterFacades
//   RegisterProviders        -> registerConfiguredProviders()
//   BootProviders            -> boot()

registerConfiguredProviders();                     // eager providers: Illuminate\* -> package-discovered -> rest of app.providers
                                                   // -> each eager provider's register()      LaravelDeclarationProvider queues one registered() callback
                                                   // -> addDeferredServices()                 deferred services become bound() / make()-able
                                                   // -> fireAppCallbacks($registeredCallbacks)   <- the `app:` block is applied HERE

$app->boot();                                      // fireAppCallbacks($bootingCallbacks)
                                                   // -> each provider's boot()               (the declared `providers` register here)
                                                   // -> booted = true
                                                   // -> fireAppCallbacks($bootedCallbacks)

// response sent, then Kernel::terminate():
$app->terminate();                                 // $this->call($terminatingCallbacks[$i])  container call, no parameters
```

**Why `registered()`, not `register()`.** `ProviderRepository::load()` calls `addDeferredServices()` only **after** every eager `register()`. Inside `LaravelDeclarationProvider::register()` no deferred service is known yet, so:

- `bindIf`/`singletonIf`/`scopedIf` would bind over a deferred-provided abstract (`Application::bound()` is `isDeferredService($abstract) || parent::bound($abstract)`), and the deferred provider would overwrite it on first `make()`;
- `setLocale` would throw `BindingResolutionException` (`$this['translator']` is deferred by `TranslationServiceProvider`);
- `instance` with a class-string could not `make()` anything that depends on a deferred service.

`ApplicationBuilder::withBindings()`, `withSingletons()` and `withScopedSingletons()` solve the same problem the same way: they wrap plain `$app->bind/singleton/scoped` loops in `$app->registered(...)`. The manifest does exactly that.

A declaration applied in that `registered()` callback is visible to:

- every provider's `boot()`, whatever the provider order,
- the `register()` of every declared provider (`providers` registers from `boot()`),
- everything runtime: `make()`, `app()`, method injection.

It is **not** visible to:

- any eager provider's `register()` (they all ran first) — the same trade-off as `ApplicationBuilder`. A later `bind()` drops a stale instance (`dropStaleInstances`), but objects already handed out keep it; an `extend()` on an already-shared instance is applied immediately and fires `rebound`,
- state consumed during bootstrap, before any provider runs: the `.env` location, the config directory and cache paths, config values computed from path helpers, and the bootstrapper callbacks (`beforeBootstrapping`, `afterBootstrapping`, `afterLoadingEnvironment`) — see §2.6.

`registered()` callbacks fire once, from `registerConfiguredProviders()`. A provider registered after that (a manual `$app->register(LaravelDeclarationProvider::class)` at runtime) never applies the block — the same as `ApplicationBuilder`.

### 1.2 Properties

All state is protected and set through the methods of §1.3 — there is nothing to declare directly.

| Property | Set by |
|---|---|
| `$basePath`, `$appPath`, `$bootstrapPath`, `$configPath`, `$databasePath`, `$langPath`, `$publicPath`, `$storagePath` | `setBasePath()` / `use*Path()` |
| `$environmentPath`, `$environmentFile` | `useEnvironmentPath()` / `loadEnvironmentFrom()` |
| `$registeredCallbacks`, `$bootingCallbacks`, `$bootedCallbacks`, `$terminatingCallbacks` | `registered()` / `booting()` / `booted()` / `terminating()` |

### 1.3 Public methods

Signatures are the native ones; most parameters are untyped, and their `@param` types are given in the Effect column.

**Container (inherited from `Container` — the primary declaration targets)**

| Method | Signature | Effect |
|---|---|---|
| `bind` | `($abstract, $concrete = null, $shared = false): void` | `$concrete = null` is a **self-binding** (concrete = abstract); a `Closure` `$abstract` binds each of its return types (`bindBasedOnClosureReturnTypes`); drops any stale instance; fires `rebound` if already resolved |
| `bindIf` | `($abstract, $concrete = null, $shared = false): void` | only `bind`s when `! $this->bound($abstract)` — `Application::bound()` also counts deferred services |
| `singleton` | `($abstract, $concrete = null): void` | `bind(..., shared: true)` |
| `singletonIf` | `($abstract, $concrete = null): void` | only when unbound |
| `scoped` | `($abstract, $concrete = null): void` | `singleton()` whose instance `forgetScopedInstances()` drops — per queue job (`QueueServiceProvider`) and per Octane request |
| `scopedIf` | `($abstract, $concrete = null): void` | only when unbound |
| `instance` | `($abstract, $instance): mixed` | binds an existing value, removes `$abstract` as an alias; fires `rebound` if previously bound |
| `alias` | `($abstract, $alias): void` | `$abstract` **first**, `$alias` second; `$app->make($alias)` resolves through it; `LogicException` when `$alias === $abstract` |
| `extend` | `($abstract, Closure $closure): void` | the Closure is called `($instance, $app)`; if a shared instance already exists it is applied and `rebound` immediately |

`$concrete` is `Closure|string|null`. A string is `make()`d (`getClosure()` → `resolve()`), so it is a class-string or another bound abstract — not `Class@method` (`build()` reflects it as a class name and fails). A `Closure` is Laravel's factory: `build()` calls it **positionally** as `$concrete($app, $parameters)`, with no container injection. YAML can express neither, which is why the manifest's reference system (§2.2) reads a Closure from a `.php` file.

**Paths**

| Method | Signature | Effect |
|---|---|---|
| `setBasePath` | `($basePath): $this` | root of every other path; re-runs `bindPathsInContainer()`, which also resets the bootstrap and lang paths |
| `useAppPath` / `useBootstrapPath` / `useConfigPath` / `useDatabasePath` / `useLangPath` / `usePublicPath` / `useStoragePath` | `($path): $this` | stores `$path` **verbatim** (no `base_path()`) **and** rebinds the matching container instance: `path`, `path.bootstrap`, `path.config`, `path.database`, `path.lang`, `path.public`, `path.storage` |
| `useEnvironmentPath` / `loadEnvironmentFrom` | `($path): $this` / `($file): $this` | consumed by `LoadEnvironmentVariables` — too late to take effect (§2.6) |
| `path` / `basePath` / `bootstrapPath` / `configPath` / `databasePath` / `langPath` / `publicPath` / `storagePath` / `resourcePath` / `viewPath` | `($path = ''): string` | getters — `app_path()`, `base_path()`, ... helpers call these |

**Lifecycle hooks**

| Method | Signature | Invocation |
|---|---|---|
| `registered` | `($callback): void` — `callable` | `fireAppCallbacks`: `$callback($this)` — end of `registerConfiguredProviders()`, after every eager `register()` and `addDeferredServices()`; a callback added while they fire runs in the same pass |
| `booting` | `($callback): void` — `callable` | `$callback($this)` — first thing in `boot()` |
| `booted` | `($callback): void` — `callable` | `$callback($this)` — last thing in `boot()`; **fires immediately** if the app already booted |
| `terminating` | `($callback): $this` — `callable\|string` | `$this->call($callback)` in `terminate()` — a native container call with **no named arguments** (pure DI); an invokable class-string or `Class@method` passes through untouched |

**Locale**

| Method | Signature | Effect |
|---|---|---|
| `setLocale` | `($locale): void` | `config->set('app.locale')` **and** `$this['translator']->setLocale()` **and** dispatches `LocaleUpdated($locale, $previous)` |
| `setFallbackLocale` | `($fallbackLocale): void` | `config->set('app.fallback_locale')` **and** `$this['translator']->setFallback()`; no event |
| `getLocale` / `currentLocale` | `(): string` | `config('app.locale')` |
| `getFallbackLocale` | `(): string` | `config('app.fallback_locale')` |
| `isLocale` | `($locale): bool` | loose (`==`) comparison with `getLocale()` |

**Runtime (not declaration targets)**

`version`, `environment`, `isLocal`, `isProduction`, `detectEnvironment`, `runningInConsole`, `runningConsoleCommand`, `runningUnitTests`, `hasDebugModeEnabled`, `isBooted`, `hasBeenBootstrapped`, `bootstrapWith`, `make`, `bound`, `resolved`, `boot`, `register`, `registerConfiguredProviders`, `getProvider(s)`, `resolveProvider`, deferred-service methods (`addDeferredServices`, `isDeferredService`, ...), `handle`, `handleRequest`, `handleCommand`, `shouldSkipMiddleware`, `abort`, `terminate`, `isDownForMaintenance`, `maintenanceMode`, `flush`, `getNamespace`, `provideFacades`, `registerCoreContainerAliases`, `configurationIsCached`, `routesAreCached`, `eventsAreCached`, `getCached*Path`, `environmentPath`, `environmentFile`.

---

## 2. Manifest schema proposal

### 2.1 Design rule

> **Every key in the `app:` block is an `Application` (or inherited `Container`) method name. Every value is the argument(s) of that method's signature.** A map gives one call per entry (`abstract => concrete/alias/value`); a list gives one call per item (a self-binding for the binding keys, one callback for the hook keys); a reference value is resolved per §2.2.

This is the same rule as declarative-routing (§2.1): the YAML can never drift from the underlying API, because a key *is* the method it calls.

### 2.2 PHP references

YAML has no `::class` constant, `Closure` or `new`. A callable or object is written as a **reference string**. Hooks and extenders resolve it through `Container::call()` (`BoundMethod::call()`, as in declarative-requests §2.2); a `.php` reference is `require`d:

| YAML form | Resolved as |
|---|---|
| `App\Hooks\FlushMetrics` | invokable class: `make()` + `__invoke()` (`BoundMethod` defaults to `__invoke` when `method_exists($callback, '__invoke')`) |
| `App\Hooks\Metrics@handle` | `make()` + instance method (`BoundMethod::isCallableWithAtSign`) |
| `App\Hooks\Metrics::flush` | static method (native PHP callable string) |
| `App\Hooks\flush_metrics` | namespaced function. PSR-4 autoloads classes only — load the file through Composer `autoload.files` |
| `hooks/flush_metrics.php` | **a PHP file**: `require`d once, its **return value is used** — the natural home for Closures |

There is no array form (`[Class, 'method']` is called statically by `Container::call`, as in declarative-requests §2.2). Write references plain or single-quoted: double quotes make `\` a YAML escape.

**The `.php` form.** A reference ending in `.php` is required exactly once (memoized by absolute path) and its return value is used wherever a value is expected — a `Closure` for `extend` and lifecycle hooks, a `Closure` concrete for `bind`/`singleton`/`scoped`, an instance or scalar for `instance`. Everywhere except `instance`, a file that returns anything but a `Closure` throws `LogicException` at registration. A relative path is resolved through `basePath()`; one starting with `/` or `\` is used as-is — the rule `Application::normalizeCachePath()` applies to `APP_*_CACHE` paths. The file is plain PHP with no package dependency:

```php
// app/extensions/store-cache.php — the file IS the extender
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Application;

return static function (Repository $instance, Application $app): Repository {
    // $instance and $app are matched by NAME; anything else is injected by type-hint

    return $instance;
};
```

**Parameters.** Hooks and extenders are invoked through `Container::call()` with named arguments. `BoundMethod::addDependencyForCallParameter()` matches by **name** first, then by class name, then `make()`s the type-hint — so the parameters **must** be named `$app` and `$instance`. `Repository $store` in the extender above would be a fresh `make(Repository::class)`, not the instance being extended.

- every lifecycle reference (`registered`, `booting`, `booted`) receives `['app' => $app]` plus DI;
- every `extend` reference receives `['instance' => $instance, 'app' => $app]` plus DI;
- a `terminating` reference receives **no named arguments** — Laravel's `terminate()` calls `$this->call($callback)` directly, so everything is DI;
- a `bind`/`singleton`/`scoped` Closure is **not** a container call: it is Laravel's factory, called positionally as `($app, $parameters)` (§1.3). Only a `.php` file can supply it; function and method references do not apply to concretes.

**Value or reference.** For the six binding keys a string ending in `.php` is required once; every other string passes through as the `$concrete` string Laravel expects, and `~` (null) is `$concrete = null`. For `terminating` a `.php` string is required once; every other string passes through as the `callable|string` Laravel's `terminate()` calls. For `registered`/`booting`/`booted`/`extend` the string is wrapped (§2.5) and called. For `instance`, a class-string or interface-string is `make()`d eagerly, a `.php` reference is required, and every other value is bound exactly as YAML decoded it.

### 2.3 Full example

```yaml
config:                                         # root-level blocks: their own documents
  app:
    name: Tenant Console

providers:
  - class: App\Providers\AppServiceProvider

app:                                            # ——— Application surface (this document) ———
  bind:                                         # -> bind($abstract, $concrete)
    App\Contracts\Pdf: App\Services\DomPdf
    App\Contracts\Slugger: app/binders/slugger.php   # .php file: the returned Closure is $concrete, called ($app, $parameters)
  bindIf:                                       # -> bindIf(): skipped when already bound, deferred services included
    App\Contracts\Cache: App\Services\RedisCache
  singleton:                                    # -> singleton($abstract, $concrete)
    App\Services\TenantContext: App\Services\TenantContext
    App\Services\ReportBuilder: ~               # null $concrete -> self-binding singleton('App\Services\ReportBuilder')
  singletonIf:
    - App\Services\SlowWarmup                   # list form: every item is a self-binding (ApplicationBuilder::withSingletons)
  scoped:                                       # -> scoped(): shared until forgetScopedInstances() (queue job / Octane request)
    App\Services\RequestLog: ~
  scopedIf:
    App\Services\BudgetGuard: ~

  instance:                                     # -> instance($abstract, $instance)
    app.signature: "1.0"                        # literal scalar, bound as-is
    App\Contracts\Clock: App\Services\Clock     # class-string -> make()d eagerly
    app.rate_limiter: app/instances/limiter.php # .php file: the returned value is bound as-is

  alias:                                        # -> alias($abstract, $alias)  — abstract FIRST
    App\Services\TenantContext: context         # app('context') resolves the singleton

  extend:                                       # -> extend($abstract, Closure)  (.php file IS the Closure)
    cache.store: app/extensions/store-cache.php

  useAppPath: src                               # -> useAppPath(base_path('src')): rebinds the `path` instance
  useLangPath: resources/lang                   # -> useLangPath(base_path('resources/lang')): applied before setLocale

  setLocale: fr                                 # -> setLocale('fr')
  setFallbackLocale: en                         # -> setFallbackLocale('en')

  registered:                                   # one registered() call per item; fires right after the block is applied
    - app/hooks/registered.php
  booting:                                      # fires first in boot()
    - App\Hooks\WarmConnections
  booted:                                       # fires last in boot()
    - app/hooks/booted.php
  terminating:                                  # fires in terminate(), pure DI
    - App\Hooks\FlushMetrics
```

A single YAML node is either a map or a list, never both: a binding key takes a map (`abstract: concrete` or `abstract: ~`) or a list of self-bindings.

### 2.4 Key → method → signature map

| YAML key (under `app:`) | `Application`/`Container` method | Value shape (YAML) | Dispatch (§2.5) |
|---|---|---|---|
| `bind` | `bind` | `map<abstract, class-string \| abstract \| .php file \| ~>` \| `list<abstract \| .php file>` | string key → `$app->bind($abstract, $concrete)`; int key → `$app->bind($concrete)` (mirrors `ApplicationBuilder::withSingletons`) |
| `bindIf` | `bindIf` | same, but list items are abstracts only — no `.php` | same shape |
| `singleton` | `singleton` | same | same shape |
| `singletonIf` | `singletonIf` | same, but list items are abstracts only — no `.php` | same shape |
| `scoped` | `scoped` | same | same shape |
| `scopedIf` | `scopedIf` | same, but list items are abstracts only — no `.php` | same shape |
| `instance` | `instance` | `map<abstract, value \| class-string \| .php file>` | value rule (§2.2) → `$app->instance($abstract, $value)` |
| `alias` | `alias` | `map<abstract, alias>` | `$app->alias($abstract, $alias)` |
| `extend` | `extend` | `map<abstract, reference>` | wrapped in `Closure ($instance, $app)` → `$app->call(...)` |
| `useAppPath` | `useAppPath` | `string` | `$app->useAppPath($this->absolute($path))` |
| `useDatabasePath` | `useDatabasePath` | `string` | `$app->useDatabasePath($this->absolute($path))` |
| `useLangPath` | `useLangPath` | `string` | `$app->useLangPath($this->absolute($path))` |
| `usePublicPath` | `usePublicPath` | `string` | `$app->usePublicPath($this->absolute($path))` |
| `useStoragePath` | `useStoragePath` | `string` | `$app->useStoragePath($this->absolute($path))` |
| `setLocale` | `setLocale` | `string` | `$app->setLocale($locale)` |
| `setFallbackLocale` | `setFallbackLocale` | `string` | `$app->setFallbackLocale($fallbackLocale)` |
| `registered` | `registered` | `list<reference>` | wrapped in `Closure ($app)` → `$app->call(...)` |
| `booting` | `booting` | `list<reference>` | wrapped in `Closure ($app)` → `$app->call(...)` |
| `booted` | `booted` | `list<reference>` | wrapped in `Closure ($app)` → `$app->call(...)` |
| `terminating` | `terminating` | `list<reference>` | `.php` resolved eagerly; otherwise passed through — Laravel's own `Container::call` |

A list-form `.php` item returns a `Closure` that becomes `$abstract` itself: `bind(Closure)` binds it under each of its declared return types (`bindBasedOnClosureReturnTypes`), which is Laravel's own semantics. `bindIf`/`singletonIf`/`scopedIf` reject it at hydration: their docblocks declare `\Closure|string $abstract`, but each calls `bound($abstract)`, which uses it as an array offset and throws `TypeError` for a Closure. Declare a Closure there in the map form (`Abstract: file.php`).

`absolute()` is the only transformation the provider applies to a value: `use*Path()` stores its argument verbatim, and YAML cannot call `base_path()`, so a relative path is resolved against `basePath()` (§2.2's rule). An absolute path passes through unchanged.

Fixed order, mirroring Laravel's semantics: `bind` → `bindIf` → `singleton` → `singletonIf` → `scoped` → `scopedIf` → `instance` → `alias` → `extend` → paths → `setLocale` → `setFallbackLocale` → `registered` → `booting` → `booted` → `terminating`. Paths precede the locale because resolving `translator` builds its `FileLoader` from `path.lang`. Each call overwrites; when two groups name the same abstract, the later group wins (Laravel's own behavior for repeated `bind`/`instance` calls) — except the `*If` groups, which never overwrite.

Unknown keys must be a validation error (fail-fast): a typo such as `singelton` otherwise hydrates silently. **`DataModel` does not do this**, and no model in the package checks it yet — routes and requests ignore unknown keys (declarative-requests §2.4), while declarative-routing §2.4 asks for the check. `App` must diff its input keys against the properties decorated with the `Key` attribute and throw, and reject a `.php` list item under the `*If` keys (above).

### 2.5 Registration algorithm (for the provider)

A new `DataModel`, `src/App.php`, hydrated from the `app:` key by a new `Manifest` property. `Manifest` owns the root-level blocks; `App` owns only this surface:

```php
// src/Manifest.php
public const string app = 'app';

/** The `Application` surface; null when the manifest has no (or an empty) `app:` block */
#[Describe([Describe::nullable => true])]
public ?App $app;
```

`nullable`, not `default`: `DataModel` calls a callable default as `(null, $context, $Attribute, $Property)`, and `App::from()`'s second parameter is `$instance`, so `Describe::default => [App::class, 'from']` would hydrate into the context array. An empty `app:` key parses to `null`, which `DataModel` treats as absent, so it hydrates to `null` too.

Its properties are plain arrays and strings because values are user data:

```php
// src/App.php
public const string bind = 'bind';

/** @var array<int|string, string|null> abstract => concrete (class-string, abstract, `.php` file, or null), or a list of abstracts */
#[Describe([Describe::default => []])]
public array $bind;

// bindIf, singleton, singletonIf, scoped, scopedIf: same shape, Describe::default => []
// instance: array<string, mixed>; alias, extend: array<string, string>; all Describe::default => []
// useAppPath, useDatabasePath, useLangPath, usePublicPath, useStoragePath: #[Describe([Describe::nullable => true])] public ?string $useAppPath;
// setLocale, setFallbackLocale: ?string, nullable
// registered, booting, booted, terminating: /** @var list<string> */ #[Describe([Describe::default => []])] public array $registered; // ...
```

In `LaravelDeclarationProvider::register()`, after `registerConfig()` — one callback, exactly as `ApplicationBuilder::withBindings()` does it (§1.1):

```php
$this->app->registered(fn (Application $app) => $this->registerApplication($Manifest->app ?? App::from(), $app));
```

```php
private function registerApplication(App $App, Application $app): void
{
    foreach (App::selected(Binding::class) as $method) {
        foreach ($App->{$method} as $abstract => $concrete) {
            is_string($abstract)
                ? $app->{$method}($abstract, $this->concrete($concrete))
                : $app->{$method}($this->concrete($concrete) ?? throw new LogicException("The `app.$method` list declares a null item; every list item is an abstract."));
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

    foreach (App::selected(Path::class) as $method) {
        if (($path = $App->{$method}) !== null) {
            $app->{$method}($this->absolute($path));
        }
    }

    if ($App->setLocale !== null) {
        $app->setLocale($App->setLocale);
    }

    if ($App->setFallbackLocale !== null) {
        $app->setFallbackLocale($App->setFallbackLocale);
    }

    foreach ($App->registered as $reference)  { $app->registered($this->wrapCallback($reference)); }
    foreach ($App->booting as $reference)     { $app->booting($this->wrapCallback($reference)); }
    foreach ($App->booted as $reference)      { $app->booted($this->wrapCallback($reference)); }
    foreach ($App->terminating as $reference) { $app->terminating($this->reference($reference)); }
}

/** null (a self-binding) passes through; anything else is a reference. */
private function concrete(?string $reference): Closure|string|null
{
    return $reference === null ? null : $this->reference($reference);
}

/** A `.php` reference is required once, memoized, and must return a Closure; any other string passes through. */
private function reference(string $reference): Closure|string
{
    if (! str_ends_with($reference, '.php')) {
        return $reference;
    }

    $value = $this->fileValue($reference);

    if (! $value instanceof Closure) {
        throw new LogicException("The `app` reference [$reference] must return a Closure, ".get_debug_type($value).' returned.');
    }

    return $value;
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
    $value = $this->reference($reference);

    return static fn (mixed $instance, Application $app): mixed => $app->call($value, ['instance' => $instance, 'app' => $app]);
}

private function wrapCallback(string $reference): Closure
{
    $value = $this->reference($reference);

    return static fn (Application $app): mixed => $app->call($value, ['app' => $app]);
}

/** `Application::normalizeCachePath()`'s rule: `/` or `\` prefix is absolute, anything else is under basePath(). */
private function absolute(string $path): string
{
    return Str::startsWith($path, ['/', '\\']) ? $path : $this->app->basePath($path);
}

private function fileValue(string $file): mixed
{
    static $loaded = [];

    $path = $this->absolute($file);

    return $loaded[$path] ??= require $path;
}
```

`is_string($abstract)` distinguishes the map form (string key = `$abstract` present) from the list form (int key = self-binding) — the exact ternary `ApplicationBuilder::withSingletons()` uses. A list item is the `$abstract` itself, so a `~` item throws: Laravel would otherwise raise a `TypeError` naming `$concrete`. The wrappers capture only the reference string or the `.php` file's already-required return value; nothing is resolved from the container until Laravel invokes them.

`registered()`, the `use*Path()` methods and `setFallbackLocale()` are not on `Illuminate\Contracts\Foundation\Application`, the type of `ServiceProvider::$app`; the callback's `Illuminate\Foundation\Application $app` parameter carries the concrete type, and the `registered()` call itself needs the same narrowing for phpstan.

### 2.6 Notes / non-goals

- **Bootstrap-consumed keys, by design (non-goals).** `useEnvironmentPath`/`loadEnvironmentFrom` are consumed by `LoadEnvironmentVariables` before any provider. `useConfigPath` and `useBootstrapPath` are consumed by `LoadConfiguration`, `PackageManifest` and `RegisterProviders` (`config_path()`, `getCachedConfigPath()`, `getCachedPackagesPath()`, `getBootstrapProvidersPath()`); declaring them later would split reads from writes — `config:cache` would write a file the next bootstrap never reads. `beforeBootstrapping`/`afterBootstrapping`/`afterLoadingEnvironment` callbacks fire during bootstrap. `setBasePath` is fixed at construction (`Application::configure(basePath:)`) and resets the bootstrap and lang paths.
- **Paths vs. config values.** Every declared `use*Path` rebinds its `path.*` instance and moves its helper (`storage_path()`, ...) from then on. Values that `./config/*.php` computed from those helpers during `LoadConfiguration` — `logging.channels.single.path`, `view.compiled`, `cache.stores.file.path`, `session.files`, `filesystems.disks.*.root`, `database.connections.sqlite.database` — keep the old path. Declare them in `config:` as well when they must follow.
- **`app.env`.** `detectEnvironment()` runs during bootstrap and sets `$this['env']`, which `environment()`/`isLocal()`/`isProduction()` read. Neither `app:` nor `config: {app: {env: ...}}` changes it (declarative-configuration §2.6); `APP_ENV` in `.env` is the declaration point.
- **`terminating` is DI-only.** `Application::terminate()` calls `$this->call($callback)` with no named arguments, so a terminating reference receives its dependencies by type-hint alone. `Kernel::terminate()` runs it after the response is sent — never use it to alter it.
- **`booted` fires immediately when already booted** (Laravel's own behavior). Within a normal request lifecycle the app is not yet booted when the `registered()` callback runs, so declared hooks fire in the same `boot()`.
- **`instance` class-string ambiguity.** A literal scalar that happens to equal a class name (`instance: {tag: App\Services\Clock}`) is `make()`d. Bind such literals through a `.php` file returning the string if the distinction matters.
- **`.php` values are process-wide.** `fileValue()` memoizes per PHP process, so two applications in one process (tests, `refreshApplication()`) share the same returned Closure or object. Return a Closure, not a stateful object, when that matters.
- **No Closure literals in YAML — by design.** The `.php` file form is the only way to declare a Closure (concrete, extender, hook). It keeps the manifest decodable by plain YAML parsers and keeps Closures in real, reviewable PHP.
- **`alias` direction.** The map is `{abstract: alias}` — signature order (`alias($abstract, $alias)`), same rule as `bind`. It is deliberately the inverse of `config/app.php`'s facade `aliases` map (`alias => class`), which is `AliasLoader`, a different API.
- **`config.app.locale` vs `setLocale`.** `TranslationServiceProvider` is deferred and builds its `Translator` from `getLocale()`/`getFallbackLocale()` on first resolve, so `config.app.locale` alone already reaches the translator unless something resolved it earlier. `setLocale` additionally resolves `translator` immediately and dispatches `LocaleUpdated` — during `registered()`, before the application's `EventServiceProvider` attaches its listeners in `booting()`, so those listeners do not observe it.
- **`config:cache` / caching.** Nothing here is cached by `config:cache` beyond what declarative-configuration already covers — `setLocale`/`setFallbackLocale` write `app.locale`/`app.fallback_locale`, so those are in the cached file too. Bindings and hooks re-apply every boot, with no snapshot to invalidate.
- **Undeclared container API.** `tag`, contextual binding (`when()->needs()->give()`, `addContextualBinding`), `bindMethod`, `rebinding`/`refresh`, and `resolving`/`beforeResolving`/`afterResolving` are public `Container` methods with no key yet. Each needs its own value shape (`tag($abstracts, $tags)` puts the abstracts first; `when()` is a builder), so they are **non-goals** for this block.
- **Deferred services.** `addDeferredServices()` is a **non-goal** for now: package discovery covers deferred providers, and the deferred-service lifecycle interacts with `ProviderRepository` caching.
- **Not a validation layer.** Values pass through as YAML decoded them; nothing re-evaluates strings. A binding that names an unloaded class fails at resolve time with Laravel's own `BindingResolutionException` / `ReflectionException`, at first `make()`.

---

## 3. Implementation plan

1. **`src/App.php`** + **`src/Manifest.php`** — create `App` (`final readonly`, `use DataModel`) with the `DataModel` properties of §2.5 (`bind`, `bindIf`, `singleton`, `singletonIf`, `scoped`, `scopedIf`, `instance`, `alias`, `extend`, `useAppPath`, `useDatabasePath`, `useLangPath`, `usePublicPath`, `useStoragePath`, `setLocale`, `setFallbackLocale`, `registered`, `booting`, `booted`, `terminating`), each with a `public const string <name> = '<name>';` key, matching the existing `Provider`/`Request` style; add the nullable `Manifest::$app` of §2.5.
2. **`../src/LaravelDeclarationProvider.php`** — queue `registerApplication()` through `$this->app->registered(...)` right after `registerConfig($Manifest)` in `register()`, and add the helpers (`concrete`, `reference`, `instantiate`, `wrapExtender`, `wrapCallback`, `absolute`, `fileValue`) of §2.5. The provider already imports `Illuminate\Support\Facades\App`, which would shadow the new same-namespace `App` class: replace `App::isProduction()` with `$this->app->environment('production')` and drop the facade import. `isProduction()` is not on `Illuminate\Contracts\Foundation\Application` (the type of `ServiceProvider::$app`), so phpstan rejects `$this->app->isProduction()`.
3. **Validation** — add the first unknown-key check (§2.4): `App` diffs its input keys against its `Key`-decorated properties and throws, so a typo fails fast instead of hydrating silently. The same hook rejects a `.php` list item under `bindIf`/`singletonIf`/`scopedIf`.
4. **Fixtures** — `tests/Fixtures/App/Application/`: `DomPdf`, `RedisCache`, `TenantContext`, `ReportBuilder`, `Clock`, a `Binders/slugger.php` returning a Closure, `Extensions/store-cache.php`, `Instances/limiter.php`, `Hooks/registered.php`, `Hooks/booted.php`, `Hooks/FlushMetrics`, `Hooks/WarmConnections`; fill the (currently empty) `app:` block of `tests/Fixtures/manifest/app.yml` with §2.3. Testbench's `basePath()` is its own skeleton, not the repository, so fixture `.php` references and paths must be absolute or relative to that skeleton.
5. **Tests** — `tests/Feature/ApplicationRegistrationTest.php`:
   - `bind` resolves the interface to the impl with distinct instances per resolve; `singleton` shares one; `~` and list items self-bind; `scoped` shares until `forgetScopedInstances`;
   - `bindIf`/`singletonIf`/`scopedIf` are skipped when a binding already exists, **including a deferred service** (e.g. `translator`);
   - `instance` binds the literal scalar, eagerly `make()`s the class-string, and binds the `.php` file's return value;
   - `alias` resolves through `app('context')`;
   - `extend` transforms `cache.store` via the `.php` extender, receiving the extended instance as `$instance`;
   - `useAppPath` rebinds `app_path()` and the `path` container instance, relative values under `basePath()`, absolute values verbatim;
   - `setLocale` updates config and the translator and dispatches `LocaleUpdated`; `setFallbackLocale` updates config and the translator's fallback;
   - lifecycle hooks fire in order (`registered` → `booting` → `booted`) and `terminating` fires on kernel terminate, with DI injection;
   - a `.php` reference is required exactly once across two boots;
   - a `.php` reference that does not return a `Closure` throws, and so does a `~` list item;
   - a `.php` list item under `bindIf`/`singletonIf`/`scopedIf` throws at hydration;
   - an unknown `app:` key throws.
6. **`composer check`** — lint, rector, phpstan, coverage, bc-check must pass. The new `App` class and `Manifest::$app` are public API; `bc-check` compares them against the latest SemVer tag, and skips while none exists.

---

### Sources

1. `Application` binds, hooks, locale, paths, `bound()`, `normalizeCachePath()` — [vendor/laravel/framework/src/Illuminate/Foundation/Application.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Foundation/Application.php)
2. Container `bind`/`bindIf`/`singleton`/`singletonIf`/`scoped`/`scopedIf`/`instance`/`alias`/`extend`/`build`/`forgetScopedInstances` — [vendor/laravel/framework/src/Illuminate/Container/Container.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Container/Container.php)
3. Reference invocation semantics (`__invoke` default, `Class@method`, name-first parameter matching) — [vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Container/BoundMethod.php)
4. `addDeferredServices()` after eager `register()` — [vendor/laravel/framework/src/Illuminate/Foundation/ProviderRepository.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Foundation/ProviderRepository.php)
5. Laravel's own declarative precedent (`withBindings`, `withSingletons`, `withScopedSingletons` inside `registered()`) — [vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php)
6. Deferred translator built from `getLocale()` — [vendor/laravel/framework/src/Illuminate/Translation/TranslationServiceProvider.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Translation/TranslationServiceProvider.php)
7. Request lifecycle and `terminate()` — [laravel.com/docs/lifecycle](https://laravel.com/docs/lifecycle), [laravel.com/docs/container](https://laravel.com/docs/container)
8. `Describe::nullable` / `default` semantics — [docs/repos/zero-to-prod/data-model/README.md](repos/zero-to-prod/data-model/README.md)
