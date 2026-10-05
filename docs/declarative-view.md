# Declarative View — `Illuminate\View\Factory` Lookup, Shared Data, Composers & Manifest Schema

> Manifest forms in this document are the pre-engine block shapes; see docs/general-purpose-migration-plan.md §2.1 and README for the current forms.

Source of truth: `vendor/laravel/framework/src/Illuminate/View/Factory.php` (`laravel/framework` v13.33.0), with `Illuminate/View/Concerns/ManagesEvents.php`, `Illuminate/View/FileViewFinder.php`, `Illuminate/View/View.php`, `Illuminate/View/ViewName.php`, `Illuminate/View/ViewServiceProvider.php`, `Illuminate/Events/Dispatcher.php`, `Illuminate/Support/ServiceProvider.php`, `Illuminate/Routing/ViewController.php`, `Illuminate/Foundation/Console/ViewCacheCommand.php` and `Illuminate/Foundation/Console/ServeCommand.php`.

Goal: a `view:` block in `manifest/app.yml` whose **keys map 1:1 onto `Factory` method names** and whose **values map 1:1 onto those methods' signatures**. This is Phase 2 of [declarative-request-to-view-roadmap.md](declarative-request-to-view-roadmap.md): stage 11 (view lookup: `addLocation`, `prependLocation`, `addNamespace`, `prependNamespace`, `replaceNamespace`, `addExtension`) and stage 10 (shared and composed view data: `share`, `composer`, `creator`). The provider applies the block with nine pass-through calls (§2.5), queued the way Laravel's own `ServiceProvider::loadViewsFrom()` queues its namespace: `callAfterResolving('view')` (§1.1). Every string reference is forwarded untouched; Laravel's `ManagesEvents` resolves it.

---

## 1. Public API of `Factory::class`

### 1.1 Lifecycle position (when the declaration runs, when it is read)

```php
$app->boot();                          // each provider's boot(), in registration order:
                                       //   LaravelDeclarationProvider::boot()
                                       //       registerRouter()
                                       //       callAfterResolving('view', registerView)   <- `view` block QUEUED here; nothing is built
                                       //       registerProviders(), registerRoutes()
                                       //   AppServiceProvider::boot()   where Laravel's docs call View::share() / View::composer();
                                       //                                the facade resolves `view`, so registerView() runs first

// first make('view') of the application: a render, a View:: facade call, view:cache
//   ViewServiceProvider's `view` singleton (ViewServiceProvider.php:39):
//       new FileViewFinder($files, config('view.paths'))   `view.finder` is a bind(): read NOW (line 84)
//       new Factory(...)                                   share('__env', $factory) (Factory.php:120)
//       $factory->share('app', $app)                       (ViewServiceProvider.php:54)
//   Container::fireAfterResolvingCallbacks('view')
//       registerView()                                     <- `view` block APPLIED here, once per application

// per render
//   Factory::make($view, $data)      finder->find(): locations, namespaces, extensions    callCreator()   <- creator
//   View::render()                   renderContents(): callComposer()                                     <- composer
//   View::gatherData()               array_merge(getShared(), $data)                                      <- share
```

Consequences, each verified against v13.33.0 with Testbench:

1. **Lazy, like `loadViewsFrom()`.** No framework provider resolves `view` during boot: `resolved('view')` is `false` after `boot()`, and still `false` after a request that returns JSON. That holds in this package's `TestCase` too. Calling `make('view')` eagerly in `boot()` would build the `Factory` on every request. It would also read `config('view.paths')` at that moment, so a `config(['view.paths' => ...])` in a provider that boots later would be ignored. `ServiceProvider::callAfterResolving()` (line 310) registers an `afterResolving` callback, and runs it immediately if `view` was resolved before this provider booted.
2. **Manifest first, hand-written second.** The callback runs inside the first resolution, before the resolving call returns. So a `View::share('brand', ...)` or `View::composer(...)` in `AppServiceProvider::boot()` or in a declared provider lands after the manifest's: its `share` wins (last write), and its composer runs after the manifest's composers in the same exact/pattern group (§1.4.3). Queuing before `registerProviders()` keeps that order for declared providers too.
3. **Once per application.** `Container::resolve()` returns an existing shared instance before it fires resolving callbacks, so a later `make('view')` does not apply the block again.
4. **`view:cache` compiles declared directories.** `ViewCacheCommand::paths()` (line 100) reads `getFinder()->getPaths()` and `getHints()` from the resolved `view`, so the block has already run.
5. **Callback order with packages.** `loadViewsFrom()` is also a `callAfterResolving('view')` callback (ServiceProvider.php:212). Callbacks run in registration order, so a package that boots after this provider adds its hints after the manifest's. This matters only for `replaceNamespace` (§2.6).

### 1.2 Properties

| Property | Type | Set by | Read by |
|---|---|---|---|
| `Factory::$shared` (protected) | `array<string, mixed>` | `share()`; the constructor (`__env`) and `ViewServiceProvider` (`app`) | `View::gatherData()`, `shared()`, `getShared()` |
| `Factory::$extensions` (protected) | `array<string, string>` extension → engine | `addExtension()` | `getEngineFromPath()` |
| `FileViewFinder::$paths` (protected) | `list<string>` | constructor (`config('view.paths')`), `addLocation()`, `prependLocation()` | `find()` → `findInPaths()` |
| `FileViewFinder::$hints` (protected) | `array<string, list<string>>` | `addNamespace()`, `prependNamespace()`, `replaceNamespace()` | `findNamespacedView()` |
| `FileViewFinder::$extensions` (protected) | `list<string>`, default `['blade.php', 'php', 'css', 'html']` | `addExtension()` (through the `Factory`) | `getPossibleViewFiles()` |
| `Dispatcher::$listeners` / `$wildcards` (protected) | `composing: {view}` / `creating: {view}` listeners | `composer()` / `creator()` | `callComposer()` / `callCreator()` |

### 1.3 Public methods

**Declaration targets**

