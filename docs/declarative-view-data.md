# Declarative View Data — `Illuminate\Routing\ViewController` Data References & Manifest Schema

Source of truth: `vendor/laravel/framework/src/Illuminate/Routing/ViewController.php` (`laravel/framework` v13.33.0), with `Illuminate/Routing/Router.php`, `Illuminate/Routing/Route.php`, `Illuminate/Routing/RouteParameterBinder.php`, `Illuminate/Routing/ControllerDispatcher.php`, `Illuminate/Routing/Controller.php`, `Illuminate/Routing/ResponseFactory.php`, `Illuminate/View/Factory.php`, `Illuminate/Container/BoundMethod.php`, `Illuminate/Routing/AbstractRouteCollection.php` and `Illuminate/Routing/CompiledRouteCollection.php`.

Goal: a view route whose **`setDefaults` keys are `Router::view()`'s parameter names** (`view`, `data`, `status`, `headers`) and whose **`data` values are view variables, or a PHP reference that returns one**. This is Phase 3 of [declarative-request-to-view-roadmap.md](declarative-request-to-view-roadmap.md): stage 9 (dynamic view data), joining stage 7 (`DeclaredRequest`) to stage 10 (`view:`). The package ships one class, `DeclaredView extends ViewController` (§2.5). It adds no manifest key, no DataModel, no `Manifest` property and no provider code: `action`, `metadata` and `setDefaults` are existing route keys, and `registerRoutes()` already applies them.

---

## 1. Public API of `ViewController::class`

### 1.1 Lifecycle position (when the declaration is read)

```php
// boot(): registerRoutes() — unchanged
$Router->addRoute('GET', 'users/{user}/posts', DeclaredView::class)   // Route::__construct(): GET adds HEAD (Route.php:185)
    ->middleware([...])
    ->metadata(['request' => 'post-filters'])
    ->setDefaults(['view' => ..., 'data' => [...], ...]);              // Route::$defaults: strings, lists, maps only

// per request
Router::findRoute()                         // container->instance(Route::class, $route) (Router.php:781)
    RouteParameterBinder::parameters()      // replaceDefaults(): every setDefaults key becomes a route parameter
route middleware
    SubstituteBindings                      // router.model / router.bind replace {user} with the model
ControllerDispatcher::dispatch()            // $route->parametersWithoutNulls(), by name
    DeclaredView::callAction()              // inherited from ViewController: parameters stay NAMED
    DeclaredView::__invoke(...$args)        // $args = setDefaults + URL parameters
        $args += defaults                   // data [], status 200, headers []            <- Router::view()'s defaults
        app(DeclaredRequest::class)         // only with metadata.request: validateResolved() -> 422 / redirect HERE
        Container::call() per reference     // route parameters by name + $request       <- `data` references
        ViewController::__invoke(...$args)  // array_merge($data, $routeParameters) -> ResponseFactory::view()
            Factory::make() | first()       // creators; View::render(): composers; gatherData(): share
```

Consequences, each verified against v13.33.0 with Testbench:

1. **Read at dispatch, per request.** Every reference runs once per request per `data` key. Nothing is cached, so editing a reference needs no re-cache.
2. **Validation precedes every reference.** `app(DeclaredRequest::class)` runs `validateResolved()` (declarative-requests.md §1.1) before the first `Container::call()`. `?sort=bogus` returns 422 (JSON) or redirects, and no reference is resolved: the §3.5 test binds the reference to a throwing Closure.
3. **Bindings are already applied.** `SubstituteBindings` runs before the action, so `$args['user']` is the model `router.model` bound (declarative-router-bindings.md §1.1). Without it, `{user}` stays `"7"`, and a reference typed `User $user` throws `TypeError: ...::__invoke(): Argument #1 ($user) must be of type ...\User, string given`.
4. **`route:cache`-safe.** `AbstractRouteCollection::compile()` stores `'defaults' => $route->defaults` (line 181), `CompiledRouteCollection::newRoute()` restores them with `setDefaults()` (line 361), and `metadata` travels in the action. After `setCompiledRoutes(compile())`, the fixture route renders the same page.
5. **GET implies HEAD**, as `Router::view()` matches both: `HEAD /created` returns 201.

### 1.2 Properties

| Property | Type | Set by | Read by |
|---|---|---|---|
| `Route::$defaults` (public) | `array<string, mixed>` | `setDefaults()` (the `setDefaults` route key) | `RouteParameterBinder::replaceDefaults()`, `AbstractRouteCollection::compile()` |
| `Route::$action['metadata']` (public `$action`) | `array<string, mixed>` | `metadata()` (the `metadata` route key) | `getMetadata()`: `DeclaredView`, `DeclaredRequest` |
| `ViewController::$response` (protected) | `Illuminate\Contracts\Routing\ResponseFactory` | constructor (container-injected) | `__invoke()` |

