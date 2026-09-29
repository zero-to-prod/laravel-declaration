# Declarative Router Bindings — `Router::model()` / `Router::bind()` & Manifest Schema

Source of truth: `vendor/laravel/framework/src/Illuminate/Routing/Router.php` (`laravel/framework` v13.33.0), with `Illuminate/Routing/RouteBinding.php`, `Illuminate/Routing/Middleware/SubstituteBindings.php`, `Illuminate/Routing/ImplicitRouteBinding.php`, `Illuminate/Foundation/Configuration/Middleware.php`, `Illuminate/Foundation/Http/Kernel.php`, `Illuminate/Database/Eloquent/Model.php` and `Illuminate/Broadcasting/Broadcasters/Broadcaster.php`.

Goal: add `model` and `bind` to the existing `router:` block ([declarative-router.md](declarative-router.md)). Each **key is a `Router` method name**, each **map entry is one call**: its key is `$key`, its value the second argument (`$class` / `$binder`). This is stage 6 of [declarative-request-to-view-roadmap.md](declarative-request-to-view-roadmap.md) §1: "Parameter → model / value". A declared action (`ViewController`, `DeclaredView`) type-hints nothing, so implicit binding never runs (roadmap §1.1). Explicit binding needs no type-hint. The provider adds two loops beside `pattern`'s (§2.5) and resolves nothing: Laravel's `RouteBinding` resolves the strings.

---

## 1. Public API of `Router::class` (explicit binding)

### 1.1 Lifecycle position (when the declaration runs, when it is read)

```php
$app->boot();                        // each provider's boot(), in registration order:
                                     //   LaravelDeclarationProvider::boot()
                                     //       registerRouter()   pattern -> model -> bind   <- $binders[$key] = Closure, HERE
                                     //       registerProviders(), registerRoutes()
                                     //   AppServiceProvider::boot()   where Laravel's docs call Route::model() / Route::bind()
                                     //   RouteServiceProvider         routes/*.php

// per request: Router::findRoute() -> route middleware, sorted by Kernel::$middlewarePriority:
//   ... StartSession, AuthenticatesRequests, ThrottleRequests ...
//   SubstituteBindings::handle()
//       Router::substituteBindings($route)          each {name} with isset($binders[$name]): setParameter($name, $binder($value, $route))
//       Router::substituteImplicitBindings($route)  action type-hints only; skips a value that is already UrlRoutable
//       catch ModelNotFoundException                -> $route->getMissing()($request, $e), else rethrow (404)
//   Authorize                                       `can` sees the bound model
//   ControllerDispatcher -> action                  DeclaredRequest resolves here: $request->route('user') is the model
```

A binder is **read at dispatch**, not when a route is created. That is the opposite of `pattern` (declarative-router.md §1.4). Consequences, each verified against v13.33.0:

1. **Every route with `{key}` is bound**, whenever and wherever it was created: the manifest's `routes`, `routes/*.php`, routes of providers that booted earlier, and cached routes. A `bind()` made after `addRoute()` still applies.
2. **Only where `SubstituteBindings` runs.** It is the only caller of `substituteBindings()`. The `web` and `api` groups include it (`Foundation/Configuration/Middleware.php:491`, `:498`). Without it, `{user}` stays the string `"7"`.
3. **Last write per key wins, for every route.** `bind()` assigns `$binders[$key]`. A hand-written `Route::model('user', ...)` in `AppServiceProvider::boot()`, or in a declared provider, runs later and replaces the manifest's binder app-wide.
4. **`boot()`, as for `pattern`.** It is Laravel's documented place (routing.md, "Explicit Binding"). The router is a base binding, so `register()` would also work. Keeping one apply point keeps §2.5 one method.

### 1.2 Properties

| Property | Type | Set by | Read by |
|---|---|---|---|
| `Router::$binders` (protected) | `array<string, Closure>` | `bind()` / `model()` | `substituteBindings()`, `getBindingCallback()` (broadcasting, §1.4.6) |

### 1.3 Public methods

**Declaration targets**

