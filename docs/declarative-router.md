# Declarative Router — `Illuminate\Routing\Router` Global Patterns & Manifest Schema

Source of truth: `vendor/laravel/framework/src/Illuminate/Routing/Router.php` (`laravel/framework` v13.33.0), with `Illuminate/Routing/Route.php`, `Illuminate/Routing/AbstractRouteCollection.php`, `Illuminate/Routing/CompiledRouteCollection.php`, `Illuminate/Routing/RoutingServiceProvider.php`, `Illuminate/Foundation/Application.php`, `Illuminate/Foundation/Configuration/ApplicationBuilder.php`, `Illuminate/Foundation/Support/Providers/RouteServiceProvider.php` and `vendor/symfony/routing/Route.php` (`symfony/routing` v8.1.6).

Goal: a `router:` block in `manifest/app.yml` whose **keys map 1:1 onto `Router` method names** and whose **values map 1:1 onto those methods' signatures**. This migration adds the block's first key, `pattern` (`Router::pattern($key, $pattern)`, Laravel's "global constraints"). It is stage 4 of [declarative-request-to-view-roadmap.md](declarative-request-to-view-roadmap.md) §1. The provider applies it with one loop (§2.5), in the phase Laravel's docs use (`boot()`, §1.1). `model` and `bind` (stage 6): [declarative-router-bindings.md](declarative-router-bindings.md).

---

## 1. Public API of `Router::class` (global patterns)

### 1.1 Lifecycle position (when the declaration runs)

```php
new Application($basePath);            // registerBaseServiceProviders(): RoutingServiceProvider binds the `router` singleton,
                                       // so the Router exists before any provider's register()

registerConfiguredProviders();         // every eager register(); by convention no route is created here

$app->boot();                          // fireAppCallbacks($bootingCallbacks)
                                       //   withRouting(): register(RouteServiceProvider::class), appended LAST
                                       // then each provider's boot(), in registration order:
                                       //   Illuminate\* -> package-discovered, LaravelDeclarationProvider among them:
                                       //       registerRouter()     <- `router.pattern` applied HERE
                                       //       registerProviders()  declared `providers`: register() now, boot() at the very end
                                       //       registerRoutes()     manifest `routes` created: patterns merged in
                                       //   -> app providers         AppServiceProvider::boot(), where Laravel's docs call Route::pattern()
                                       //   -> RouteServiceProvider  its booted() callback runs loadRoutes(): routes/web.php, routes/api.php
                                       //   -> declared `providers`
```

A pattern is merged into a route **when the route is created** (§1.4), not when it is matched. So the call must run before `addRoute()`. Laravel's docs put `Route::pattern()` in `AppServiceProvider::boot()` (routing.md, "Global Constraints"), and the package already creates its routes in `boot()`. So the manifest applies `pattern` first in `LaravelDeclarationProvider::boot()`.

Applied there, a pattern constrains:

- the manifest's `routes`,
- `routes/*.php` loaded by `withRouting()`. `ApplicationBuilder::withRouting()` registers `RouteServiceProvider` in a `booting()` callback, so it boots after every eager provider and loads the files in its own `booted()` callback,
- routes created in the `boot()` of any provider that boots later: later package-discovered providers, app providers, and declared `providers`,
- routes created in a declared provider's `register()`. `registerProviders()` runs after `registerRouter()`.

It does **not** constrain:

- routes created earlier: the `boot()` of framework providers and of package providers discovered before this one. `ServiceProvider::loadRoutesFrom()` `require`s the file immediately. The same is true of a hand-written `Route::pattern()` in `AppServiceProvider::boot()`, which runs later still,
- cached routes. Their `wheres` were fixed at `route:cache` time (§1.4).

### 1.2 Properties

| Property | Type | Set by | Read by |
|---|---|---|---|
| `Router::$patterns` (protected) | `array<string, string>` | `pattern()` / `patterns()` | `getPatterns()`, `addWhereClausesToRoute()` |
| `Route::$wheres` (public) | `array<string, string>` | `where()` / `setWheres()` | `toSymfonyRoute()` (as requirements), `AbstractRouteCollection::compile()` (route cache) |

### 1.3 Public methods

**Declaration target**

| Method | Signature | Effect |
|---|---|---|
| `pattern` | `($key, $pattern): void`. `@param string $key, string $pattern` | `$this->patterns[$key] = $pattern`. A second call for the same key replaces the first for routes created **afterwards**. Routes already created are untouched |

**Not declaration targets**

| Method | Signature | Why no key |
|---|---|---|
| `patterns` | `($patterns): void`. `@param array $patterns` | `foreach ($patterns as $key => $pattern) $this->pattern($key, $pattern)`. The map form of `pattern` is already that loop (§2.6) |
| `getPatterns` | `(): array` | runtime read-back |
| `Route::where` / `setWheres` | `($name, $expression = null): $this` | route level; already the `where` route key (declarative-routing.md §2.4) |
| `Route::whereNumber` / `whereAlpha` / `whereAlphaNumeric` / `whereUuid` / `whereUlid` / `whereIn` | `(array\|string $parameters): $this` | route level. On the facade, `Router::__call()` forwards `where*` to a `RouteRegistrar`: a **group** attribute, not a global pattern. There is no global preset in Laravel; declare the preset's regex under `pattern` (§2.2) |
| `model` / `bind` | `($key, $class, ?Closure $callback = null)` / `($key, $binder)` | declaration targets: declarative-router-bindings.md §1.3 |

### 1.4 How a pattern reaches a route

```php
// Router.php:567 — every addRoute() / get() / match() / view() / redirect() goes through here
protected function createRoute($methods, $uri, $action)
{
    // ... controller action parsing, newRoute(), group attribute merge ...
    $this->addWhereClausesToRoute($route);
    return $route;
}

// Router.php:707
protected function addWhereClausesToRoute($route)
{
    $route->where(array_merge(
        $this->patterns, $route->getAction()['where'] ?? []   // group `where` beats a global pattern
    ));
    return $route;
}

// Route.php:665 — one assignment per key: a later where() for the same key wins
public function where($name, $expression = null)
{
    foreach ($this->parseWhere($name, $expression) as $name => $expression) {
        $this->wheres[$name] = $expression;
    }
    return $this;
}

// Route.php:1480 — wheres become Symfony requirements for the path AND the host
public function toSymfonyRoute()
{
    return new SymfonyRoute(
        preg_replace('/\{(\w+?)\?\}/', '{$1}', $this->uri()), $this->getOptionalParameterNames(),
        $this->wheres, ['utf8' => true],
        $this->getDomain() ?: '', [], $this->methods
    );
}
```

Consequences, each verified against v13.33.0:

1. **Precedence.** A route's own `where` (the manifest's `where` key, applied after `addRoute()`) beats a group `where`, which beats a global `pattern`.
2. **Every route carries every pattern.** `Route::$wheres` of `GET /` contains `id` once `pattern('id', ...)` ran before it was created. `RouteCompiler` uses only the requirements whose name is a `{parameter}` of the URI or domain; the rest do nothing.
3. **Domain parameters too.** `pattern: {account: '[a-z]+'}` compiles `{account}.example.com` to `{^(?P<account>[a-z]+)\.example\.com$}sDiu`.
4. **Requirement syntax is Symfony's.** `Symfony\Component\Routing\Route::sanitizeRequirement()` (Route.php:446) strips one leading `^` or `\A` and one trailing `$` or `\z`. No delimiters, no flags. An empty result throws `InvalidArgumentException: Routing requirement for "id" cannot be empty.`, and a non-string throws `TypeError: ... Argument #2 ($regex) must be of type string, array given`. Both are thrown at the first `toSymfonyRoute()` (first match, or `route:cache`) of **every route created afterwards** (consequence 2). One bad pattern makes every later route fail with a 500.
5. **`route:cache` freezes it.** `AbstractRouteCollection::compile()` (line 182) stores `'wheres' => $route->wheres`. `CompiledRouteCollection::newRoute()` (line 362) rebuilds each route through `Router::newRoute()`, not `createRoute()`, then calls `->setWheres($attributes['wheres'])`. `$patterns` is never merged again: the cached routes keep the patterns in effect at cache time.

