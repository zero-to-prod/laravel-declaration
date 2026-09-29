# Roadmap — Declarative Request → View

Source of truth: `vendor/laravel/framework/src/Illuminate` (`laravel/framework` v13.33.0): `Routing/Router.php`, `Routing/ViewController.php`, `Routing/RouteBinding.php`, `Routing/ControllerDispatcher.php`, `Routing/Controller.php`, `Routing/ImplicitRouteBinding.php`, `View/Factory.php`, `View/Concerns/ManagesEvents.php`, `View/View.php`, `Foundation/Http/Kernel.php`.

Goal: one manifest declares the whole HTTP path — **request in, validated, bound, shaped into view data, rendered** — with no hand-written controller. Every new block follows the rule the existing blocks follow: **a key is a Laravel method (or property) name, a value is its argument(s), and the provider applies it with Laravel's own call.** Anything that must be PHP is a **reference string**, resolved exactly as Laravel resolves it.

---

## 1. The pipeline and what already covers it

The request lifecycle, in dispatch order, with the Laravel API that owns each stage:

| # | Stage | Laravel API | Manifest key | Status |
|---|---|---|---|---|
| 1 | Container | `Application` | `app` | done |
| 2 | Config | `Config::set()` | `config` | done |
| 3 | Providers | `Application::register()` | `providers` | done |
| 4 | HTTP Kernel pipeline | `Http\Kernel` middleware, groups, priority | `kernel` | done (Phase 5) |
| 5 | Global parameter patterns | `Router::pattern()` / `patterns()` | `router` | done (Phase 1) |
| 6 | Route match + route middleware | `Router::addRoute()` + `Route` builders | `routes` | done |
| 7 | Parameter → model / value | `Router::bind()` / `Router::model()` | `router` | done (Phase 1) |
| 8 | Eloquent model defaults + scopes | `Model` properties, observers, global scopes | `models` | done |
| 9 | Authorize + validate | `FormRequest` | `requests` + `metadata.request` | done |
| 10 | Reusable query pipelines | `Eloquent\Builder` chaining + terminals | `queries` | done (Phase 4) |
| 11 | Action | `Router::view()` → `ViewController` | `routes.action` + `setDefaults` | done (`DeclaredView`) |
| 12 | Dynamic view data | `ViewController` `data` | `DeclaredView` | done (Phase 3) |
| 13 | Shared / composed view data | `View\Factory::share()` / `composer()` / `creator()` | `view` | done (Phase 2) |
| 14 | View lookup | `View\Factory::addLocation()` / `addNamespace()` | `view` | done (Phase 2) |
| 15 | Response status + headers | `ResponseFactory::view($view, $data, $status, $headers)` | `setDefaults.status` / `headers` | done (`DeclaredView`) |

The gaps that previously blocked "no controller" — **action implicit binding**, **static view data**, and **query pipeline execution** — are resolved by `DeclaredView` (Phase 3) and `queries:` (Phase 4).

### 1.1 Why stage 6 needs explicit binding

`ImplicitRouteBinding::resolveForRoute()` walks `$route->signatureParameters(['subClass' => UrlRoutable::class])`: the **action's type-hints**. `ViewController::__invoke(...$args)` hints nothing, so `{user}` stays the raw string `"1"`. `Router::substituteBindings()` instead reads `$this->binders[$key]`, populated by `Router::bind()` / `Router::model()`, and needs no type-hint. It runs in the `SubstituteBindings` middleware, which the `web` and `api` groups include (`Foundation/Configuration/Middleware.php`), so a declared route opts in with `middleware: [web]`.

### 1.2 Why stage 8 needs no code

`Router::view()` is sugar over the existing `routes` keys:

```php
// Router::view($uri, $view, $data = [], $status = 200, array $headers = [])
$this->match(['GET', 'HEAD'], $uri, '\Illuminate\Routing\ViewController')
    ->setDefaults(['view' => $view, 'data' => $data, 'status' => $status, 'headers' => $headers]);
```

`ViewController::__invoke(...$args)` merges every route parameter that is not `view`, `data`, `status` or `headers` into `data` (route parameters win), then calls `ResponseFactory::view()`. It overrides `callAction()` to keep the parameters **named**, unlike `Controller::callAction()` (`array_values()`). So every bound parameter from stage 6 reaches the view by its route name.

---

## 2. Design rules carried forward