| Method | Signature | Effect |
|---|---|---|
| `model` | `($key, $class, ?Closure $callback = null): void`. `@param string $key, string $class` | `bind($key, RouteBinding::forModel($container, $class, $callback))` |
| `bind` | `($key, $binder): void`. `@param string $key, string\|callable $binder` | `$binders[str_replace('-', '_', $key)] = RouteBinding::forCallback($container, $binder)` |

**Not declaration targets**

| Method | Why no key |
|---|---|
| `model()`'s third argument | `?Closure`: a string is a `TypeError`, and YAML has no Closure. §2.6 |
| `getBindingCallback` | runtime read-back |
| `substituteBindings` / `substituteImplicitBindings` | runtime; `SubstituteBindings` calls them |
| `substituteImplicitBindingsUsing` | takes a `callable` that replaces implicit binding app-wide. Not stage 6 |

### 1.4 How a string resolves

```php
// RouteBinding.php:18 — bind()
public static function forCallback($container, $binder)
{
    return is_string($binder) ? static::createClassBinding($container, $binder) : $binder;
}

// RouteBinding.php:34
protected static function createClassBinding($container, $binding)
{
    return function ($value, $route) use ($container, $binding) {
        [$class, $method] = Str::parseCallback($binding, 'bind');     // splits on `@` only; default method `bind`
        $callable = [$container->make($class), $method];
        return $callable($value, $route);                            // positional, no method injection
    };
}

// RouteBinding.php:58 — model()
public static function forModel($container, $class, $callback = null)
{
    return function ($value, $route = null) use ($container, $class, $callback) {
        if (is_null($value)) return;
        $instance = $container->make($class);
        $method = $route?->allowsTrashedBindings() && $instance::isSoftDeletable()
            ? 'resolveSoftDeletableRouteBinding' : 'resolveRouteBinding';
        if ($model = $instance->{$method}($value)) return $model;    // ONE argument: no binding field
        if ($callback instanceof Closure) return $callback($value);
        throw (new ModelNotFoundException)->setModel($class);
    };
}
```

Consequences, each verified against v13.33.0 with Testbench:

1. **`bind` accepts exactly two string forms.** `Class` calls `make(Class)->bind($value, $route)`. `Class@method` calls `make(Class)->method($value, $route)`. The constructor is container-injected; the method is not.
   - An invokable class without `bind()` fails: `Error: Call to undefined method Invokable::bind()`. Unlike every other reference in this package, a bare class here does **not** mean `__invoke`.
   - `Class::method` fails: `BindingResolutionException: Target class [Teams::stat] does not exist.`
   - An enum (`Status@from`) fails: `BindingResolutionException: Target [Status] is not instantiable.`
2. **`model` ignores `{key:field}` and scoped bindings.** `resolveRouteBinding($value)` gets no `$field`, so it uses `getRouteKeyName()`, and it never consults `parentOfParameter()`. `users/{user:slug}` looks the user up by primary key. A route's `withTrashed` **is** honored.
3. **Not found → `missing` or 404.** A `null` from `resolveRouteBinding()` throws `ModelNotFoundException`, and so does a binder's `firstOrFail()`. `SubstituteBindings` hands it to the route's `missing` key (declarative-routing.md §2.4), else rethrows (404).
4. **Absent optional parameter: no binder runs.** An absent `{post?}` is not in `$route->parameters()`, so `substituteBindings()` never reaches it, and the action gets its default.
5. **Explicit before implicit.** `ImplicitRouteBinding.php:36` skips a value that is already `UrlRoutable`, so a type-hinted controller keeps working and the explicit binder wins.
6. **Broadcasting reads the same binders.** `Broadcaster::resolveExplicitBindingIfPossible()` (line 227) calls `getBindingCallback($key)($value)` with one argument. A `model` binder works for a channel's `{user}`. A `bind` string binder throws `ArgumentCountError`, because `createClassBinding()`'s Closure requires `$route`. This is Laravel's behavior, and a hand-written `Route::bind('x', Binder::class)` has it too.

Every failure above is Laravel's own exception, thrown at the first request that matches the route (a 500).

### 1.5 The PHP this replaces

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    Route::model('user', User::class);
    Route::bind('post', PostBinder::class);
    Route::bind('team', 'App\Routing\Teams@bySlug');
}
```

```yaml
router:
  model:
    user: App\Models\User
  bind:
    post: App\Routing\PostBinder
    team: App\Routing\Teams@bySlug