| Method | Signature | Effect |
|---|---|---|
| `addLocation` | `($location): void`. `@param string $location` | finder: `$paths[] = realpath($location) ?: $location` |
| `prependLocation` | `($location): void`. `@param string $location` | finder: `array_unshift($paths, realpath($location) ?: $location)` |
| `addNamespace` | `($namespace, $hints): $this`. `@param string $namespace, string\|array $hints` | finder: appends `(array) $hints` to the namespace's hints |
| `prependNamespace` | `($namespace, $hints): $this`. Same types | finder: prepends `(array) $hints` |
| `replaceNamespace` | `($namespace, $hints): $this`. Same types | finder: `$hints[$namespace] = (array) $hints` |
| `addExtension` | `($extension, $engine, $resolver = null): void`. `@param string $extension, string $engine, ?Closure $resolver` | finder tries `.$extension` first; `$extensions = [$extension => $engine] + $extensions`; a `$resolver` registers a new engine |
| `share` | `($key, $value = null): mixed`. `@param array\|string $key` | `$shared[$key] = $value` per pair. An array `$key` is the map |
| `composer` | `($views, $callback): array`. `@param array\|string $views, Closure\|string $callback` | `addViewEvent($view, $callback, 'composing: ')` per view |
| `creator` | `($views, $callback): array`. Same types | `addViewEvent($view, $callback, 'creating: ')` per view |

**Not declaration targets**

| Method | Why no key |
|---|---|
| `composers` | `(array $composers)`: `foreach ($composers as $callback => $views) $this->composer($views, $callback)` (ManagesEvents.php:35). The `composer` map *is* that loop (§2.1) |
| `addExtension()`'s `$resolver` | `?Closure`, and YAML has no Closure (§2.6) |
| `make` / `file` / `first` / `exists` / `renderWhen` / `renderUnless` / `renderEach` | runtime rendering — dispatched per request by `DeclaredView`'s `setDefaults.factory` ([declarative-view-factory.md](declarative-view-factory.md) §2) |
| `shared` / `getShared` / `getExtensions` / `getFinder` / `getEngineResolver` / `getDispatcher` / `getContainer` | runtime read-back |
| `setFinder` / `setDispatcher` / `setContainer` | take objects |
| `callComposer` / `callCreator` | runtime; `View::renderContents()` and `make()` call them |
| `flushFinderCache` / `flushState` | the `view:` epilogue booleans ([declarative-view-factory.md](declarative-view-factory.md) §2.5) |
| `flushStateIfDoneRendering` / `incrementRender` / `decrementRender` / `doneRendering` / `hasRenderedOnce` / `markAsRenderedOnce` | render bookkeeping |
| sections, stacks, loops, components, fragments, translations (`ManagesLayouts`, `ManagesStacks`, ...) | called by compiled Blade |
| `macro` / `mixin` | `Macroable`; take Closures |

### 1.4 How a declaration reaches a render

```php
// ManagesEvents.php:72 — composer() / creator() call this once per view name
protected function addViewEvent($view, $callback, $prefix = 'composing: ')
{
    $view = $this->normalizeName($view);                      // ViewName::normalize(): users/show -> users.show
    if ($callback instanceof Closure) { /* listen */ }
    elseif (is_string($callback)) { return $this->addClassEvent($view, $callback, $prefix); }
    // anything else: nothing registered, nothing thrown
}

// ManagesEvents.php:116
protected function buildClassEventCallback($class, $prefix)
{
    [$class, $method] = $this->parseClassEvent($class, $prefix);        // Str::parseCallback(): `@` only; default compose / create
    return function () use ($class, $method) {
        return $this->container->make($class)->{$method}(...func_get_args());   // ($view) positional; constructor DI only
    };
}

// ManagesEvents.php:158
protected function addEventListener($name, $callback)
{
    if (str_contains($name, '*')) {                            // `users.*`, `*`: a Dispatcher wildcard, matched by Str::is()
        $callback = function ($name, array $data) use ($callback) { return $callback($data[0]); };
    }
    $this->events->listen($name, $callback);
}

// Factory.php:148 — view(), ResponseFactory::view() (so ViewController), @include
public function make($view, $data = [], $mergeData = [])
{
    $path = $this->finder->find($view = $this->normalizeName($view));  // locations, namespaces, extensions
    $data = array_merge($mergeData, $this->parseData($data));
    return tap($this->viewInstance($view, $path, $data), function ($view) {
        $this->callCreator($view);                                         // creators: at make()
    });
}

// View.php:182
protected function renderContents()
{
    $this->factory->incrementRender();
    $this->factory->callComposer($this);                                  // composers: at render()
    $contents = $this->getContents();                                     // -> gatherData()
    // ...
}

// View.php:216
public function gatherData()
{
    $data = array_merge($this->factory->getShared(), $this->data);        // view data beats shared data
    // ...
}

// FileViewFinder.php:180 — addLocation() / prependLocation()
protected function resolvePath($path)
{
    return realpath($path) ?: $path;                                        // relative to the process CWD
}
```

Consequences, each verified against v13.33.0 with Testbench:

1. **Data precedence, lowest to highest:** `share` → `make()` data (for a `ViewController` route: its `data`, then route parameters, roadmap §1.2) → creator `with()` → composer `with()`. Every `with()` overwrites the key. The fixture route (§3.6, §3.7) renders `title` from its creator, not from `share` or the route's `data`.
2. **`Class` calls `make(Class)->compose($view)`** (`->create($view)` for a creator). **`Class@method` calls `make(Class)->method($view)`.** The constructor is container-injected; the method is not. Every failure is Laravel's own exception, thrown at the first render of a matching view. Registration itself never fails:
   - an invokable-only class fails with `Error: Call to undefined method Invokable::compose()`. As with `router.bind` (declarative-router-bindings.md §1.4.1), a bare class does **not** mean `__invoke`,
   - `Class::method` fails with `BindingResolutionException: Target class [Menus::primary] does not exist.`,
   - a second method parameter fails with `ArgumentCountError: Too few arguments to function Menus::primary(), 1 passed ... and exactly 2 expected`,
   - an unloadable class fails with `BindingResolutionException: Target class [...] does not exist.`