### 1.3 Public methods

**Declaration targets.** Together they define the shape of `setDefaults`:

| Method | Signature | Effect |
|---|---|---|
| `Router::view` | `($uri, $view, $data = [], $status = 200, array $headers = []): Route` | `match(['GET', 'HEAD'], $uri, ViewController)->setDefaults(['view', 'data', 'status', 'headers'])` |
| `ViewController::__invoke` | `(...$args)` | removes `view`, `data`, `status`, `headers` from `$args`, merges the rest (the route parameters) over `$args['data']`, returns `$this->response->view($args['view'], $args['data'], $args['status'], $args['headers'])` |
| `ResponseFactory::view` | `($view, $data = [], $status = 200, array $headers = []): Response` | `is_array($view)` → `Factory::first($view, $data)`, else `Factory::make($view, $data)`; then `make($content, $status, $headers)` |

**Not declaration targets**

| Method | Why no key |
|---|---|
| `ViewController::callAction` | plumbing: `$this->{$method}(...$parameters)` keeps the names. `Controller::callAction()` uses `array_values()` |
| `Router::view()`'s `is_array($status)` overload | resolved inside `Router::view()` before `setDefaults()`. A route default is already normalized, so `headers` is its own key |
| `Router::redirect` / `permanentRedirect` (`RedirectController`) | not a view; roadmap Phase 0 |
| `ResponseFactory::json` / `make` / `noContent` / `download` | not the request → view path (roadmap §6) |
| `Controller::middleware` / `getMiddleware` | the `middleware` route key covers it |

### 1.4 How `setDefaults` reaches the view

```php
// Router.php:287 — what the `setDefaults` key writes by hand
public function view($uri, $view, $data = [], $status = 200, array $headers = [])
{
    return $this->match(['GET', 'HEAD'], $uri, '\Illuminate\Routing\ViewController')
        ->setDefaults([
            'view' => $view,
            'data' => $data,
            'status' => is_array($status) ? 200 : $status,
            'headers' => is_array($status) ? $status : $headers,
        ]);
}

// RouteParameterBinder.php:102 — every default is a route parameter; a matched URL value wins
protected function replaceDefaults(array $parameters)
{
    foreach ($parameters as $key => $value) {
        $parameters[$key] = $value ?? Arr::get($this->route->defaults, $key);
    }
    foreach ($this->route->defaults as $key => $value) {
        if (! isset($parameters[$key])) {
            $parameters[$key] = $value;
        }
    }
    return $parameters;
}

// ControllerDispatcher.php:38
public function dispatch(Route $route, $controller, $method)
{
    $parameters = $this->resolveParameters($route, $controller, $method);    // parametersWithoutNulls(), keyed by name
    if (method_exists($controller, 'callAction')) {
        return $controller->callAction($method, $parameters);
    }
    return $controller->{$method}(...array_values($parameters));
}

// ViewController.php:32
public function __invoke(...$args)
{
    $routeParameters = array_filter($args, function ($key) {
        return ! in_array($key, ['view', 'data', 'status', 'headers']);
    }, ARRAY_FILTER_USE_KEY);

    $args['data'] = array_merge($args['data'], $routeParameters);             // route parameters win

    return $this->response->view($args['view'], $args['data'], $args['status'], $args['headers']);
}

// ViewController.php:55
public function callAction($method, $parameters)
{
    return $this->{$method}(...$parameters);                                   // string keys -> named arguments
}

// BoundMethod.php:122 — Container::call(), once per reference
protected static function getMethodDependencies($container, $callback, array $parameters = [])
{
    $dependencies = [];
    foreach (static::getCallReflector($callback)->getParameters() as $parameter) {
        static::addDependencyForCallParameter($container, $parameter, $parameters, $dependencies);   // by name, else make(Class)
    }
    return array_merge($dependencies, array_values($parameters));              // unmatched ones appended positionally
}
```

Consequences, each verified against v13.33.0 with Testbench:

1. **`ViewController` requires all four keys.** It reads `$args['data']`, `['status']` and `['headers']` unconditionally. A Phase 0 route without `status` fails with `ErrorException: Undefined array key "status"` (ViewController.php:43). `DeclaredView` supplies `Router::view()`'s defaults (§2.5), so only `view` stays required. Without it, the route fails with `ErrorException: Undefined array key "view"`.
2. **Route parameters beat `data`.** This is `array_merge($args['data'], $routeParameters)`. The fixture's `data: {user: shadowed}` renders the bound model's key, not `shadowed` (§3.4).
3. **A matched URL value beats a default of the same name** (`replaceDefaults()`). A `{view}`, `{data}`, `{status}` or `{headers}` path parameter replaces that argument, as it does for `Route::view()`. Don't give a path parameter one of those names.
4. **`view` may be a list.** `ResponseFactory::view()` passes an array to `Factory::first()`, so the first existing view renders: `[missing, plain]` renders `plain`. If none exists, it throws `InvalidArgumentException: None of the views in the given array exist.`
5. **`ViewController` passes a reference through as a string.** A Phase 0 route (`action: Illuminate\Routing\ViewController`) with `data: {posts: App\Queries\UserPosts}` renders the class name as text. Changing `action` to `DeclaredView` is the whole upgrade.
6. **Parameters match by name.** `Container::call()` gives `$user` the `{user}` value and `$request` the request by name, and `make()`s any other class-typed parameter:
   - `DeclaredRequest $req` is `make()`d again, so `validateResolved()` runs a second time: `prepareForValidation` runs twice. This is double validation, not the recursion of declarative-requests.md §2.2, because `DeclaredView` is not inside the request pipeline.
   - `Illuminate\Routing\Route $route` receives the matched route, which `Router::findRoute()` binds (Router.php:781).
   - Unmatched parameters are appended positionally, and PHP ignores extra arguments to a userland callable.
7. **A literal with `\` before any `@` is a reference.** `data: {dir: 'C:\temp'}` fails at the first request with `Error: Call to undefined function C:\temp()`. Such a literal must come from a reference or from `view.share`.
8. **Data precedence**, lowest to highest (declarative-view.md §1.4.1): `view.share` → `data` (literals and resolved references) → route parameters → `view.creator` → `view.composer`.

### 1.5 The PHP this replaces

```php
// app/Http/Controllers/UserPostsController.php
final class UserPostsController
{
    public function __invoke(User $user, PostFiltersRequest $request, UserPosts $posts, PostStats $stats): Response
    {
        return response()->view('users.posts', [
            'title' => 'Posts',
            'posts' => $posts($user, $request),
            'stats' => $stats->forUser($user),
            'user' => $user,
        ], 200, ['Cache-Control' => 'private']);
    }
}

// routes/web.php
Route::get('users/{user}/posts', UserPostsController::class);
```

```yaml
routes:
  - path: "users/{user}/posts"
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    middleware: [web]
    metadata: {request: post-filters}
    setDefaults:
      view: users.posts
      data:
        title: Posts
        posts: App\Queries\UserPosts
        stats: App\Queries\PostStats@forUser
      headers: {Cache-Control: private}
```

`user` needs no `data` entry, because route parameters reach the view by name (§1.4.2).

---

## 2. Manifest schema proposal

### 2.1 Design rule

> **A view route is `Router::view()` written as route keys.** `action` is the controller `Router::view()` registers. The `setDefaults` keys are `Router::view()`'s parameter names (`view`, `data`, `status`, `headers`). These are also `ViewController::__invoke()`'s `$args` keys and `ResponseFactory::view()`'s parameters. **`data` is `$data`: every key is a view variable, and every value is that variable, or a PHP reference (§2.2) that returns it.**

The rule adds no key, because `action`, `setDefaults` and `metadata` are existing `Route` keys (declarative-routing.md §2.4). It is the `requests` rule (declarative-requests.md §2.1): a key is Laravel's name, and a value is what Laravel receives, or a reference that returns it. A Phase 0 route becomes a `DeclaredView` route by changing only `action`.

**Why not a `views:` block** (a registry keyed by name, as `requests:` is):

1. `Router::view()` already defines the shape, and `Route::setDefaults()` already stores it cache-safely. A registry would duplicate both, and it would need a second handle (`metadata.view`) for one route.
2. `requests` is a registry because one request serves many routes with different actions. A view route's defaults belong to exactly one route. Reuse is a YAML anchor (§2.6).

**Why a reference per key, not one for all of `data`.** `$data` is an `array` in every signature in §1.3. A reference per key keeps `data` a map in every manifest. A string `data` would be a type `Router::view()` never takes. A whole-array computation is a `view.composer` (declarative-view.md §2.6).

### 2.2 Values

| Key (under `setDefaults`) | Value | Laravel does | Absent, `ViewController` | Absent, `DeclaredView` |
|---|---|---|---|---|
| `view` | view name \| `list<view name>` | `Factory::make()` \| `Factory::first()` | `ErrorException` | `ErrorException` |
| `data` | `map<variable, literal \| reference>` | view data, under the route parameters | `ErrorException` | `[]` |
| `status` | `int` | response status | `ErrorException` | `200` |
| `headers` | `map<name, string \| list<string>>` | response headers | `ErrorException` | `[]` |
| any other key | literal | a route parameter default (`replaceDefaults()`): reaches `data` and references by name | — | — |

**References.** A `data` value is a reference when it is a string whose class or function part, the text before the first `@`, contains `\`. This is `DeclaredRequest::rule()`'s test with `@` in place of `:` (declarative-requests.md §2.2). A method name never contains `\`, so a `\` after the `@` means a literal (`ops@corp\team`). Every other value (a string without `\`, a number, a bool, `null`, a map or a list) reaches the view as YAML decoded it. Only top-level `data` values are tested, and nested maps and lists pass untouched.

The forms are `DeclaredRequest`'s, and the same `Container::call()` resolves them:

| YAML | Resolved as |
|---|---|
| `App\Queries\UserPosts` | invokable class: `make()` + `__invoke()` |
| `App\Queries\PostStats@forUser` | `make()` + instance method |
| `App\Queries\Greeting::for` | static method |
| `App\Queries\user_posts` | namespaced function; load the file through Composer `autoload.files` |

**Parameters.** Every call receives the route parameters by name: bound models after `SubstituteBindings`, raw strings otherwise, and any extra `setDefaults` key. It also receives `$request`: the validated `DeclaredRequest` when the route declares `metadata.request`, otherwise the current `Illuminate\Http\Request`. Everything else is container-injected. Name the parameters after the route parameters and `$request` (§1.4.6).

**YAML.** Write references plain or single-quoted. `"App\Queries\UserPosts"` throws `ParseException: Found unknown escape character "\Q"`.

### 2.3 Full example

```yaml
router:
  model:
    user: App\Models\User                            # {user} -> User, in SubstituteBindings