```

---

## 2. Manifest schema proposal

### 2.1 Design rule

Unchanged from declarative-router.md §2.1: **every key in `router:` is a `Router` method name, and every value is that method's argument(s).** A map is one call per entry, and its key is the first argument.

### 2.2 Values

| Key | Value | Laravel does | Rejected forms |
|---|---|---|---|
| `model` | class-string with `resolveRouteBinding()` (an Eloquent `Model`, or any `UrlRoutable`) | `make($class)->resolveRouteBinding($value)` | anything else fails at first match |
| `bind` | `Class` or `Class@method` | `make(Class)->{method ?? 'bind'}($value, $route)` | invokable-only class, `Class::method`, function, enum, `.php` file (§1.4.1, §2.6) |

Write references plain or single-quoted: `"App\Models\User"` throws `ParseException: Found unknown escape character "\M"`.

A binder is an ordinary class:

```php
namespace App\Routing;

use App\Models\Post;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route;

final class PostBinder
{
    public function __construct(private PostRepository $posts) {}   // make(): constructor DI

    public function bind(string $value, Route $route): Post         // positional ($value, $route), as Laravel calls it
    {
        return $this->posts->findBySlug($value)
            ?? throw (new ModelNotFoundException)->setModel(Post::class);   // -> route `missing`, else 404
    }
}
```

A binder is also how a backed enum binds without a type-hint (`return Status::from($value);`). Laravel has no explicit enum binder.

### 2.3 Full example

```yaml
router:                                          # ——— Router surface ———
  pattern:
    user: '[0-9]+'                               # declarative-router.md
  model:                                         # -> model($key, $class), one call per entry
    user: App\Models\User                        # {user} -> User::resolveRouteBinding($value); 404 when null
  bind:                                          # -> bind($key, $binder), one call per entry
    post: App\Routing\PostBinder                 # -> make(PostBinder)->bind($value, $route)
    team: App\Routing\Teams@bySlug               # -> make(Teams)->bySlug($value, $route)

routes:
  - path: "users/{user}"
    methods: GET
    action: Illuminate\Routing\ViewController    # hints nothing: only explicit binding binds {user}
    middleware: [web]                            # web includes SubstituteBindings
    setDefaults: {view: users.show, data: {}, status: 200, headers: {}}   # $user reaches the view by name (roadmap §1.2)

  - path: "teams/{team}/posts/{post}"
    methods: GET
    action: App\Http\Controllers\PostController
    middleware: [api]
    can: {ability: view, models: post}           # Authorize runs after SubstituteBindings ($middlewarePriority)
    missing: App\Http\Handlers\PostMissing       # PostBinder's ModelNotFoundException lands here
```

### 2.4 Key → method → signature map (whole `router:` block)

| YAML key | `Router` method | Value shape (YAML) | Dispatch (§2.5) | Read | Absent → |
|---|---|---|---|---|---|
| `pattern` | `pattern` | `map<param, regex>` | `$Router->pattern($key, $pattern)` | route creation | no global pattern |
| `model` | `model` | `map<param, class-string>` | `$Router->model($key, $class)` | dispatch | implicit binding only |
| `bind` | `bind` | `map<param, Class \| Class@method>` | `$Router->bind($key, $binder)` | dispatch | implicit binding only |

Fixed order is `pattern` → `model` → `bind`. The same key under both `model` and `bind` gives `bind` (last write, §1.1.3). An unknown key throws `LogicException` when the manifest is read. `patterns` is still unknown.

### 2.5 Registration algorithm (for the provider)

`src/Router.php` gains two properties, each decorated with the `Key` attribute. The `pre` hook stays on `$pattern`: it runs against the whole block even when `pattern` is absent (declarative-router.md §3.1).

```php
public const string model = 'model';

/** @var array<string, string> */
#[Key, Describe([Describe::default => []])]
public array $model;

public const string bind = 'bind';