3. **Exact names before patterns.** `Dispatcher::getListeners()` (line 406) returns `array_merge(prepareListeners($name), getWildcardListeners($name))`. A `users.show` composer runs before the `users.*` and `*` composers, whatever the declaration order. Within each group, declaration order holds.
4. **`false` stops the chain.** `Dispatcher::invokeListeners()` (line 338) breaks on a `false` return, so every later composer for that render is skipped. Declare composers `: void`.
5. **A non-string `$callback` is dropped silently.** `composer('users.show', [A::class, B::class])` returns `[null]` and registers no listener. §2.1 relies on this.
6. **Lookup order.** `find()` searches the `prependLocation` paths, then `config('view.paths')`, then the `addLocation` paths, and the first file found wins. `admin::x` searches the `admin` hints in order, `prependNamespace` hints first. `addExtension()` unshifts its extension: with `html: blade`, `page.html` beats `page.blade.php`, and renders through Blade. An unknown engine fails at first render with `InvalidArgumentException: Engine [twig] not found.`
7. **Relative paths follow the CWD, not the base path** (`resolvePath()`). The CWD is `public/` under PHP-FPM and under `artisan serve` (`ServeCommand` starts its process in `public_path()`), and the base path under `artisan`. So a relative location compiles in `view:cache` but is not found over HTTP. That is why the provider applies `absolute()` (§2.2).
8. **Reserved shared keys.** The constructor shares `__env` (Factory.php:120), which compiled `@include`, `@extends`, `@section` and `@push` call. `ViewServiceProvider` shares `app`. `share: {__env: x}` makes every `@include` throw `ViewException: Call to a member function make() on string`. `ShareErrorsFromSession` (in the `web` group) re-shares `errors` on every request.
9. **Namespaces Laravel rewrites at render.** `RegisterErrorViewPaths` replaces `errors` when it renders an HTTP exception (line 17). `Mail\Markdown` replaces `mail` for every Markdown mail (lines 100, 135). A declared `errors` or `mail` hint is discarded at that render.

### 1.5 The PHP this replaces

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    View::addLocation(resource_path('declared-views'));
    View::addNamespace('admin', resource_path('admin-views'));
    View::share('brand', 'Tenant Console');
    View::composers([
        UserMenu::class => 'users.*',
        CurrentTenant::class => '*',
    ]);
    View::creator('users.show', Breadcrumbs::class);
}
```

```yaml
view:
  addLocation: [resources/declared-views]
  addNamespace: {admin: resources/admin-views}
  share: {brand: Tenant Console}
  composer:
    App\View\Composers\UserMenu: users.*
    App\View\Composers\CurrentTenant: '*'
  creator:
    App\View\Creators\Breadcrumbs: users.show
```

The PHP resolves the `Factory` in `boot()`. The manifest has the same effect when the `Factory` is first built (§1.1.1).

---

## 2. Manifest schema proposal

### 2.1 Design rule

> **Every key in the `view:` block is an `Illuminate\View\Factory` method name. Every value is the argument(s) of that method's signature.** A list gives one call per item. A map gives one call per entry, and its key is the first argument, except where the `Factory` defines the map form itself: `share` takes the map as its array `$key`, and `composer` / `creator` take `Factory::composers()`'s map, **keyed by the callback**.

This is the rule of `app:` and `router:` (declarative-application.md §2.1, declarative-router.md §2.1). The exception comes from the same source as `pattern`. `pattern` uses the map form of `Router::patterns()` (`$key => $pattern`). `composer` uses the map form of `Factory::composers()` (`$callback => $views`). In both cases the map is Laravel's, not the package's.

**Why `composer` and `creator` are keyed by the callback.** The roadmap's draft was `views: callback | list<callback>`. It is rejected because:

1. **The map is Laravel's own.** `composers(array $composers)` iterates `$callback => $views` and calls `composer($views, $callback)` (ManagesEvents.php:35). A `View::composers([...])` array from an existing provider copies over verbatim (§1.5).
2. **Both arguments pass through untouched.** `$views` is natively `array|string` (a name, a `*` pattern or a list), and `$callback` is the key. The provider neither loops nor casts.
3. **A view-keyed map cannot give one view two composers without inventing a value shape.** YAML keys are unique, so `'*'` could hold only one composer. The draft's `list<callback>` value is not a `composer()` argument. Passed through, it registers **nothing** and raises nothing (§1.4.5). Supporting it would take a second loop and an `(array)` cast: the provider translating instead of passing through (roadmap rule 3).
4. **`creator($views, $callback)` has the identical signature** and the identical `addViewEvent()` path, so it takes the identical shape. Laravel has no `creators()`.

### 2.2 Values

| Key | Value | Laravel does | Rules |
|---|---|---|---|
| `addLocation` / `prependLocation` | `list<path>` | appends / prepends to `FileViewFinder::$paths` | a relative path resolves under `basePath()` |
| `addNamespace` / `prependNamespace` / `replaceNamespace` | `map<namespace, path \| list<path>>` | appends / prepends / replaces `$hints[$namespace]` | every path through `absolute()` |
| `addExtension` | `map<extension, engine>` | finder extension + `extension → engine` | engine: `blade`, `php`, `file`, or one registered in PHP |
| `share` | `map<key, literal>` | `$shared[$key] = $value` | bound as YAML decoded it; a string is never resolved |
| `composer` | `map<Class \| Class@method, view \| list<view>>` | `listen('composing: {view}')` | default method `compose`; `*` wildcards |
| `creator` | same | `listen('creating: {view}')` | default method `create` |

**Paths.** Every path goes through the provider's existing `absolute()`. That is `Application::normalizeCachePath()`'s rule, already used by `app.use*Path` (declarative-application.md §2.4): a leading `/` or `\` is absolute, anything else goes under `basePath()`. YAML cannot call `resource_path()`, and Laravel resolves a relative path against the CWD (§1.4.7).

**References.** A composer or creator is an ordinary class:

```php
namespace App\View\Composers;

use App\Repositories\UserRepository;
use Illuminate\View\View;

final class UserMenu
{
    public function __construct(private UserRepository $users) {}   // make(): constructor DI