view:
  share:
    brand: Tenant Console
  composer:
    App\View\Composers\CurrentTenant: '*'

requests:
  - name: post-filters
    rules:
      sort: [nullable, 'in:created_at,title']

routes:
  - path: "users/{user}/posts"
    methods: GET                                     # GET implies HEAD, as Router::view() matches both
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    name: users.posts
    middleware: [web]                                # SubstituteBindings: router.model binds {user}
    metadata:
      request: post-filters                          # validated before any data resolves; references get it as $request
    setDefaults:                                     # Router::view($uri, $view, $data, $status, $headers)
      view: users.posts                              # required; a list renders the first view that exists
      data:                                          # every key is a view variable
        title: Posts                                 # literal
        posts: App\Queries\UserPosts                 # make(UserPosts)(user: $user, request: $request)
        stats: App\Queries\PostStats@forUser         # make(PostStats)->forUser(user: $user)
      status: 200                                    # optional, default 200
      headers:                                       # optional, default []
        Cache-Control: private

  - path: "users/{user}/drafts"
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    middleware: [web]
    setDefaults:
      view: [users.drafts, users.posts]              # Factory::first()
      data:
        posts: App\Queries\UserDrafts                # no metadata.request: $request is Illuminate\Http\Request
```

`users.posts` receives, lowest to highest: `brand` (share) < `title`, `posts`, `stats` (data) < `user` (the model) < `tenant` (composer).

The references are ordinary classes. Nothing extends a package type:

```php
namespace App\Queries;

final class UserPosts
{
    // `$user` matches {user} by NAME and is the model router.model bound; `$request` is the validated DeclaredRequest
    public function __invoke(User $user, DeclaredRequest $request): LengthAwarePaginator
    {
        return $user->posts()->orderBy($request->validated('sort', 'created_at'))->paginate();
    }
}

final class PostStats
{
    public function forUser(User $user): int
    {
        return $user->posts()->count();
    }
}
```

### 2.4 Key → argument map

| YAML | `Router::view()` | `ViewController` `$args` | `ResponseFactory::view()` | `DeclaredView` does |
|---|---|---|---|---|
| `setDefaults.view` | `$view` | `view` | `$view` | nothing |
| `setDefaults.data` | `$data` | `data` | `$data` | `?? []`; each reference → `Container::call()` |
| `setDefaults.status` | `$status` | `status` | `$status` | `?? 200` |
| `setDefaults.headers` | `$headers` | `headers` | `$headers` | `?? []` |
| `setDefaults.factory` | — | — | — | one dynamic `Factory` dispatch ([declarative-view-factory.md](declarative-view-factory.md) §2); exclusive with `template`/`view` |
| `metadata.request` | — | — | — | `app(DeclaredRequest::class)` as `$request`, before any reference |

### 2.5 Implementation

`src/DeclaredView.php` is the whole implementation:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Http\Response;
use Illuminate\Routing\ViewController;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * A `Router::view()` route whose `data` values may be references.
 *
 * Reads the route's `setDefaults` as `Router::view()` writes them (`view`, `data`, `status`,
 * `headers`), resolves each namespaced `data` value through `Container::call()` with the route
 * parameters by name and `$request`, then renders through `ViewController`.
 *
 * @link docs/declarative-view-data.md
 */
class DeclaredView extends ViewController
{
    public function __invoke(mixed ...$args): Response
    {
        $args += ['data' => [], 'status' => 200, 'headers' => []];

        $parameters = [
            ...Arr::except($args, ['view', 'data', 'status', 'headers']),
            'request' => request()->route()->getMetadata('request') === null ? request() : app(DeclaredRequest::class),
        ];

        /** @var array<string, mixed> $data */
        $data = $args['data'];

        $args['data'] = array_map(
            static fn (mixed $value): mixed => is_string($value) && str_contains(Str::before($value, '@'), '\\')
                ? app()->call($value, $parameters)
                : $value,
            $data,
        );

        return parent::__invoke(...$args);
    }
}
```