1. **Key = method name.** `router.model` → `Router::model()`, `view.composer` → `Factory::composer()`. No invented verbs.
2. **Map = one call per entry, first argument is the key** (`abstract: concrete` in `app`), unless Laravel defines the map form itself: `Factory::composers()` is `callback: views` (declarative-view.md §2.1). **List = one call per item.**
3. **Pass references through when Laravel accepts strings.** `Router::bind()` takes `Class@method` (default `bind`) and `Factory::composer()` takes `Class@method` (default `compose`) natively. The provider forwards the string; Laravel resolves it; semantics (positional `$value, $route` / `$view`) are Laravel's, not the package's.
4. **Wrap only where Laravel needs a Closure**, and then call through `Container::call()` with **named** parameters, as `app.extend`, the hooks and `DeclaredRequest` already do.
5. **One seam class per Laravel base class.** `DeclaredRequest extends FormRequest`; `DeclaredView extends ViewController`. Each reads its declaration from the matched route.
6. **`route:cache` / `config:cache` safe.** Route defaults and metadata hold only strings, lists and maps.
7. **Fail at the same place Laravel fails.** Unknown keys throw `LogicException` when the manifest is read; bad references fail with Laravel's own exception at first use.

---

## 3. Phases

Each phase is independently shippable, and each is **one DataModel + one provider loop + one doc + one fixture + one Feature test** (§5).

### Phase 0 — `Router::view()` through existing route keys [Superseded by Phase 3]

Functionally available via native Laravel `Illuminate\Routing\ViewController`. Documented and verified as `Route::view('users/{user}', 'users.show', ['title' => 'User'])`:

```yaml
routes:
  - path: "users/{user}"
    methods: GET                               # GET implies HEAD, as Router::view() matches both
    action: Illuminate\Routing\ViewController
    name: users.show
    setDefaults:                               # Router::view()'s own argument names
      view: users.show
      data: {title: User}
      status: 200
      headers: {}
```

Known footgun: `ViewController` reads `$args['data']`, `$args['status']` and `$args['headers']` unconditionally, so **all four keys are required**; a missing one causes an `ErrorException` (undefined array key).

**Status:** Superseded by **Phase 3** (`DeclaredView`), which provides safe defaults (`data: []`, `status: 200`, `headers: []`) and resolves dynamic references and query handles. Raw `ViewController` remains available for static routes with explicit default arrays.

### Phase 1 — `router:` block → `Illuminate\Routing\Router` [Completed]

Closes stage 7 (and stage 5).

```yaml
router:
  pattern:                                     # -> pattern($key, $pattern); global `where`
    id: '[0-9]+'
  model:                                       # -> model($key, $class)
    user: App\Models\User                      # {user} -> User::resolveRouteBinding($value)
  bind:                                        # -> bind($key, $binder)
    post: App\Routing\PostBinder               # Class@method, default `bind`; ($value, $route) positional, as Laravel calls it
    team: App\Routing\Teams@bySlug
```

| YAML key | `Router` method | Value | Applied |
|---|---|---|---|
| `pattern` | `pattern($key, $pattern)` | `map<param, regex>` | `boot()`, **before** `registerRoutes()`: `Router::addWhereClausesToRoute()` merges `$this->patterns` into each route's `where` when it is added |
| `model` | `model($key, $class)` | `map<param, class-string>` | `boot()` |
| `bind` | `bind($key, $binder)` | `map<param, reference>` | `boot()`; string forwarded, `RouteBinding::createClassBinding()` resolves it |

Decisions:

- **No `patterns` key.** `patterns($patterns)` is a loop over `pattern()`; the map form of `pattern` already is that loop. Same rule as `app.bind` being one call per entry.
- **No third `model()` argument.** The not-found Closure duplicates `Route::missing()`, already a route key.
- **No `.php` references in `bind`.** Keeps `Router` a pure pass-through; revisit if a Closure binder is requested.
- **Middleware (`aliasMiddleware`, `middlewareGroup`, `pushMiddlewareToGroup`) is deferred to Phase 5.** `Http\Kernel::syncMiddlewareToRouter()` re-runs on every Kernel mutation and overwrites any group the Kernel owns (`web`, `api`), so a Router-level declaration is not durable. It belongs on the Kernel, not the Router.

**Deliverables completed:** `src/Router.php` (DataModel), `Manifest::$router`, `RouterDeclarationServiceProvider`, `docs/declarative-router.md`, [docs/declarative-router-bindings.md](declarative-router-bindings.md), `tests/Fixtures/manifest/router.yml`, `tests/Feature/RouterRegistrationTest.php`, `manifest.schema.json` `router` definition.

### Phase 2 — `view:` block → `Illuminate\View\Factory` [Completed]