    public function compose(View $view): void                       // ($view) positional, as Laravel calls it; never return false
    {
        $view->with('menu', $this->users->menuFor($view->name()));
    }
}
```

**YAML.** Write references plain or single-quoted, and quote a bare `*`:

| YAML | Decodes to | Result |
|---|---|---|
| `App\View\Composers\Tenant: '*'` | `'*'` | correct |
| `App\View\Composers\Tenant: *` | `ParseException: Reference "" does not exist` | the manifest fails to load |
| `App\View\Composers\Tenant: [users.*, '*']` | `['users.*', '*']` | correct |
| `App\View\Composers\Menu@menu: [users.index, users.show]` | a `Class@method` key | correct: `@` is literal after the first character |
| `"App\View\X": users.show` | `ParseException: Found unknown escape character "\V"` | the manifest fails to load |
| `admin::dashboard` (as a view name) | `'admin::dashboard'` | correct: no `: ` inside |

### 2.3 Full example

```yaml
view:                                            # ——— View\Factory surface (this document) ———
  addLocation:                                   # -> addLocation($location), one call per item
    - resources/declared-views                   # searched after config('view.paths'); relative -> basePath()
  prependLocation:                               # -> prependLocation($location), one call per item
    - resources/theme                            # searched first: overrides resources/views
  addNamespace:                                  # -> addNamespace($namespace, $hints), one call per entry
    admin: resources/admin-views                 # view('admin::dashboard')
  prependNamespace:                              # -> prependNamespace($namespace, $hints)
    courier: [resources/overrides/courier]       # a package's views, overridden (§2.6)
  replaceNamespace:                              # -> replaceNamespace($namespace, $hints)
    legacy: resources/legacy-views
  addExtension:                                  # -> addExtension($extension, $engine)
    html: blade                                  # page.html renders through Blade
  share:                                         # -> share($key): the whole map, one call
    brand: Tenant Console                        # $brand in every view
  composer:                                      # -> composer($views, $callback), keyed as Factory::composers()
    App\View\Composers\UserMenu: users.*         # make(UserMenu)->compose($view) before each users.* render
    App\View\Composers\CurrentTenant: '*'        # every view; quote `*`
    App\View\Composers\Nav@primary: [layouts.app, layouts.admin]   # make(Nav)->primary($view)
  creator:                                       # -> creator($views, $callback): at make(), before composers
    App\View\Creators\Breadcrumbs: users.show    # make(Breadcrumbs)->create($view)

routes:
  - path: "users/{user}"
    methods: GET
    action: Illuminate\Routing\ViewController    # roadmap Phase 0: Route::view() through route keys
    middleware: [web]                            # web also re-shares `errors` (ShareErrorsFromSession)
    setDefaults: {view: users.show, data: {title: User}, status: 200, headers: {}}
    # users.show gets, lowest to highest: brand (share) < title, user (route) < Breadcrumbs < composers
```

### 2.4 Key → method → signature map

| YAML key (under `view:`) | `Factory` method | Value shape (YAML) | Dispatch (§2.5) | Absent → |
|---|---|---|---|---|
| `addLocation` | `addLocation` | `list<path>` | `$Factory->addLocation($this->absolute($location))` per item | `config('view.paths')` only |
| `prependLocation` | `prependLocation` | `list<path>` | `$Factory->prependLocation($this->absolute($location))` per item | `config('view.paths')` only |
| `addNamespace` | `addNamespace` | `map<namespace, path \| list<path>>` | `$Factory->addNamespace($namespace, array_map($this->absolute(...), (array) $hints))` per entry | packages' namespaces only |
| `prependNamespace` | `prependNamespace` | same | same shape | packages' namespaces only |
| `replaceNamespace` | `replaceNamespace` | same | same shape | packages' namespaces only |
| `addExtension` | `addExtension` | `map<extension, engine>` | `$Factory->addExtension($extension, $engine)` per entry | Laravel's four extensions |
| `share` | `share` | `map<key, literal>` | `$Factory->share($share)`, one call | `__env`, `app` only |
| `composer` | `composer` | `map<Class \| Class@method, view \| list<view>>` | `$Factory->composer($views, $callback)` per entry | no composer |
| `creator` | `creator` | same | `$Factory->creator($views, $callback)` per entry | no creator |
| `flushFinderCache` | `flushFinderCache` | `boolean` | `$Factory->flushFinderCache()` when `true` (the epilogue, after `creator`) | no flush |
| `flushState` | `flushState` | `boolean` | `$Factory->flushState()` when `true` (the epilogue, after `creator`) | no flush |

The order is fixed: `addLocation` → `prependLocation` → `addNamespace` → `prependNamespace` → `replaceNamespace` → `addExtension` → `share` → `composer` → `creator` → `flushFinderCache` / `flushState` (the epilogue). One pair interacts: `replaceNamespace` runs last among the namespace keys, so it discards the manifest's own `addNamespace` / `prependNamespace` hints for the same namespace. `composer` and `creator` do not interact, because they are separate events fired at fixed points (§1.1). The epilogue always runs after every registration, so a flushed finder cache cannot hide a declared path ([declarative-view-factory.md](declarative-view-factory.md) §2.5). An unknown key throws `LogicException` when the manifest is read. `composers` is an unknown key (§2.6).

### 2.5 Registration algorithm (for the provider)

A new `DataModel`, `src/View.php`, is hydrated from the `view:` key by a new `Manifest` property. It is `nullable`, not `default`, for the reason `Manifest::$app` gives (declarative-application.md §2.5):

```php
// src/Manifest.php
public const string view = 'view';

/** The `View\Factory` surface; null when the manifest has no (or an empty) `view:` block */
#[Describe([Describe::nullable => true])]
public ?View $view;
```

In `LaravelDeclarationProvider::boot()`, right after `registerRouter()` (§1.1.2):

```php
$Manifest = $this->app->make(Manifest::class);
$Router = $this->app->make(Router::class);