Each line maps to Laravel:

| Line | Laravel counterpart |
|---|---|
| `$args += [...]` | `Router::view()`'s signature defaults. `+=` keeps every declared value |
| `Arr::except($args, [...])` | `ViewController::__invoke()`'s own filter: the route parameters |
| `getMetadata('request')` | the key `DeclaredRequest::declaration()` reads |
| `app(DeclaredRequest::class)` | Laravel's resolve-and-validate (`FormRequestServiceProvider`). It runs while `$parameters` is built, before `array_map()`, so validation comes first |
| `is_string(...) && str_contains(Str::before(...))` | §2.2's reference test |
| `app()->call($value, $parameters)` | `Container::call()`, as `DeclaredRequest::resolve()` calls it |
| `parent::__invoke(...$args)` | `ViewController`: merges the route parameters, calls `ResponseFactory::view()` |

Nothing else changes. `Manifest`, `Route` and `LaravelDeclarationProvider` are untouched. `registerRoutes()` already applies `setDefaults` and `metadata` with `$route->{$method}($value)` (declarative-routing.md §2.5). No DataModel is needed, because `Route::$setDefaults` already hydrates the map. Types:

- **`mixed ...$args`** types the parent's untyped variadic for phpstan level 9. It is the same type, so the override is valid.
- **`: Response`** narrows to the parent's documented `@return`.
- **The `@var`** follows `DeclaredRequest`'s style.

Departures from the roadmap draft (Phase 3), each smaller than the draft:

- **`$args`, not `app('router')->current()->parametersWithoutNulls()`.** `$args` already holds those parameters (ControllerDispatcher.php:57). The draft also passed `view`, `data`, `status` and `headers` to every reference by name, and `Arr::except()` removes them.
- **`Str::before($value, '@')`, not `Str::before(Str::before($value, '@'), '::')`.** A static reference's class part is namespaced either way, so the inner split changes no result.
- **Inline test, no private `isReference()`.** It has one caller.
- **`$args +=`, not `parent::__invoke(...[...$args, 'status' => $args['status'] ?? 200, ...])`.** It covers `data` too, which the draft defaulted separately.

### 2.6 Notes / non-goals

- **`DeclaredView` is public API**, like `DeclaredRequest`. It is not `final`, so an application can extend it. `bc-check` covers it once a SemVer tag exists; the repository has none yet, so `bc-check` skips.
- **`SubstituteBindings` is the binding opt-in** (declarative-router-bindings.md §2.6). `DeclaredView` type-hints no model, so implicit binding never runs (roadmap §1.1).
- **`metadata.request` is the validation opt-in.** Without it, nothing validates and `$request` is the base request. Then a reference typed `DeclaredRequest $request` fails with `TypeError: ... Argument #2 ($request) must be of type ZeroToProd\LaravelDeclaration\DeclaredRequest, Illuminate\Http\Request given`. A `metadata.request` that names an undeclared request throws `DeclaredRequest`'s own `LogicException` (declarative-requests.md §2.5).
- **Only top-level `data` values resolve**, as with `app.instance`. A reference nested in a map is a string.
- **No reference for the whole of `data`** (§2.1).
- **No Closure or `.php` references.** `setDefaults` must stay `route:cache`-safe (roadmap rule 6), and `Class@method` covers what a Closure would do.
- **Reuse is a YAML anchor.** `setDefaults: &posts {...}` on one route, and `setDefaults: {<<: *posts, status: 201}` on another. `symfony/yaml` resolves anchors and the merge key before `Route` hydrates.
- **Not a validation layer.** Values pass through as YAML decoded them, and every failure is Laravel's own, at the first request (§1.4). `laravel-declaration:validate` cannot require `view` for a `DeclaredView` route: `justinrainbow/json-schema` 6.13.0 does not enforce draft-07 `if`/`then` in this validator (verified). So the schema only documents the keys (§3.2).
- **One render source per route.** `template` > `view` is the inline-template precedence ([declarative-inline-template.md](declarative-inline-template.md)), and `factory` joins the pair as a third, mutually exclusive source: declaring `factory` beside `template` or `view` throws `LogicException` (declarative-view-factory.md §2.2).
- **Redirects and JSON stay out.** `RedirectController` is roadmap Phase 0, and `ResponseFactory::json()` is a roadmap §6 non-goal.