/** @var array<string, string> */
#[Key, Describe([Describe::default => []])]
public array $bind;
```

`LaravelDeclarationProvider::registerRouter()` gains two loops. `boot()` is unchanged:

```php
private function registerRouter(Manifest $Manifest, Router $Router): void
{
    foreach ($Manifest->router->pattern ?? [] as $key => $pattern) {
        $Router->pattern($key, $pattern);
    }

    foreach ($Manifest->router->model ?? [] as $key => $class) {
        $Router->model($key, $class);
    }

    foreach ($Manifest->router->bind ?? [] as $key => $binder) {
        $Router->bind($key, $binder);
    }
}
```

That is the whole implementation. The value is the argument, with no wrapper and no reference resolution. The provider still never names the DataModel (declarative-router.md §2.5), and each loop is the literal Laravel call, so phpstan checks it against `Router`'s signature.

### 2.6 Notes / non-goals

- **`SubstituteBindings` is the opt-in.** Use `middleware: [web]`, `[api]`, or `[Illuminate\Routing\Middleware\SubstituteBindings]`. `web` also runs `EncryptCookies` and `StartSession`, which need `APP_KEY`. The fixture names the middleware directly, so its tests depend on nothing else.
- **Global by parameter name.** `model: {user: ...}` binds `{user}` on every route that runs `SubstituteBindings`, vendor packages' routes included. Choose keys that are specific enough.
- **No third `model()` argument.** It is `?Closure`, and it returns a fallback *value*. For a fallback *response*, use the route's `missing`. For a fallback value, write a `bind` class that returns it.
- **No `.php` Closure binder.** This keeps `Router` a pure pass-through (roadmap Phase 1). `Class@method` covers what a Closure would do.
- **No `{key:field}` / scoping through `model`** (§1.4.2). For those, keep implicit binding (a type-hinted action), or use a `bind` class that queries the field. To bind by another column, declare `getRouteKeyName` on the model's `models` entry ([declarative-model.md](declarative-model.md) §1.4.3).
- **`route:cache`-safe, and no re-cache needed.** Binders live on the `Router`, not on routes, and `registerRouter()` re-applies them on every boot. Editing `model` or `bind` needs no `route:cache`, unlike `pattern`.
- **Not a validation layer.** Values pass through as YAML decoded them, and failures are Laravel's own (§1.4). `laravel-declaration:validate` catches the `bind` forms that can never work (`::`, `.php`, a trailing `@`) through the schema pattern (§3.3).

---

## 3. Implementation plan

1. **`src/Router.php`**. Add the two consts and properties, and extend `keys`, exactly as in §2.5. Keep them after `$pattern`, in key order.

2. **`../src/LaravelDeclarationProvider.php`**. Replace `registerRouter()` with §2.5. Nothing else changes.

3. **`manifest.schema.json`**. Replace `definitions.router` with:

   ```json
   "router": {
     "description": "Illuminate\\Routing\\Router methods: every key is a method name, its value the argument(s). Applied first in boot(), before `providers` and `routes`. An unknown key throws LogicException.",
     "type": ["object", "null"],
     "additionalProperties": false,
     "properties": {
       "pattern": {
         "description": "-> pattern($key, $pattern), one call per entry: a global `where`, merged into every route created afterwards (URI and domain parameters). A route's own `where` wins. Single-quote the regex: `[` starts a YAML list.",
         "type": "object",
         "additionalProperties": { "type": "string" }
       },
       "model": {
         "description": "-> model($key, $class), one call per entry: {key} resolves through $class::resolveRouteBinding($value) in SubstituteBindings (`web`/`api` groups), on every route. 404 when null; a route's `missing` handles it. Ignores {key:field} and scopeBindings.",
         "type": "object",
         "additionalProperties": { "$ref": "#/definitions/classString" }
       },
       "bind": {
         "description": "-> bind($key, $binder), one call per entry: `Class` (method `bind`) or `Class@method`, make()d and called ($value, $route) in SubstituteBindings. Not an invokable, not `Class::method`.",
         "type": "object",
         "additionalProperties": {
           "type": "string",
           "pattern": "^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*(@[A-Za-z_][A-Za-z0-9_]*)?$"
         }
       }
     }
   },
   ```

   The `bind` pattern is `classString`'s with an optional `@method`. Checked with `JsonSchema\Validator`, it accepts `App\Routing\PostBinder` and `App\Routing\Teams@bySlug`, and it rejects `App\Routing\Teams::bySlug`, `App\Routing\Teams@` and `app/binders/post.php`.

4. **`README.md`, `## Router`**. Change the link sentence to "See `tests/Feature/RouterRegistrationTest.php`, [docs/declarative-router.md](docs/declarative-router.md) and [docs/declarative-router-bindings.md](docs/declarative-router-bindings.md)." Insert this paragraph after the `pattern` paragraph:

   ```markdown
   `model` is `Router::model($key, $class)` and `bind` is `Router::bind($key, $binder)`:
   explicit route binding. Laravel runs every binder in the `SubstituteBindings`
   middleware, which the `web` and `api` groups include. So `{key}` is bound on
   every route that runs it, whenever the route was created and whether or not
   its action type-hints the parameter. Without it, the parameter stays a string.
   `model` resolves `$class::resolveRouteBinding($value)`. A `null` result is a 404,
   which a route's `missing` handles. It ignores `{key:field}` and `scopeBindings`.
   `bind` takes `Class` (method `bind`) or `Class@method`. Laravel `make()`s it and
   calls it with `($value, $route)`. An invokable-only class or `Class::method`
   fails. A later `Route::model()` or `Route::bind()` for the same key replaces
   the manifest's for every route, for example in `AppServiceProvider::boot()`.
   ```

   Replace the structure block with:

   ````markdown
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
   ```
   ````

5. **`docs/declarative-router.md`**. Make four edits. In the Goal, replace "`model` and `bind` (stage 6) share this block and come in the next migration; until then they throw as unknown keys (§2.6)." with "`model` and `bind` (stage 6): [declarative-router-bindings.md](declarative-router-bindings.md)." In the §1.3 `model` / `bind` row, replace "stage 6, the next migration" with "declaration targets: declarative-router-bindings.md §1.3". In §2.4, replace "`patterns`, `model` and `bind` are unknown keys today." with "`patterns` is an unknown key; `model` / `bind`: declarative-router-bindings.md §2.4." In §2.6, replace the `model` / `bind` bullet with "- **`model` / `bind` (stage 6)**: [declarative-router-bindings.md](declarative-router-bindings.md)."

6. **`docs/declarative-request-to-view-roadmap.md`**. In §1, set stage 4 and stage 6 to "done". Add `docs/declarative-router-bindings.md` to the Phase 1 deliverables.

7. **Fixtures**, in `tests/Fixtures/App/Routing/` (all checked at phpstan level 9 with the repository's `phpstan.neon`):

   ```php
   // User.php
   namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing;

   use Illuminate\Database\Eloquent\Model;

   /** Resolves without a database: a numeric value is its id, anything else is not found. */
   final class User extends Model
   {
       protected $guarded = [];

       public function resolveRouteBinding(mixed $value, mixed $field = null): ?self
       {
           return is_numeric($value) ? new self(['id' => (int) $value]) : null;
       }
   }

   // PostBinder.php
   /** `bind: {post: PostBinder}`: Laravel calls the default method, `bind`. */
   final class PostBinder
   {
       public function bind(string $value): string
       {
           return "post-$value";
       }
   }

   // Teams.php
   use Illuminate\Routing\Route;

   /** `bind: {team: Teams@bySlug}`: called positionally with ($value, $route). */
   final class Teams
   {
       public function bySlug(string $value, Route $route): string
       {
           return "$value@{$route->uri()}";
       }
   }

   // ParametersController.php
   use Illuminate\Routing\Route;

   /** Hints no route parameter, so only an explicit binding can bind one. */
   final class ParametersController
   {
       /** @return array<string, mixed> */
       public function __invoke(Route $route): array
       {
           return $route->parameters();
       }
   }
   ```

   Each file starts with `<?php`, `declare(strict_types=1);` and the namespace, as the other fixtures do. `Route $route` is the matched route, because `Router::findRoute()` binds it as a container instance (Router.php:781).

   Append to `tests/Fixtures/manifest/router.yml`. The paths avoid `posts/{id}`, which would match first:

   ```yaml
   # under router:
     model:
       user: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\User
     bind:
       post: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\PostBinder
       team: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\Teams@bySlug

   # under routes:
     - path: "users/{user}"
       methods: GET
       action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\ParametersController
       middleware: [Illuminate\Routing\Middleware\SubstituteBindings]

     - path: "missing/{user}"
       methods: GET
       action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\ParametersController
       middleware: [Illuminate\Routing\Middleware\SubstituteBindings]
       missing: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\UserMissingHandler

     - path: "raw/{user}"
       methods: GET
       action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\ParametersController

     - path: "bound/{post}"
       methods: GET
       action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\ParametersController
       middleware: [Illuminate\Routing\Middleware\SubstituteBindings]

     - path: "teams/{team}"
       methods: GET
       action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\ParametersController
       middleware: [Illuminate\Routing\Middleware\SubstituteBindings]
   ```

   The existing `ValidateCommandTest` case for `router.yml` now exercises the new schema entries too.

8. **Tests**, appended to `tests/Feature/RouterRegistrationTest.php`. The expected responses were reproduced against Laravel's router with these exact fixtures:

   ```php
   it('binds a model to a parameter the action does not type-hint', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);

       $this->get('/users/7')->assertExactJson(['user' => ['id' => 7]]);
       $this->get('/users/none')->assertNotFound();
   });

   it('lets a route missing handle a failed model binding', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);

       $this->get('/missing/none')->assertNotFound()->assertExactJson(['missing' => true]);
   });

   it('leaves the parameter raw without SubstituteBindings', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);

       $this->get('/raw/7')->assertExactJson(['user' => '7']);
   });

   it('forwards a class binder to its bind method', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);

       $this->get('/bound/5')->assertExactJson(['post' => 'post-5']);
   });

   it('forwards a Class@method binder the value and the route', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);

       $this->get('/teams/acme')->assertExactJson(['team' => 'acme@teams/{team}']);
   });
   ```

   Rename `'sets no pattern without a router block'` to `'sets no pattern or binder without a router block'`, and chain `->and(app(Router::class)->getBindingCallback('user'))->toBeNull()`.

9. **`composer check`**: lint, rector, phpstan, 100% coverage, bc-check. The new loops are covered by the fixture routes, and the absent block by the `requests.yml` case. `Router::$model` / `$bind` are additive public API. The MCP `api` tool reflects `src/` with no change.

---

### Sources

1. `bind()`, `model()`, `getBindingCallback()`, `substituteBindings()`, `performBinding()`, `findRoute()` — [Router.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/Router.php)
2. `forCallback()`, `createClassBinding()`, `forModel()` — [RouteBinding.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/RouteBinding.php)
3. Binders run, `missing` catches `ModelNotFoundException` — [Middleware/SubstituteBindings.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/Middleware/SubstituteBindings.php)
4. Implicit binding skips `UrlRoutable` values; scoping and binding fields are implicit-only — [ImplicitRouteBinding.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/ImplicitRouteBinding.php)
5. `web` / `api` include `SubstituteBindings` — [Foundation/Configuration/Middleware.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Foundation/Configuration/Middleware.php); ordering before `Authorize` — [Foundation/Http/Kernel.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Foundation/Http/Kernel.php) `$middlewarePriority`
6. `resolveRouteBinding()`, `resolveSoftDeletableRouteBinding()`, `isSoftDeletable()` — [Database/Eloquent/Model.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Model.php)
7. One-argument binder call for channels — [Broadcasting/Broadcasters/Broadcaster.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Broadcasting/Broadcasters/Broadcaster.php)
8. Explicit binding belongs in `AppServiceProvider::boot()` — [docs/repos/laravel/docs/routing.md](repos/laravel/docs/routing.md#explicit-binding), [laravel.com/docs/routing#explicit-binding](https://laravel.com/docs/routing#explicit-binding)
9. Every consequence in §1.1 and §1.4, the §3.3 schema pattern and the §3.8 expected responses — reproduced with `Orchestra\Testbench\Foundation\Application::create()` and `JsonSchema\Validator` in this repository (scratch scripts, not committed)