### 1.5 The PHP this replaces

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    Route::pattern('id', '[0-9]+');
    Route::pattern('account', '[a-z]+');
}
```

```yaml
router:
  pattern:
    id: '[0-9]+'
    account: '[a-z]+'
```

---

## 2. Manifest schema proposal

### 2.1 Design rule

> **Every key in the `router:` block is an `Illuminate\Routing\Router` method name. Every value is the argument(s) of that method's signature.** A map gives one call per entry, and its key is the first argument (`param: regex` → `pattern($key, $pattern)`).

This is the rule of `app.alias` and `app.bind` (declarative-application.md §2.1). The YAML can never drift from the API, because a key *is* the method it calls.

### 2.2 Values

A value is a regex string in Symfony requirement syntax (§1.4, consequence 4), passed through untouched. **Single-quote it.** YAML reads regex characters as syntax:

| YAML | Decodes to | Result |
|---|---|---|
| `id: '[0-9]+'` | `'[0-9]+'` | correct |
| `id: [0-9]+` | `ParseException: Unexpected token "+"` | the manifest fails to load |
| `id: [a-z]` | `['a-z']`, a **list** | hydrates silently; `TypeError` at the first route compile |
| `id: "\d+"` | `ParseException: Found unknown escape character "\d"` | the manifest fails to load |
| `id: \d+` | `'\d+'` | works; quote it anyway |

Laravel's route-level presets have no global form. Declare their regex:

| Route preset | Regex (`CreatesRegularExpressionRouteConstraints`) | `pattern` entry |
|---|---|---|
| `whereNumber` | `[0-9]+` | `id: '[0-9]+'` |
| `whereAlpha` | `[a-zA-Z]+` | `slug: '[a-zA-Z]+'` |
| `whereAlphaNumeric` | `[a-zA-Z0-9]+` | `code: '[a-zA-Z0-9]+'` |
| `whereUuid` | `[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}` | `uuid: '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}'` |
| `whereUlid` | `[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}` | `ulid: '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}'` |
| `whereIn($values)` | `implode('\|', enum_value(...$values))` | `status: 'draft\|published'`. Symfony wraps each requirement in `(?P<status>...)`, so the alternation stays scoped |

### 2.3 Full example

```yaml
router:                                          # ——— Router surface (this document) ———
  pattern:                                       # -> pattern($key, $pattern), one call per entry
    id: '[0-9]+'                                 # every {id} of every route created afterwards
    account: '[a-z]+'                            # domain parameters too