$this->registerRouter($Manifest, $Router);
$this->callAfterResolving('view', fn (Factory $Factory) => $this->registerView($Manifest, $Factory));
$this->registerProviders($Manifest);
$this->registerRoutes($Manifest, $Router);
```

```php
private function registerView(Manifest $Manifest, Factory $Factory): void
{
    foreach ($Manifest->view->addLocation ?? [] as $location) {
        $Factory->addLocation($this->absolute($location));
    }

    foreach ($Manifest->view->prependLocation ?? [] as $location) {
        $Factory->prependLocation($this->absolute($location));
    }

    foreach ($Manifest->view->addNamespace ?? [] as $namespace => $hints) {
        $Factory->addNamespace($namespace, array_map($this->absolute(...), (array) $hints));
    }

    foreach ($Manifest->view->prependNamespace ?? [] as $namespace => $hints) {
        $Factory->prependNamespace($namespace, array_map($this->absolute(...), (array) $hints));
    }

    foreach ($Manifest->view->replaceNamespace ?? [] as $namespace => $hints) {
        $Factory->replaceNamespace($namespace, array_map($this->absolute(...), (array) $hints));
    }

    foreach ($Manifest->view->addExtension ?? [] as $extension => $engine) {
        $Factory->addExtension($extension, $engine);
    }

    $Factory->share($Manifest->view->share ?? []);

    foreach ($Manifest->view->composer ?? [] as $callback => $views) {
        $Factory->composer($views, $callback);
    }

    foreach ($Manifest->view->creator ?? [] as $callback => $views) {
        $Factory->creator($views, $callback);
    }
}
```

That is the whole implementation: nine literal `Factory` calls, so phpstan checks each against its signature. The provider adds no wrapper and resolves no reference. Its only transformation is `absolute()`, the existing helper, and the `(array)` it needs for `array_map()` mirrors the `(array) $hints` that `FileViewFinder` applies itself. `share([])` is a no-op, so an absent block needs no guard.

- **`Factory` is `Illuminate\View\Factory`**, the concrete class. `prependLocation`, `prependNamespace` and `addExtension` are not on `Illuminate\Contracts\View\Factory`.
- **The provider never names the DataModel**, because `?? []` covers the absent block. So the same-namespace `ZeroToProd\LaravelDeclaration\View` needs no import alias, as with `Router` (declarative-router.md §2.5).
- **`callAfterResolving()` passes `($factory, $app)`.** The arrow function takes only the first argument.

### 2.6 Notes / non-goals

- **Keyed by the callback** (§2.1). A view-keyed map, the reversed shape, is accepted by Laravel silently: it composes a view literally named `App\...`, which never renders. `laravel-declaration:validate` rejects it (§3.4).
- **No `composers` key.** `composers()` is a loop over `composer()`, and the `composer` map is that loop. This is the `patterns` rule (declarative-router.md §2.6). The typo `composers:` throws.
- **No Closure or `.php` composers.** This keeps `view` a pure pass-through, like `router.bind`, and `Class@method` covers what a Closure would do. For the same reason, `addExtension()`'s `$resolver` is not declarable: register a custom engine in PHP, then map extensions to it by name here.
- **`share` takes literals only.** A string is shared as a string. A computed shared value is a `composer: {Class: '*'}`, Laravel's own answer (roadmap Phase 2), so the package adds no third reference convention.
- **`'*'` means every view**: pages, `@include`s, components, pagination, mail and notification views, error pages. Keep `'*'` composers cheap, or target a layout (`layouts.app`).
- **`addNamespace` is not `loadViewsFrom()`.** It registers only the declared paths. It does not add `resources/views/vendor/{namespace}`. To override a package's views from another directory, use `prependNamespace`. It is order-safe: whenever the package adds its hint, the prepended path stays first.
- **`replaceNamespace` is order-dependent.** It replaces only the hints registered before the block runs. Laravel replaces `errors` and `mail` again at render (§1.4.9), and a package that boots after this provider adds its hint afterwards (§1.1.5). Prefer `prependNamespace`.
- **`config.view.paths` vs `addLocation`.** The `config:` block's `view.paths` *replaces* Laravel's list when the finder is built, and the package passes it through verbatim, so it needs absolute paths. `addLocation` *appends* to that list, through `absolute()`. Use `config` to drop `resources/views`, and `addLocation` to add a directory beside it.
- **Blade is not `Factory`.** `Blade::component()`, `anonymousComponentPath()`, `directive()` and `if()` are `BladeCompiler` methods. A `blade:` block would be its own document.
- **Caching.** Nothing here is in `config:cache` or `route:cache`; the block re-applies on every boot. `view:cache` compiles the declared directories (§1.1.4). Re-run it after adding one, as for `resources/views`.
- **Not a validation layer.** Values pass through as YAML decoded them, and failures are Laravel's own, at first render (§1.4.2, §1.4.6). Through the schema, `laravel-declaration:validate` catches a view-keyed map, `Class::method`, a trailing `@`, a scalar where a list is required, and an empty hint list. Missing directories are accepted, as Laravel accepts them in `config('view.paths')`.
- **Phase 3.** `DeclaredView extends ViewController` renders through `ResponseFactory::view()` → `Factory::make()`, so every declaration here applies to it unchanged. See [declarative-view-data.md](declarative-view-data.md).

---

## 3. Implementation plan

1. **`src/View.php`** (new). It has the same shape as `Router`: one `const` and one property per key, in §2.4 order, and the unknown-key hook on the first property. The `pre` hook runs even when `addLocation` is absent (declarative-router.md §3.1):

   ```php
   <?php

   declare(strict_types=1);

   namespace ZeroToProd\LaravelDeclaration;