---

## 3. Implementation plan

1. **`src/DeclaredView.php`** (new): the class in §2.5.

2. **`manifest.schema.json`**: two description edits, no structural change.
   - `definitions.route.properties.setDefaults.description`: `"-> Route::setDefaults(). On a ViewController or DeclaredView action: Router::view()'s arguments `view`, `data`, `status`, `headers`. DeclaredView resolves a namespaced `data` value through Container::call()."`
   - `definitions.route.properties.metadata.properties.request.description`: `"The `name` of a declared request; the action must type-hint DeclaredRequest, or be DeclaredView."`

3. **`README.md`**. Make four edits:
   - In `## Manifest`, add `tests/Feature/DeclaredViewTest.php` to the test list.
   - In `## Manifest`, end the feature list with "[Routes](#routes), [Requests](#requests) and [View routes](#view-routes)."
   - In `## Requests`, replace "An action without a `DeclaredRequest` type-hint does not validate." with "An action without a `DeclaredRequest` type-hint does not validate, except `DeclaredView` ([View routes](#view-routes))."
   - Insert this section between `## Requests` and `## License`:

   ````markdown
   ## View routes

   A view route is [`Route::view()`](https://laravel.com/docs/routing#view-routes)
   written as route keys: `action` is the view controller, and `setDefaults` holds
   `Router::view()`'s arguments `view`, `data`, `status` and `headers`.
   `ZeroToProd\LaravelDeclaration\DeclaredView` extends Laravel's `ViewController`.
   `data`, `status` and `headers` default to `[]`, `200` and `[]`, as in
   `Router::view()`. Only `view` is required, and a list renders the first view
   that exists. See `tests/Feature/DeclaredViewTest.php` and
   [docs/declarative-view-data.md](docs/declarative-view-data.md).

   Every `data` key is a view variable. A value whose class or function part (the
   text before any `@`) contains `\` is a PHP reference, run through
   `Container::call()`: an invokable FQCN, `Class@method`, `Class::method` or a
   namespaced function. Every other value reaches the view as YAML decoded it. A
   reference receives the route parameters by **name**, plus `$request`. A
   parameter is a model once `router.model` or `router.bind` bound it, which needs
   `SubstituteBindings` (in the `web` and `api` groups). With
   `metadata: {request: <name>}`, `$request` is the `DeclaredRequest`, validated
   before any reference runs. Without it, `$request` is the current request. As in
   `ViewController`, route parameters also reach the view, and they override a
   `data` key of the same name.

   Complete structure:

   ```yaml
   routes:
     - path: "users/{user}/posts"
       methods: GET                                 # implies HEAD
       action: ZeroToProd\LaravelDeclaration\DeclaredView
       middleware: [web]                            # SubstituteBindings: {user} -> router.model
       metadata:
         request: post-filters                      # optional: validated first, passed as $request
       setDefaults:                                 # Router::view($uri, $view, $data, $status, $headers)
         view: users.posts                          # required; a list renders the first that exists
         data:
           title: Posts                             # literal
           posts: App\Queries\UserPosts             # make(UserPosts)(user: $user, request: $request)
           stats: App\Queries\PostStats@forUser     # make(PostStats)->forUser(user: $user)
         status: 200                                # optional, default 200
         headers: {Cache-Control: private}          # optional, default []
   ```
   ````

4. **Fixtures.** PHP classes in `tests/Fixtures/App/View/Data/`, namespace `ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Data`. Each file starts with `<?php` and `declare(strict_types=1);`, as the other fixtures do:

   ```php
   // UserPosts.php
   use ZeroToProd\LaravelDeclaration\DeclaredRequest;
   use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\User;

   /** `data: {posts: UserPosts}`: invokable; `$user` is the bound {user}, `$request` the validated DeclaredRequest. */
   final class UserPosts
   {
       /** @return array{user: mixed, sort: mixed} */
       public function __invoke(User $user, DeclaredRequest $request): array
       {
           return ['user' => $user->getKey(), 'sort' => $request->validated('sort', 'created_at')];
       }
   }

   // PostStats.php
   use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\User;

   /** `data: {stats: PostStats@forUser}`: make() + instance method, parameters by name. */
   final class PostStats
   {
       public function forUser(User $user): int
       {
           $id = $user->getKey();

           return is_int($id) ? $id * 10 : 0;
       }
   }

   // Greeting.php
   use Illuminate\Http\Request;

   /** `data: {greeting: Greeting::for}`: static method; `$name` is the raw {name}, `$request` the base request. */
   final class Greeting
   {
       public static function for(string $name, Request $request): string
       {
           return "hello $name at {$request->path()}";
       }
   }
   ```

   `User` is the Phase 1 fixture: it resolves without a database (declarative-router-bindings.md §3.7). Views go in `tests/Fixtures/App/View/views/`, which `TestCase::copyApplicationFiles()` already copies to `resources/declared-views`. So `TestCase` is unchanged:

   | File | Content |
   |---|---|
   | `users/posts.blade.php` | `{{ $brand }}\|{{ $title }}\|{{ $posts['user'] }}:{{ $posts['sort'] }}\|{{ $stats }}\|{{ $user->getKey() }}` |
   | `plain.blade.php` | `{{ $greeting }}\|{{ $name }}\|{{ $email }}` |

   The `created` route reuses the existing `greeting.blade.php` (`base`).

   `tests/Fixtures/manifest/view-data.yml`. It is a separate file, so every other test's routes stay unchanged. It joins stages 6, 7, 9 and 10 in one route:

   ```yaml
   # yaml-language-server: $schema=./../../../manifest.schema.json

   router:
     model:
       user: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\User

   view:
     addLocation: [resources/declared-views]
     share:
       brand: Tenant Console

   requests:
     - name: post-filters
       rules:
         sort: [nullable, 'in:created_at,title']

   routes:
     - path: "users/{user}/posts"
       methods: GET
       action: ZeroToProd\LaravelDeclaration\DeclaredView
       middleware: [Illuminate\Routing\Middleware\SubstituteBindings]
       metadata:
         request: post-filters
       setDefaults:
         view: users.posts
         data:
           title: Posts
           posts: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Data\UserPosts
           stats: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Data\PostStats@forUser
           user: shadowed
         status: 200
         headers: {Cache-Control: private}

     - path: "greet/{name}"
       methods: GET
       action: ZeroToProd\LaravelDeclaration\DeclaredView
       setDefaults:
         view: [missing, plain]
         data:
           greeting: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Data\Greeting::for
           email: ada@example.com

     - path: created
       methods: GET
       action: ZeroToProd\LaravelDeclaration\DeclaredView
       setDefaults:
         view: greeting
         status: 201
         headers: {X-Declared: 'yes'}
   ```

   The middleware is named directly, not `web`, so the tests need no `APP_KEY` or session (declarative-router-bindings.md §2.6).

5. **Tests**: `tests/Feature/DeclaredViewTest.php`. These exact tests and fixtures pass, reproduced in a scratch copy of this repository:

   ```php
   <?php

   declare(strict_types=1);

   use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Data\UserPosts;

   $manifest = __DIR__.'/../Fixtures/manifest/view-data.yml';

   it('renders literals, references and route parameters with the declared status and headers', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);

       $this->get('/users/7/posts?sort=title')
           ->assertOk()
           ->assertHeader('Cache-Control', 'private')
           ->assertSeeText('Tenant Console|Posts|7:title|70|7');
   });

   it('validates the declared request before any data resolves', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);
       app()->bind(UserPosts::class, fn () => throw new LogicException('resolved'));

       $this->getJson('/users/7/posts?sort=bogus')
           ->assertUnprocessable()
           ->assertJsonValidationErrors('sort');
   });

   it('binds a model the action never type-hints', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);

       $this->get('/users/none/posts')->assertNotFound();
   });

   it('passes route parameters by name and the base request without metadata.request', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);

       $this->get('/greet/ada')
           ->assertOk()
           ->assertSeeText('hello ada at greet/ada|ada|ada@example.com');
   });

   it('defaults data, status and headers as Router::view() does', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);

       $this->get('/created')->assertCreated()->assertHeader('X-Declared', 'yes')->assertSeeText('base');
   });
   ```

   What each assertion pins:
   - `Posts` is a literal.
   - `7:title` is an invokable reference, which received the bound model and the validated request.
   - `70` is a `Class@method` reference.
   - The final `7` is the route parameter overriding `data.user: shadowed` (§1.4.2).
   - `greet/ada` covers a `Class::method` reference, a raw route parameter, the base request, a list `view` and a string literal containing `@`.
   - `created` covers the `$args +=` defaults.

   Two mutations were checked, and each fails the tests. Replacing the `request` ternary with `request()` fails tests 1–2. Removing `$args +=` fails tests 4–5 with `Undefined array key`.

   Also add to `tests/Feature/ValidateCommandTest.php`, before the "reports each schema violation" test:

   ```php
   test('laravel-declaration:validate accepts DeclaredView routes', function (): void {
       $this->artisan('laravel-declaration:validate', ['--manifest' => __DIR__.'/../Fixtures/manifest/view-data.yml'])
           ->expectsOutputToContain('is valid')
           ->assertSuccessful();
   });
   ```

6. **`composer check`**. In the scratch copy, with steps 1, 2, 4 and 5 applied:
   - `pint --test`, `rector process --dry-run` and `phpstan analyse` (level 9) pass.
   - `pest --coverage --min=100` passes 121 tests at 100.0%, with `DeclaredView` at 100.0%.
   - `bc-check` prints "no SemVer release tags found, nothing to compare against. Skipping."

   The new public API is `DeclaredView::__invoke()`, which is additive. The MCP `api` tool reflects `src/`, so it lists `DeclaredView` (class summary plus `public function __invoke(mixed ...$args): Illuminate\Http\Response;`) with no code change. The `readme` tool serves the updated README.

7. **`docs/declarative-request-to-view-roadmap.md`**. Make five edits:
   - In §1, set stage 9 to "done".
   - In Phase 3, replace the PHP `DeclaredView` block with "See [declarative-view-data.md](declarative-view-data.md) §2.5."
   - In Phase 3's decisions, replace "**String = reference only when namespaced**, the rule-list rule `DeclaredRequest::rule()` already ships." with "**String = reference only when namespaced**: its text before the first `@` contains `\` (`DeclaredRequest::rule()`'s test, with `@` for `:`)."
   - In Phase 3's decisions, add "**No DataModel, no provider loop.** `setDefaults` and `metadata` are existing route keys that `registerRoutes()` already applies, so §3's 'one DataModel + one provider loop' and §5 items 1–2 do not apply."
   - In Phase 3's deliverables, change `tests/Fixtures/manifest/views.yml` to `tests/Fixtures/manifest/view-data.yml`, and "README `Views` section" to "README `View routes` section". Add "`manifest.schema.json` `setDefaults` / `metadata.request` descriptions".

8. **Other docs**. Make two edits:
   - In `docs/declarative-requests.md` §2.5, append to the paragraph that begins "The type-hint is Laravel's validation trigger": "`DeclaredView` is the exception: it resolves `DeclaredRequest` itself when its route declares `metadata.request` ([declarative-view-data.md](declarative-view-data.md) §2.5)."
   - In `docs/declarative-view.md` §2.6, end the "Phase 3" bullet with "See [declarative-view-data.md](declarative-view-data.md)."

---

### Sources

1. `__invoke()` filter, merge and `ResponseFactory::view()` call, and the named `callAction()`: [Routing/ViewController.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/ViewController.php)
2. `view()` writes `setDefaults(['view', 'data', 'status', 'headers'])`; `findRoute()` binds `Route::class`: [Routing/Router.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/Router.php)
3. `replaceDefaults()`, where defaults become route parameters: [Routing/RouteParameterBinder.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/RouteParameterBinder.php)
4. `dispatch()` and `resolveParameters()` via `parametersWithoutNulls()`: [Routing/ControllerDispatcher.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/ControllerDispatcher.php); positional `callAction()`: [Routing/Controller.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/Controller.php)
5. `setDefaults()`, `getMetadata()`, `parametersWithoutNulls()`, GET adds HEAD: [Routing/Route.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/Route.php)
6. `view()` sends an array to `first()`: [Routing/ResponseFactory.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/ResponseFactory.php); `first()` and `make()`: [View/Factory.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/View/Factory.php)
7. `call()`, `callClass()`, `getMethodDependencies()`, `addDependencyForCallParameter()` (name first, leftovers appended): [Container/BoundMethod.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Container/BoundMethod.php)
8. Route cache stores and restores `defaults`: [AbstractRouteCollection.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/AbstractRouteCollection.php), [CompiledRouteCollection.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/CompiledRouteCollection.php)
9. View routes: [docs/repos/laravel/docs/routing.md](repos/laravel/docs/routing.md#view-routes), [laravel.com/docs/routing#view-routes](https://laravel.com/docs/routing#view-routes)
10. YAML anchors and the merge key, `"App\Queries\UserPosts"` → `ParseException`, a flow list `view`: `symfony/yaml` v8.1.6, verified with `Yaml::parse()` in this repository
11. draft-07 `if`/`then` not enforced: `justinrainbow/json-schema` 6.13.0, verified with `JsonSchema\Validator` (inline schema and `$ref`, as `ValidateCommand` calls it)
12. Every consequence in §1.1 and §1.4, the §2.5 class, the §3.4–§3.5 fixtures and tests, the mutations and the §3.6 `composer check` results: reproduced with Testbench in a scratch copy of this repository (scratch files, not committed)