routes:
  - path: "posts/{id}"                           # {id} must match [0-9]+: /posts/abc is a 404
    methods: GET
    action: App\Http\Controllers\PostController

  - path: "slugs/{id}"
    methods: GET
    action: App\Http\Controllers\SlugController
    where:
      id: '[a-z]+'                               # a route's own where wins over the global pattern

  - path: tenants
    methods: GET
    action: App\Http\Controllers\TenantController
    domain: "{account}.example.com"              # {account} must match [a-z]+
```

### 2.4 Key → method → signature map

| YAML key (under `router:`) | `Router` method | Value shape (YAML) | Dispatch (§2.5) | Absent → |
|---|---|---|---|---|
| `pattern` | `pattern` | `map<param, regex>` | `$Router->pattern($key, $pattern)` per entry | no global pattern |

An unknown key throws `LogicException` when the manifest is read in `register()`, as `app:` does (declarative-application.md §2.4). `patterns` is an unknown key; `model` / `bind`: declarative-router-bindings.md §2.4.

### 2.5 Registration algorithm (for the provider)

A new `DataModel`, `src/Router.php`, hydrated from the `router:` key by a new `Manifest` property. `nullable`, not `default`, for the reason `Manifest::$app` gives (declarative-application.md §2.5): an empty `router:` parses to `null`, which hydrates to `null`.

```php
// src/Manifest.php
public const string router = 'router';

/** The `Router` surface; null when the manifest has no (or an empty) `router:` block */
#[Describe([Describe::nullable => true])]
public ?Router $router;
```

In `LaravelDeclarationProvider::boot()`, before `registerProviders()` and `registerRoutes()` (§1.1):

```php
$Manifest = $this->app->make(Manifest::class);
$Router = $this->app->make(Router::class);