use LogicException;use Zerotoprod\DataModel\Describe;use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Key;use ZeroToProd\LaravelDeclaration\Internal\DataModel;

   final readonly class View
   {
       use DataModel;

       public const string addLocation = 'addLocation';

       /** @var list<string> */
       #[Key, Describe([Describe::pre => [self::class, 'validate'], Describe::default => []])]
       public array $addLocation;

       public const string prependLocation = 'prependLocation';

       /** @var list<string> */
       #[Key, Describe([Describe::default => []])]
       public array $prependLocation;

       public const string addNamespace = 'addNamespace';

       /** @var array<string, string|list<string>> */
       #[Key, Describe([Describe::default => []])]
       public array $addNamespace;

       // prependNamespace, replaceNamespace: same as addNamespace
       // addExtension: array<string, string>; share: array<string, mixed>
       // composer, creator: array<string, string|list<string>>; all Describe::default => []

       /** @param  array<array-key, mixed>  $context */
       public static function validate(mixed $value, array $context): void
       {
           $unknown = array_diff(array_keys($context), self::selected(Key::class));

           if ($unknown !== []) {
               throw new LogicException(
                   'The `view` block declares unknown key(s): '.implode(', ', $unknown).
                   '. Every key must be an `Illuminate\View\Factory` method name.'
               );
           }
       }
   }
   ```

2. **`src/Manifest.php`**. Add the `view` const and nullable property of §2.5 directly after `$router`.

3. **`../src/LaravelDeclarationProvider.php`**. Add `use Illuminate\View\Factory;`, insert the `callAfterResolving()` line of §2.5 into `boot()` after `registerRouter()`, and add `registerView()` after `registerRouter()`.

4. **`manifest.schema.json`**. Add `"view": { "$ref": "#/definitions/view" },` to the root `properties` after `"router"`, and these entries to `definitions` after `"router"`:

   ```json
   "view": {
     "description": "Illuminate\\View\\Factory methods: every key is a method name, its value the argument(s). Applied when Laravel first resolves `view` (callAfterResolving, queued in boot()). An unknown key throws LogicException.",
     "type": ["object", "null"],
     "additionalProperties": false,
     "properties": {
       "addLocation": {
         "description": "-> addLocation($location), one call per item: searched after config('view.paths'). A relative path resolves under basePath().",
         "type": "array",
         "items": { "type": "string" }
       },
       "prependLocation": {
         "description": "-> prependLocation($location), one call per item: searched before every other location. A relative path resolves under basePath().",
         "type": "array",
         "items": { "type": "string" }
       },
       "addNamespace": {
         "description": "-> addNamespace($namespace, $hints), one call per entry: `namespace::view` searches these paths after the hints already registered.",
         "type": "object",
         "additionalProperties": { "$ref": "#/definitions/stringOrList" }
       },
       "prependNamespace": {
         "description": "-> prependNamespace($namespace, $hints), one call per entry: searched before the hints already registered, so it overrides a package's views.",
         "type": "object",
         "additionalProperties": { "$ref": "#/definitions/stringOrList" }
       },
       "replaceNamespace": {
         "description": "-> replaceNamespace($namespace, $hints), one call per entry: discards the hints already registered. Laravel replaces `errors` and `mail` itself at render.",
         "type": "object",
         "additionalProperties": { "$ref": "#/definitions/stringOrList" }
       },
       "addExtension": {
         "description": "-> addExtension($extension, $engine), one call per entry: `.$extension` files render with $engine (blade, php, file) and are found before every other extension.",
         "type": "object",
         "additionalProperties": { "type": "string" }
       },
       "share": {
         "description": "-> share($key) with the whole map: every entry reaches every view as YAML decoded it. View data, creators and composers override it. Never share `__env` or `app`.",
         "type": "object"
       },
       "composer": {
         "description": "-> composer($views, $callback), one call per entry, keyed as Factory::composers() is: `Class` (method `compose`) or `Class@method` => a view name, a `*` pattern, or a list. make()d and called ($view) when the view renders.",
         "$ref": "#/definitions/viewEvents"
       },
       "creator": {
         "description": "-> creator($views, $callback), one call per entry, shaped as `composer`: default method `create`, called when the view is made, before any composer.",
         "$ref": "#/definitions/viewEvents"
       }
     }
   },
   "stringOrList": {
     "oneOf": [
       { "type": "string" },
       { "type": "array", "items": { "type": "string" }, "minItems": 1 }
     ]
   },
   "viewEvents": {
     "type": "object",
     "additionalProperties": false,
     "patternProperties": {
       "^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*(@[A-Za-z_][A-Za-z0-9_]*)?$": { "$ref": "#/definitions/stringOrList" }
     }
   },
   ```

   The key pattern is `router.bind`'s value pattern (declarative-router-bindings.md §3.3), and `patternProperties` + `additionalProperties: false` applies it to keys. `propertyNames` is not an option here: `justinrainbow/json-schema` 6.13.0 accepts every key under it. Checked with `laravel-declaration:validate`, the schema accepts `tests/Fixtures/manifest/view.yml`. It rejects `users.*: App\View\Menu` (the reversed map), `App\View\Menu::menu`, `App\View\Menu@`, `addNamespace: {admin: []}` and `addLocation: resources/x` (a scalar).

5. **`README.md`**. In `## Manifest`, make three edits. Change "applies its `router` block and registers its providers and routes in `boot()`" to "applies its `router` block, queues its `view` block for the view factory, and registers its providers and routes in `boot()`". Add `tests/Feature/ViewRegistrationTest.php` to the test list. Make the key list read "`config`, `app`, `router`, `view`, `providers`, `routes` and `requests`", with `[View](#view)` among the links. Then insert this section between `## Router` and `## Providers`:

   ````markdown
   ## View

   The `view` block maps 1:1 onto
   [`Illuminate\View\Factory`](https://laravel.com/docs/views#view-composers)
   methods: every key is a `Factory` method name, and its value is that method's
   argument(s). A list is one call per item, a map one call per entry.
   `LaravelDeclarationProvider` queues the block in `boot()` with
   `callAfterResolving('view')`, as Laravel's `loadViewsFrom()` does. It applies
   when Laravel first builds the view factory, and a request that renders nothing
   never builds it. See `tests/Feature/ViewRegistrationTest.php` and
   [docs/declarative-view.md](docs/declarative-view.md).

   `addLocation` and `prependLocation` add a view directory after or before
   `config('view.paths')`. `addNamespace`, `prependNamespace` and `replaceNamespace`
   register `namespace::view` directories. A relative path resolves under
   `basePath()`. `addExtension` renders `.extension` files with an existing engine
   (`blade`, `php`, `file`). `share` is `Factory::share()`: every entry reaches every
   view as YAML decoded it, and view data, creators and composers override it.
   `composer` and `creator` take `Factory::composers()`'s map. The key is the
   callback: `Class` (method `compose` / `create`) or `Class@method`. The value is a
   view name, a `*` pattern or a list. Laravel `make()`s the class and calls it with
   the `View`. Creators run when the view is made, composers when it renders.
   Exact names run before patterns. An unknown key throws a `LogicException`.

   Complete structure:

   ```yaml
   view:
     addLocation: [resources/declared-views]      # -> addLocation($location), one call per item
     prependLocation: [resources/theme]           # searched before config('view.paths')
     addNamespace:                                # -> addNamespace($namespace, $hints)
       admin: resources/admin-views               # view('admin::dashboard')
     prependNamespace:
       courier: [resources/overrides/courier]     # overrides a package's views
     replaceNamespace:
       legacy: resources/legacy-views
     addExtension:                                # -> addExtension($extension, $engine)
       html: blade
     share:                                       # -> share($key): every view gets $brand
       brand: Tenant Console
     composer:                                    # -> composer($views, $callback), keyed as Factory::composers()
       App\View\Composers\UserMenu: users.*       # make(UserMenu)->compose($view)
       App\View\Composers\CurrentTenant: '*'      # every view; quote `*`
       App\View\Composers\Nav@primary: [layouts.app, layouts.admin]
     creator:                                     # -> creator($views, $callback), default method `create`
       App\View\Creators\Breadcrumbs: users.show
   ```
   ````

6. **Fixtures**. PHP classes in `tests/Fixtures/App/View/`. Each file starts with `<?php`, `declare(strict_types=1);`, `namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View;` and `use Illuminate\View\View;`, as the other fixtures do:

   ```php
   // UserMenu.php
   /** `composer: {UserMenu: users.*}`: Laravel calls the default method, `compose`. */
   final class UserMenu
   {
       public function compose(View $view): void
       {
           $view->with('menu', 'users');
       }
   }

   // CurrentTenant.php
   /** `composer: {CurrentTenant: '*'}`: runs for every view. */
   final class CurrentTenant
   {
       public function compose(View $view): void
       {
           $view->with('tenant', 'acme');
       }
   }

   // Menus.php
   /** `composer: {Menus@primary: [...]}`: called positionally with the View. */
   final class Menus
   {
       public function primary(View $view): void
       {
           $view->with('nav', 'primary');
       }
   }

   // Breadcrumbs.php
   /** `creator: {Breadcrumbs: users.show}`: Laravel calls the default method, `create`, at make(). */
   final class Breadcrumbs
   {
       public function create(View $view): void
       {
           $view->with('crumb', 'users')->with('title', 'creator');
       }
   }
   ```

   Views in `tests/Fixtures/App/View/views/`, one line each:

   | File | Content |
   |---|---|
   | `users/show.blade.php` | `{{ $brand }}\|{{ $title }}\|{{ $user }}\|{{ $menu }}\|{{ $nav }}\|{{ $crumb }}\|{{ $tenant }}` |
   | `greeting.blade.php` | `base` |
   | `theme/greeting.blade.php` | `theme` |
   | `admin/dashboard.blade.php` | `admin` |
   | `admin-theme/dashboard.blade.php` | `admin-theme` |
   | `page.html` | `{{ $brand }}` |

   Paths resolve under Testbench's skeleton, not this repository (declarative-application.md §3.4). So `tests/TestCase.php::copyApplicationFiles()` gains one line after its loop, plus `use Illuminate\Filesystem\Filesystem;`, and its docblock names the `view:` block too:

   ```php
   (new Filesystem)->copyDirectory(__DIR__.'/Fixtures/App/View/views', $skeleton.'/resources/declared-views');
   ```

   `tests/Fixtures/manifest/view.yml`. It is a separate file, so the other tests' views and routes stay unchanged. Its relative paths exercise `absolute()`, and its route is roadmap Phase 0's `ViewController` shape:

   ```yaml
   # yaml-language-server: $schema=./../../../manifest.schema.json

   view:
     addLocation:
       - resources/declared-views
     prependLocation:
       - resources/declared-views/theme
     addNamespace:
       admin: resources/declared-views/admin
     prependNamespace:
       admin: [resources/declared-views/admin-theme]
     replaceNamespace:
       legacy: resources/declared-views/admin
     addExtension:
       html: blade
     share:
       brand: Tenant Console
       title: shared
     composer:
       ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\UserMenu: users.*
       ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\CurrentTenant: '*'
       ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Menus@primary: [users.show, greeting]
     creator:
       ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Breadcrumbs: users.show

   routes:
     - path: "users/{user}"
       methods: GET
       action: Illuminate\Routing\ViewController
       setDefaults: {view: users.show, data: {title: route}, status: 200, headers: {}}
   ```

7. **Tests**: `tests/Feature/ViewRegistrationTest.php`. These exact tests and fixtures pass, reproduced in a scratch copy of this repository:

   ```php
   <?php

   declare(strict_types=1);

   $manifest = __DIR__.'/../Fixtures/manifest/view.yml';

   it('applies the block when Laravel first resolves the view factory', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);

       expect(app()->resolved('view'))->toBeFalse()
           ->and(app('view')->getShared())->toMatchArray(['brand' => 'Tenant Console', 'title' => 'shared']);
   });

   it('adds and prepends locations under basePath', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);

       expect(app('view')->getFinder()->getPaths())->toBe([
           base_path('resources/declared-views/theme'),
           resource_path('views'),
           base_path('resources/declared-views'),
       ])->and(view('greeting')->render())->toBe("theme\n");
   });

   it('adds, prepends and replaces namespace hints', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);

       expect(app('view')->getFinder()->getHints())->toMatchArray([
           'admin' => [base_path('resources/declared-views/admin-theme'), base_path('resources/declared-views/admin')],
           'legacy' => [base_path('resources/declared-views/admin')],
       ])->and(view('admin::dashboard')->render())->toBe("admin-theme\n");
   });

   it('renders a declared extension with its engine', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);

       expect(view('page')->render())->toBe("Tenant Console\n");
   });

   it('layers shared data, route data, creators and composers on a ViewController route', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);

       $this->get('/users/7')->assertOk()->assertSeeText('Tenant Console|creator|7|users|primary|users|acme');
   });

   it('applies nothing without a view block', function (): void {
       $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/requests.yml']);

       expect(array_keys(app('view')->getShared()))->toBe(['__env', 'app'])
           ->and(app('view')->getFinder()->getPaths())->toBe([resource_path('views')]);
   });

   it('rejects unknown view keys', function (): void {
       $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
       file_put_contents($file, <<<'YAML'
           view:
             composers:
               App\View\Composers\UserMenu: users.*
           YAML);

       expect(fn (): bool => $this->withConfig(['laravel-declaration.manifest' => $file]) !== null)
           ->toThrow(LogicException::class, 'unknown key(s): composers');
   });
   ```

   Also add to `tests/Feature/ValidateCommandTest.php`, so the schema entry is exercised:

   ```php
   test('laravel-declaration:validate accepts the view block', function (): void {
       $this->artisan('laravel-declaration:validate', ['--manifest' => __DIR__.'/../Fixtures/manifest/view.yml'])
           ->expectsOutputToContain('is valid')
           ->assertSuccessful();
   });
   ```

8. **`composer check`**: lint, rector, phpstan, 100% coverage, bc-check. In the scratch copy, with steps 1–7 applied, `pint --test`, `rector process --dry-run` and `phpstan analyse` pass, and `pest --coverage --min=100` passes 115 tests at 100.0%. `bc-check` was not run there: it needs this repository's git tags. The new public API is `View` (its properties and `validate()`) and `Manifest::$view`. Both are additive. The MCP `api` tool reflects `src/`, so it lists `View` with no change (`PublicApiToolTest` passes), and the `readme` tool serves the updated README.

9. **`docs/declarative-request-to-view-roadmap.md`**. Make six edits:
   - in §1, set stages 10 and 11 to "done",
   - in §2 rule 2, replace "(`abstract: concrete` in `app`, `views: callback` in `view`)" with "(`abstract: concrete` in `app`), unless Laravel defines the map form itself: `Factory::composers()` is `callback: views` (declarative-view.md §2.1)",
   - in Phase 2's YAML, write `composer` and `creator` keyed by the callback, as in §2.3 of this document,
   - in Phase 2's table, set their value shape to `map<Class | Class@method, view | list<view>>`, and split the slash rows into one row per key,
   - in Phase 2's decisions, replace the first bullet with "Queued in `boot()` with `callAfterResolving('view')`, as `loadViewsFrom()` is, and applied when Laravel first builds the `Factory` (declarative-view.md §1.1)". Rendering happens at dispatch, so order relative to `registerRoutes()` is irrelevant,
   - in §4, change the `composer` line to `App\View\Composers\CurrentTenant: '*'`.

---

### Sources

1. `make()`, `share()`, `addLocation()`, `prependLocation()`, `addNamespace()`, `prependNamespace()`, `replaceNamespace()`, `addExtension()`, `getShared()`, constructor `share('__env')` — [View/Factory.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/View/Factory.php)
2. `composer()`, `composers()`, `creator()`, `addViewEvent()`, `buildClassEventCallback()`, `classEventMethodForPrefix()`, `addEventListener()`, `callComposer()`, `callCreator()` — [View/Concerns/ManagesEvents.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/View/Concerns/ManagesEvents.php)
3. `$paths`, `$hints`, `$extensions`, `resolvePath()`, namespace and extension methods — [View/FileViewFinder.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/View/FileViewFinder.php); name normalization — [View/ViewName.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/View/ViewName.php)
4. `renderContents()`, `gatherData()`, `with()` — [View/View.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/View/View.php)
5. `view` singleton, `view.finder` bind reading `config('view.paths')`, `share('app')` — [View/ViewServiceProvider.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/View/ViewServiceProvider.php)
6. `listen()` wildcards, `getListeners()` exact-before-wildcard, `invokeListeners()` stop on `false` — [Events/Dispatcher.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Events/Dispatcher.php)
7. `callAfterResolving()`, `loadViewsFrom()` — [Support/ServiceProvider.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Support/ServiceProvider.php); `afterResolving()` and resolve-once — [Container/Container.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Container/Container.php)
8. `ViewController` data merge, `ResponseFactory::view()` → `Factory::make()` — [Routing/ViewController.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/ViewController.php), [Routing/ResponseFactory.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/ResponseFactory.php)
9. `view:cache` reads finder paths and hints — [Foundation/Console/ViewCacheCommand.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Foundation/Console/ViewCacheCommand.php); `artisan serve` CWD is `public_path()` — [Foundation/Console/ServeCommand.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Foundation/Console/ServeCommand.php)
10. Laravel rewrites `errors` and `mail` at render — [Foundation/Exceptions/RegisterErrorViewPaths.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Foundation/Exceptions/RegisterErrorViewPaths.php), [Mail/Markdown.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Mail/Markdown.php); `errors` re-shared per request — [View/Middleware/ShareErrorsFromSession.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/View/Middleware/ShareErrorsFromSession.php)
11. `share` and composers belong in a provider's `boot()` — [docs/repos/laravel/docs/views.md](repos/laravel/docs/views.md#sharing-data-with-all-views), [laravel.com/docs/views#view-composers](https://laravel.com/docs/views#view-composers); `loadViewsFrom()` and `resources/views/vendor` overrides — [docs/repos/laravel/docs/packages.md](repos/laravel/docs/packages.md#views)
12. YAML decoding of `*`, `'*'`, `Class@method` keys, `admin::dashboard` and `"App\View\X"` — `symfony/yaml` v8.1.6, verified with `Yaml::parse()` in this repository
13. `propertyNames` ignored, `patternProperties` enforced — `justinrainbow/json-schema` 6.13.0, verified with `JsonSchema\Validator` and `laravel-declaration:validate` in this repository
14. Every consequence in §1.1 and §1.4, the §2.5 algorithm, and the §3.4–§3.8 schema, fixtures, tests and `composer check` results — reproduced with `Orchestra\Testbench\Foundation\Application::create()` and a scratch copy of this repository (scratch files, not committed)