Closes stages 12, 13, and 14: data every view (or a view pattern) receives, and where views live.

```yaml
view:
  addLocation:                                 # -> addLocation($location); relative -> basePath()
    - resources/declared-views
  addNamespace:                                # -> addNamespace($namespace, $hints)
    admin: resources/admin-views               # admin::dashboard
  share:                                       # -> share($key); literal, as YAML decoded it
    brand: Tenant Console
  composer:                                    # -> composer($views, $callback), keyed as Factory::composers()
    App\View\Composers\UserMenu: users.*      # Class@method, default `compose`; receives View positionally
    App\View\Composers\CurrentTenant: '*'     # every view; quote `*`
  creator:                                     # -> creator($views, $callback); runs at make(), before composers
    App\View\Creators\Breadcrumbs: users.show # default method `create`
```

| YAML key | `Factory` method | Value |
|---|---|---|
| `addLocation` | `addLocation($location)` | `list<path>` |
| `prependLocation` | `prependLocation($location)` | `list<path>` |
| `addNamespace` | `addNamespace($namespace, $hints)` | `map<namespace, path \| list<path>>` |
| `prependNamespace` | `prependNamespace($namespace, $hints)` | `map<namespace, path \| list<path>>` |
| `replaceNamespace` | `replaceNamespace($namespace, $hints)` | `map<namespace, path \| list<path>>` |
| `addExtension` | `addExtension($extension, $engine)` | `map<extension, engine>` |
| `share` | `share($key)` | `map<key, literal>` |
| `composer` | `composer($views, $callback)` | `map<Class \| Class@method, view \| list<view>>` |
| `creator` | `creator($views, $callback)` | `map<Class \| Class@method, view \| list<view>>` |

Decisions:

- Queued in `boot()` with `callAfterResolving('view')`, as `loadViewsFrom()` is, and applied when Laravel first builds the `Factory` (declarative-view.md §1.1). Rendering happens at dispatch, so order relative to `registerRoutes()` is irrelevant.
- Paths resolve with the existing `absolute()` rule (`/` or `\` prefix is absolute, else `basePath()`).
- `share` values are literals only. A dynamic shared value is a `composer: {"*": ...}` — Laravel's own answer, so the package adds no third reference convention.
- `composer` / `creator` strings are forwarded untouched. `Factory::addViewEvent()` accepts `Class@method`; wildcard patterns (`users.*`, `*`) are Laravel's.

Deliverables completed: `src/View.php`, `Manifest::$view`, `ViewDeclarationServiceProvider`, `docs/declarative-view.md`, `tests/Fixtures/manifest/view.yml`, `tests/Feature/ViewRegistrationTest.php`, schema.

### Phase 3 — `DeclaredView` → `Illuminate\Routing\ViewController` [Completed]

Closes stage 11, and joins stage 9 to stage 12. This is the phase that makes **request → view** one declaration.

```yaml
routes:
  - path: "users/{user}/posts"
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    middleware: [web]                          # SubstituteBindings -> router.model runs
    metadata:
      request: post-filters                    # optional: validated before any data resolves
    setDefaults:                               # still Router::view()'s argument names
      view: users.posts                        # the only required key
      data:
        title: Posts                           # literal
        posts: App\Queries\UserPosts           # reference -> Container::call()
        stats: App\Queries\PostStats@forUser
      status: 200                              # optional, default 200
      headers: {Cache-Control: private}        # optional, default []
```

See [declarative-view-data.md](declarative-view-data.md) §2.5.

The reference:

```php
final class UserPosts
{
    // `$user` matches {user} by NAME and is the model router.model bound; `$request` is the DeclaredRequest
    public function __invoke(User $user, DeclaredRequest $request): LengthAwarePaginator
    {
        return $user->posts()->orderBy($request->validated('sort', 'created_at'))->paginate();
    }
}
```

Decisions:

- **`setDefaults`, not a new `views:` block.** `view`/`data`/`status`/`headers` are `Router::view()`'s signature and `ViewController`'s contract; the route schema gains no key, and a Phase 0 route upgrades by changing only `action`. Reuse across routes is YAML anchors (`&posts` / `*posts`), not a registry.
- **References get route parameters by name + `$request`**, the named-parameter convention of `app.extend` (`$instance`, `$app`) and `DeclaredRequest` (`$request`, `$validator`). Same trap applies and is documented: `DeclaredRequest $req` re-resolves and re-validates.
- **String = reference only when namespaced**: its text before the first `@` contains `\` (`DeclaredRequest::rule()`'s test, with `@` for `:`). `title: Posts` stays a literal. A literal that contains `\` before any `@`/`::` must come from a reference or `view.share`.
- **Only top-level `data` values resolve.** Nested maps/lists pass untouched, like `app.instance`.
- **Laravel's precedence is kept:** route parameters override `data` keys of the same name (`ViewController`'s `array_merge($args['data'], $routeParameters)`).
- **Request validation is opt-in by `metadata.request`**, the key that already exists. No `metadata.request` → references receive the base `Illuminate\Http\Request`.
- `DeclaredView` is **public API** (like `DeclaredRequest`); `bc-check` covers it.
- **No DataModel, no provider loop.** `setDefaults` and `metadata` are existing route keys that `RoutesDeclarationServiceProvider` already applies.

**Deliverables completed:** `src/DeclaredView.php`, `docs/declarative-view-data.md`, `tests/Fixtures/manifest/view-data.yml`, `tests/Feature/DeclaredViewTest.php` (literal, reference, bound model, validated request, 422 before data resolves, defaults, parameter precedence), README section, `manifest.schema.json` `setDefaults` / `metadata.request` descriptions.

### Phase 4 — `queries:` → `Illuminate\Database\Eloquent\Builder` [Completed]

Enables reusable Eloquent query pipelines without PHP controllers or query wrapper classes. Keys match `Illuminate\Database\Eloquent\Builder` methods applied in document order. A reserved `from` key roots the query on an Eloquent model class (`App\Models\Flight`) or a bound route parameter relation (`user.posts`). Terminal execution methods (`paginate`, `simplePaginate`, `cursorPaginate`, `get`, `first`, `firstOrFail`, `sole`, `count`, `exists`, `value`, `pluck`) execute the builder, defaulting to `get()`.

```yaml
queries:
  - name: user-posts
    from: user.posts                           # route parameter {user} -> $user->posts()
    with: [author]                             # -> with(['author'])
    scopes: [published]                        # -> local scope published()
    latest: created_at                         # -> latest('created_at')
    paginate: 15                               # terminal; reads ?page natively
```

Resolution of gating question: **Request-derived arguments** live cleanly in **local scopes** on the Model (`#[Scope]`), requiring no custom argument mapping DSL. `Builder::paginate()` natively resolves request pagination parameters (`?page=2`). `DeclaredView` detects string handles matching `Manifest::$queries` and executes them via `DeclaredQuery::run($name, $parameters)` before view rendering.

**Deliverables completed:** `src/Query.php` (DataModel), `src/DeclaredQuery.php` (invoker facade), `Manifest::$queries`, `docs/declarative-query.md`, `tests/Fixtures/manifest/queries.yml`, `tests/Feature/DeclaredQueryTest.php`, `manifest.schema.json` `queries` definition.

### Phase 5 — `kernel:` block → `Illuminate\Foundation\Http\Kernel` [Completed]

Middleware aliases, groups, and priority, declared where they are durable: `Kernel::pushMiddleware()`, `prependMiddleware()`, `setGlobalMiddleware()`, `appendMiddlewareToGroup()`, `prependMiddlewareToGroup()`, `setMiddlewareGroups()`, `setMiddlewareAliases()`, `setMiddlewarePriority()`, `prependToMiddlewarePriority()`, `appendToMiddlewarePriority()`, `addToMiddlewarePriorityBefore()`, `addToMiddlewarePriorityAfter()`, `whenRequestLifecycleIsLongerThan()`, each of which re-syncs the Router itself.

**Deliverables completed:** `src/Kernel.php` (DataModel), `Manifest::$kernel`, `KernelDeclarationServiceProvider`, `docs/declarative-kernel.md`, `tests/Fixtures/manifest/kernel.yml`, `tests/Feature/KernelRegistrationTest.php`, `manifest.schema.json` `kernel` definition.

---

## 4. End state (Unified Pipeline)

```yaml
kernel:
  middlewarePriority:
    - Illuminate\Routing\Middleware\SubstituteBindings

router:
  model:
    user: App\Models\User

view:
  share:
    brand: Tenant Console
  composer:
    App\View\Composers\CurrentTenant: '*'

requests:
  - name: post-filters
    rules:
      sort: [nullable, 'in:created_at,title']

queries:
  - name: user-posts
    from: user.posts
    with: [author]
    latest: created_at
    paginate: 15

routes:
  - path: "users/{user}/posts"
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    name: users.posts
    middleware: [web]
    where: {user: '[0-9]+'}
    metadata: {request: post-filters}
    setDefaults:
      view: users.posts
      data:
        title: Posts
        posts: user-posts                      # declared query handle -> DeclaredQuery::run()
        stats: App\Queries\PostStats@forUser   # container reference -> app()->call()
```

`GET /users/7/posts?sort=title&page=2` → route match → `SubstituteBindings` → `router.model` binds `$user` → `DeclaredRequest` authorizes + validates `post-filters` (422/redirect on failure; no query runs) → `DeclaredView` resolves `user-posts` via `DeclaredQuery::run()` and `PostStats@forUser` via `app()->call()` → `ViewController` merges `{title, posts, stats, user}` → `CurrentTenant` composer adds `tenant` → `share` adds `brand` → `users.posts` renders. The only PHP files are model definitions, composers, and optional custom references.

---

## 5. Where We Stand vs. What Is Left To Do

### 5.1 Where We Stand

All 5 roadmap phases and related core subsystems are **completed, tested, and green**:

1. **Phase 1 (`router:`)**: Global patterns, explicit model binding, custom binder classes registered via `RouterDeclarationServiceProvider`.
2. **Phase 2 (`view:`)**: View paths, namespaces, shared data, view composers, and creators registered via `ViewDeclarationServiceProvider`.
3. **Phase 3 (`DeclaredView`)**: Zero-controller action extending `ViewController`, resolving container references and request-validated data.
4. **Phase 4 (`queries:`)**: Reusable Eloquent query pipelines with 40+ builder methods, model and relation roots, terminal execution, and automatic resolution in `DeclaredView`.
5. **Phase 5 (`kernel:`)**: Durable HTTP middleware pipelines, groups, aliases, priorities, and request duration lifecycle handlers registered via `KernelDeclarationServiceProvider`.
6. **Eloquent Models (`models:`)**: Declarative Eloquent models via `DeclaredModel` (tables, primary keys, fillable attributes, casts, global scopes, observers, route key names).
7. **Modular Service Providers (`src/Providers/`)**: Concern providers cleanly isolated and invoked via container method injection, replacing monolithic provider loops.
8. **Tooling & Quality**: 100% test coverage, PHPStan max level, Rector, Pint formatting, backwards-compatibility validation (`bc-check`), and Model Context Protocol (MCP) server integration.

### 5.2 What Is Left To Do

The core declarative request-to-view roadmap is functionally complete. The following optional follow-up tasks and future extensions remain:

1. **Unified Full-Stack Integration Test**:
   - Add a single end-to-end integration test (`tests/Feature/EndToEndRequestToViewTest.php`) and fixture asserting the entire unified pipeline (`kernel` + `router.model` + `requests` + `queries` + `view.share` + `view.composer` + `DeclaredView`) executing within a single HTTP request lifecycle exactly as presented in §4.
2. **Phase 0 Documentation / Test Decision**:
   - Either add `tests/Feature/RouteViewTest.php` pinning vanilla `Illuminate\Routing\ViewController` behavior, or officially document it as superseded in favor of `DeclaredView`.
3. **Future Extension — `DeclaredJson`**:
   - Zero-controller JSON API response action extending `ResponseFactory::json()` or `JsonResource` pipelines for REST API parity with `DeclaredView`.
4. **Future Extension — Query Parameter Injection into Scopes**:
   - Optional convention or attribute for mapping request query parameters directly into model local scopes when invoking declared queries.

---

## 6. Definition of done (every phase)

1. DataModel in `src/` with one `public const string` + property per key, `Describe` defaults matching "absent" behavior; unknown keys throw `LogicException` at read (as `App`).
2. `Manifest` property; provider applies it in the phase Laravel would (documented in the doc's §1.1).
3. `docs/declarative-<feature>.md` in the house format: §1 Laravel API (source of truth + lifecycle), §2 schema (design rule, references, full example, key → method map, registration algorithm, non-goals).
4. README section with the complete structure block; `manifest.schema.json` definition.
5. Fixture YAML under `tests/Fixtures/manifest/` + `tests/Feature/*Test.php`.
6. `composer check` green: lint, rector, phpstan, **100% coverage**, bc-check.

## 7. Non-goals

- JSON responses / `JsonResource` shaping. `ResponseFactory::json()` is a sibling of `view()`; a `DeclaredJson` would follow Phase 3's pattern, but it is not on the request → view path.
- Blade templates in YAML. Views stay `.blade.php`.
- A general expression language in values. Anything computed is a reference.
- Replacing controllers. A hand-written controller keeps working beside every phase.