$this->registerRouter($Manifest, $Router);
$this->registerProviders($Manifest);
$this->registerRoutes($Manifest, $Router);
```

```php
private function registerRouter(Manifest $Manifest, Router $Router): void
{
    foreach ($Manifest->router->pattern ?? [] as $key => $pattern) {
        $Router->pattern($key, $pattern);
    }
}
```

That loop is the whole implementation. The provider adds no transformation, wrapper or reference resolution: the value is the argument. `Router` in the provider stays `Illuminate\Routing\Router` (the existing import). The provider never names the new DataModel, because `?? []` covers the absent block, so the same-namespace `ZeroToProd\LaravelDeclaration\Router` needs no import alias. `->pattern ?? []`, not `?->pattern ?? []`: phpstan reports a nullsafe access on the left of `??` as unnecessary.

### 2.6 Notes / non-goals

- **No `patterns` key.** `patterns($patterns)` is a loop over `pattern()`, and the map form of `pattern` is that loop. Two keys for one effect would break "one key = one method" (roadmap Phase 1). The typo `patterns:` throws, because it is the likeliest one.
- **`boot()`, not `register()`.** The router is a base binding, so `register()` would work mechanically, and it would also constrain the routes of providers that boot earlier. No hand-written `Route::pattern()` in the documented place (`AppServiceProvider::boot()`) does that. `boot()` keeps the manifest's reach to that of the code it replaces, or wider.
- **Not retroactive, by design.** Laravel merges at `createRoute()` (§1.4). A pattern never reaches a route created before `registerRouter()` ran, and `pattern()` has no "re-apply" API.
- **Interplay with a hand-written `Route::pattern()`.** For the same key, the later call wins for routes created after it. `AppServiceProvider::boot()` runs after `LaravelDeclarationProvider::boot()`, so its value applies to `routes/*.php`, and the manifest's value applies to the manifest's own `routes`.
- **`route:cache`.** `route:cache` boots a fresh application, which applies the block, and it stores each route's merged `wheres` (§1.4, consequence 5). Treat `router` like `./routes`: re-run `route:cache` after editing it.
- **Not a validation layer.** Regexes pass through as YAML decoded them. A list, or a regex that is empty after anchor stripping, fails at the first compile of every later route with Symfony's own exception (§1.4, consequence 4). `laravel-declaration:validate` catches the list case: the schema requires strings.
- **No preset keys.** Laravel has no `patternNumber()` and no global `whereUuid()`, so the manifest has no such keys either. Use the regex table in §2.2.
- **No group `where`.** Group attributes (`Route::where([...])->group()`, facade `Route::whereNumber()`) need a group construct, which the manifest does not have. A route's `where` key covers the per-route case.
- **`model` / `bind` (stage 6)**: [declarative-router-bindings.md](declarative-router-bindings.md).
- **Middleware** (`aliasMiddleware`, `middlewareGroup`, ...) is not a `router` key: `Http\Kernel::syncMiddlewareToRouter()` overwrites it (roadmap Phase 5).

---

## 3. Implementation plan

1. **`src/Router.php`** (new). The same shape as `App`, with one property and the same unknown-key hook:

   ```php
   <?php

   declare(strict_types=1);

   namespace ZeroToProd\LaravelDeclaration;

   use LogicException;
   use Zerotoprod\DataModel\Describe;
   use ZeroToProd\LaravelDeclaration\Attributes\Key;
   use ZeroToProd\LaravelDeclaration\Internal\DataModel;

   /**
    * The `router:` block of the manifest.
    *
    * Every key is an `Illuminate\Routing\Router` method name and every value is the argument(s)
    * of that method's signature: a map is one call per entry, its key the first argument.
    *
    * @link docs/declarative-router.md
    */
   final readonly class Router
   {
       use DataModel;

       public const string pattern = 'pattern';

       /** @var array<string, string> route parameter => regex; one `Router::pattern($key, $pattern)` per entry */
       #[Key, Describe([Describe::pre => [self::class, 'validate'], Describe::default => []])]
       public array $pattern;

       /**
        * Rejects keys that name no declarable `Router` method, so a typo fails fast instead of
        * hydrating silently. Runs against the whole `router:` block, before any value is resolved.
        *
        * @param  array<array-key, mixed>  $context
        */
       public static function validate(mixed $value, array $context): void
       {
           $unknown = array_diff(array_keys($context), self::selected(Key::class));

           if ($unknown !== []) {
               throw new LogicException(
                   'The `router` block declares unknown key(s): '.implode(', ', $unknown).
                   '. Every key must be an `Illuminate\Routing\Router` method name.'
               );
           }
       }
   }
   ```

   The `pre` hook runs even when `pattern` is absent. `App`'s `singelton` test depends on the same behavior (`bind` absent, hook still fires).

2. **`src/Manifest.php`**. Add the `router` const and nullable property of §2.5 directly after `$app`.

3. **`src/LaravelDeclarationProvider.php`**. Replace the last three lines of `boot()` with the block in §2.5 and add `registerRouter()`. Keep `use Illuminate\Routing\Router;`.

4. **`manifest.schema.json`**. Add `"router": { "$ref": "#/definitions/router" },` to the root `properties` after `"app"`, and this entry to `definitions` after `"app"`:

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
       }
     }
   },
   ```

5. **`README.md`**. In `## Manifest`, make three edits. Change "registers its providers and routes in `boot()`" to "applies its `router` block and registers its providers and routes in `boot()`". Add `tests/Feature/RouterRegistrationTest.php` to the test list. Make the key list read "`config`, `app`, `router`, `providers`, `routes` and `requests`", with `[Router](#router)` among the links. Then insert this section between `## Application` and `## Providers`:

   ````markdown
   ## Router

   The `router` block maps 1:1 onto
   [`Illuminate\Routing\Router`](https://laravel.com/docs/routing#parameters-global-constraints)
   methods: every key is a `Router` method name, and its value is that method's
   argument(s). A map is one call per entry, its key the first argument.
   `LaravelDeclarationProvider` applies the block first in `boot()`, before
   `providers` and `routes`. See `tests/Feature/RouterRegistrationTest.php` and
   [docs/declarative-router.md](docs/declarative-router.md).

   `pattern` is `Router::pattern($key, $pattern)`, a global `where`. Laravel
   merges every pattern into each route as the route is created, so it constrains
   `{key}` in the URI or domain of every route created afterwards: the manifest's
   `routes`, `routes/*.php`, and routes of providers that boot later. Routes
   created earlier are not constrained. A route's own `where` wins. Single-quote
   every regex: `[` starts a YAML list, and `"\d"` is a YAML escape error.
   `route:cache` bakes patterns into each cached route, so re-run it after
   editing the block. An unknown key throws a `LogicException`. `patterns` is
   not a key, because the map form of `pattern` is that loop.

   Complete structure:

   ```yaml
   router:
     pattern:                    # -> pattern($key, $pattern), one call per entry
       id: '[0-9]+'              # every {id} of every route created afterwards
       account: '[a-z]+'         # domain parameters too: {account}.example.com
   ```
   ````

6. **Fixture**: `tests/Fixtures/manifest/router.yml`. It is a separate file, so `RouteRegistrationTest`'s route count and fallback in `app.yml` stay unchanged:

   ```yaml
   # yaml-language-server: $schema=./../../../manifest.schema.json

   router:
     pattern:
       id: '[0-9]+'
       account: '[a-z]+'

   routes:
     - path: "posts/{id}"
       methods: GET
       action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockController

     - path: "slugs/{id}"
       methods: GET
       action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockController
       where:
         id: '[a-z]+'

     - path: tenants
       methods: GET
       action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockController
       domain: "{account}.example.com"
   ```

7. **Tests**: `tests/Feature/RouterRegistrationTest.php`:

   ```php
   <?php

   declare(strict_types=1);

   use Illuminate\Routing\Router;

   $manifest = __DIR__.'/../Fixtures/manifest/router.yml';

   it('sets every declared pattern on the router', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);

       expect(app(Router::class)->getPatterns())->toBe(['id' => '[0-9]+', 'account' => '[a-z]+']);
   });

   it('constrains the parameter on every route created afterwards', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);

       $this->get('/posts/5')->assertOk();
       $this->get('/posts/abc')->assertNotFound();
   });

   it('lets a route where override the pattern', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);

       $this->get('/slugs/abc')->assertOk();
       $this->get('/slugs/5')->assertNotFound();
   });

   it('constrains domain parameters', function () use ($manifest): void {
       $this->withConfig(['laravel-declaration.manifest' => $manifest]);

       $this->get('https://acme.example.com/tenants')->assertOk();
       $this->get('https://acme1.example.com/tenants')->assertNotFound();
   });

   it('sets no pattern without a router block', function (): void {
       $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/requests.yml']);

       expect(app(Router::class)->getPatterns())->toBeEmpty();
   });

   it('rejects unknown router keys', function (): void {
       $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
       file_put_contents($file, <<<'YAML'
           router:
             patterns:
               id: '[0-9]+'
           YAML);

       expect(fn (): bool => $this->withConfig(['laravel-declaration.manifest' => $file]) !== null)
           ->toThrow(LogicException::class, 'unknown key(s): patterns');
   });
   ```

   Also add to `tests/Feature/ValidateCommandTest.php`, so the schema entry is exercised:

   ```php
   test('laravel-declaration:validate accepts the router block', function (): void {
       $this->artisan('laravel-declaration:validate', ['--manifest' => __DIR__.'/../Fixtures/manifest/router.yml'])
           ->expectsOutputToContain('is valid')
           ->assertSuccessful();
   });
   ```

8. **`composer check`**: lint, rector, phpstan, 100% coverage, bc-check. `registerRouter()` is covered by the fixture tests, and the `throw` in `Router::validate()` by the unknown-key test. The new public API is `Router` (with `$pattern` and `validate()`) and `Manifest::$router`. Both are additive for `bc-check`. The MCP `api` tool reflects `src/`, so it lists `Router` with no change, and the `readme` tool serves the updated README.

9. **Roadmap**. In [declarative-request-to-view-roadmap.md](declarative-request-to-view-roadmap.md) §1, set stage 4 to "done". Phase 1 continues with `model` and `bind`: they decorate new `Router` properties with the `Key` attribute, and add loops to `registerRouter()`, and rows to §2.4 of this document.

---

### Sources

1. `pattern()`, `patterns()`, `getPatterns()`, `createRoute()`, `addWhereClausesToRoute()`, `__call()` — [vendor/laravel/framework/src/Illuminate/Routing/Router.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/Router.php)
2. `where()`, `setWheres()`, `toSymfonyRoute()` — [vendor/laravel/framework/src/Illuminate/Routing/Route.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/Route.php)
3. Preset regexes — [vendor/laravel/framework/src/Illuminate/Routing/CreatesRegularExpressionRouteConstraints.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/CreatesRegularExpressionRouteConstraints.php)
4. Route cache stores and restores `wheres` — [AbstractRouteCollection.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/AbstractRouteCollection.php), [CompiledRouteCollection.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/CompiledRouteCollection.php)
5. `router` singleton as a base provider — [RoutingServiceProvider.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/RoutingServiceProvider.php), [Foundation/Application.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Foundation/Application.php) (`registerBaseServiceProviders()`, `boot()`, `bootProvider()`)
6. `withRouting()` registers `RouteServiceProvider` in `booting()`; routes load in its `booted()` callback — [ApplicationBuilder.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php), [Foundation/Support/Providers/RouteServiceProvider.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Foundation/Support/Providers/RouteServiceProvider.php)
7. `loadRoutesFrom()` requires immediately — [Support/ServiceProvider.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Support/ServiceProvider.php)
8. Requirement sanitizing (anchors, empty) — [vendor/symfony/routing/Route.php](https://github.com/symfony/routing/blob/v8.1.6/Route.php)
9. Global constraints belong in `AppServiceProvider::boot()` — [docs/repos/laravel/docs/routing.md](repos/laravel/docs/routing.md#parameters-global-constraints), [laravel.com/docs/routing#parameters-global-constraints](https://laravel.com/docs/routing#parameters-global-constraints)
10. YAML decoding of `[0-9]+`, `[a-z]`, `"\d+"` — `symfony/yaml` v8.1.6, verified with `Yaml::parse()` in this repository
