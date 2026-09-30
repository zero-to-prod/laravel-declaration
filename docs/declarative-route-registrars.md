# Declarative Route Registrars — `Router::group()` / Resource Registration / Native Shortcuts & Manifest Schema

Source of truth: `vendor/laravel/framework/src/Illuminate/Routing/Router.php` (`laravel/framework` v13.33.0), with `RouteGroup.php`, `ResourceRegistrar.php`, `PendingResourceRegistration.php`, `PendingSingletonResourceRegistration.php`, `RouteFileRegistrar.php`, `ViewController.php`, `RedirectController.php` and `Illuminate/Routing/Route.php`.

Goal: resolve Tier 1 gap inventory [declarative-tier1-gap-inventory.md](declarative-tier1-gap-inventory.md) §2.2 — "Route Registration — `routes:` `[/] (narrower)`". The route-level surface is shipped ([declarative-routing.md](declarative-routing.md): native `uri` noun + dynamic `builders` dispatch). What remains are the **Router-level registration surfaces** that a `Route`-builder-level dispatch cannot express, because they *create route collections*: `Router::group()`, the resource registrar family, and the native shortcuts (`Router::view()` / `Router::redirect()` / `Router::permanentRedirect()`).

**Implementation requirement: dynamic dispatch.** No hardcoded per-method whitelists anywhere (the defect that produced the original 16 static `#[Builder]` properties). Every manifest key is a native method name and is dispatched through `$object->{$method}(...)`; the only special-casing is *shape-based* signature adaptation (flag / map-of-calls / spread / single value / Closure wrap), mirroring the shipped `RoutesDeclarationServiceProvider::applyBuilder()`. Unknown keys fail with Laravel's own exceptions (Design Rule 7).

Design rules: [declarative-request-to-view-roadmap.md](declarative-request-to-view-roadmap.md) §2 (Rule 1: key = method name; Rule 2: one call per entry; Rule 4: wrap only where Laravel needs a Closure; Rule 6: `route:cache` safe; Rule 7: fail where Laravel fails).

Grounding documentation: [declarative-routing.md](declarative-routing.md) (the shipped `Route`-level surface), [declarative-router.md](declarative-router.md) / [declarative-router-bindings.md](declarative-router-bindings.md) (the `router:` config surface), [declarative-router-configuration.md](declarative-router-configuration.md) (§2.1 — `router.resourceParameters` / `router.singularResourceParameters` become effective only once resource registration ships, §1.3).

---

## 1. Native API (v13.33.0)

### 1.1 Lifecycle position (when the declaration runs)

```php
$app->boot()
  RoutesDeclarationServiceProvider::boot()                    // <- one dispatch loop HERE
    register(Router, Manifest->routes)
      Router::addRoute($methods, $uri, $action)               // shipped surface, per entry
        createRoute()                                         //   Router.php:567
          prefix($uri)                                        //   getLastGroupPrefix() prepends group prefix to URI (Router.php:696)
          mergeGroupAttributesIntoRoute($route)               //   RouteGroup::merge(action, group, prependExistingPrefix: false) (Router.php:722)
          addWhereClausesToRoute($route)                      //   global patterns + route wheres (Router.php:707)
      Router::group($attributes, Closure)                     // Router.php:472
        updateGroupStack()                                    //   mergeWithLastGroup() -> RouteGroup::merge() (Router.php:494,510)
        loadRoutes($routes)                                   //   Closure form: $routes($this) (Router.php:521)
          register(Router, nested Routes)                     //   RECURSION — group stack active for every nested call
      Router::resource($name, $controller, $options)          // Router.php:347 -> PendingResourceRegistration
        $Pending->{$option}(...)                              //   fluent setters mutate options (PendingResourceRegistration.php:69-305)
        PendingResourceRegistration::__destruct()             //   -> register() fires HERE, inside the active group stack (PendingResourceRegistration.php:331)
          ResourceRegistrar::register()                       //   creates the 7 Route instances (ResourceRegistrar.php:79)
      Router::view($uri, $view, ...)                          // Router.php:287 -> ViewController + setDefaults
```

Consequences, each verified against v13.33.0:

1. **Groups are native.** The provider never re-implements attribute merging: it opens the group scope and recurses; `RouteGroup::merge()` (RouteGroup.php:17) does prefix concatenation, `where` merging, `as` concatenation, `namespace` concatenation, `middleware` merging and metadata merging. Nested groups come free from `updateGroupStack()`.
2. **Resources register at object destruction.** `PendingResourceRegistration::__destruct()` (line 331) calls `register()` when `!$this->registered`. The dispatch loop must create the Pending object inside the group closure scope so the destructor fires while the group stack is pushed — that is how a resource inside `Router::group()` gets its prefix. No explicit `->register()` call is needed.
3. **Dispatch order across keys is fixed but matching-irrelevant.** `RouteCollection` resolves the fallback route last natively, so a fallback declared in the first group still only matches after every other route.

### 1.2 `Router::group()` — the attributes contract

```php
public function group(array $attributes, Closure|array|string $routes)  // Router.php:472 (returns $this)
```

`loadRoutes()` runs a **Closure** as `$routes($this)` — the closure receives the `Router` (Router.php:521-529). The array/string forms delegate to `RouteFileRegistrar`, which in v13 only `require`s file paths (RouteFileRegistrar.php:28-35) — so a declarative manifest must use the Closure form (Design Rule 4: wrap only where Laravel needs a Closure).

The group stack merges through `RouteGroup::merge()` (RouteGroup.php:17). Attributes consumed natively:

| Group attribute | Merge behavior (RouteGroup.php) | Read by |
|---|---|---|
| `prefix` | concatenated parent/child, `/`-normalized; direction flips via `$prependExistingPrefix` (`formatPrefix`, :124) | `Router::prefix($uri)` → URI (Router.php:696-703) |
| `as` | concatenated `parent.child` (`formatAs`, :157) | route `action['as']` (route names) |
| `namespace` | concatenated with `\` unless child starts with `\` (`formatNamespace`, :105) | `prependGroupNamespace()` (Router.php:641) |
| `where` | merged parent + child (`formatWhere`, :142) | `addWhereClausesToRoute()` (Router.php:707) |
| `middleware` | `array_merge_recursive` (generic merge, duplicates preserved) | `Route::gatherMiddleware()` |
| `domain` | child wins; parent's dropped when child declares one (`merge`, :19-21) | `Route::getDomain()` |
| `controller` | child wins; prepended to bare method names (`prependGroupController()`, Router.php:656) | `convertToControllerAction()` (Router.php:613) |
| `metadata` | `RouteGroup::mergeMetadata()` — assoc maps merge recursively, lists replace (:53-87) | `Route::getMetadata()` (the `metadata.request` seam of `DeclaredRequest` / `DeclaredView`) |

### 1.3 Resource registrar family

| Native method | Signature | Line | Default actions |
|---|---|---|---|
| `Router::resource()` | `resource(string $name, string $controller, array $options = []): PendingResourceRegistration` | :347 | `index, create, store, show, edit, update, destroy` (ResourceRegistrar.php:21) |
| `Router::resources()` | `resources(array $resources, array $options = []): void` | :318 | batch loop over `resource()` |
| `Router::apiResource()` | `apiResource(string $name, string $controller, array $options = []): PendingResourceRegistration` | :382 | `index, show, store, update, destroy` minus `$options['except']` |
| `Router::apiResources()` | `apiResources(array $resources, array $options = []): void` | :367 | batch loop |
| `Router::singleton()` | `singleton(string $name, string $controller, array $options = []): PendingSingletonResourceRegistration` | :417 | `show, edit, update` (ResourceRegistrar.php:28); `+ create, store, destroy` when `$options['creatable']`, `+ destroy` when `$options['destroyable']` (:163-166) |
| `Router::singletons()` | `singletons(array $singletons, array $options = []): void` | :402 | batch loop |
| `Router::apiSingleton()` | `apiSingleton(string $name, string $controller, array $options = []): PendingSingletonResourceRegistration` | :452 | `store, show, update, destroy` minus `except` |
| `Router::apiSingletons()` | `apiSingletons(array $singletons, array $options = []): void` | :437 | batch loop |

**Dispatch vocabulary = the fluent surface of what the call returns.** `Router::resource()` returns a `PendingResourceRegistration` (uses `Macroable` + `CreatesRegularExpressionRouteConstraints`, PendingResourceRegistration.php:10); `Router::singleton()` returns `PendingSingletonResourceRegistration` (same traits). Registration fires at `__destruct()` → `register()` → `ResourceRegistrar::register()`. Every option key written by the fluent setters (verified PendingResourceRegistration.php:69-317, PendingSingletonResourceRegistration.php:69-303):

| Pending method | Signature | Option written | ResourceRegistrar consumption |
|---|---|---|---|
| `only($methods)` | `(array|string ...$methods)` — variadic when string | `only` | `getResourceMethods()` (ResourceRegistrar.php:270) — wins over defaults |
| `except($methods)` | `(array|string ...$methods)` | `except` | filters the defaults |
| `names($names)` | `(array $names)` — map<method, name> | `names` | `getResourceRouteName()` (:677) |
| `name($method, $name)` | `(string $method, string $name)` | `names[$method]` | per-method override |
| `parameters($parameters)` | `(array|string $parameters)` | `parameters` | `getResourceWildcard()` (:615) — map or `'singular'` |
| `parameter($previous, $new)` | `(string $previous, string $new)` | `parameters[$previous]` | per-parameter override |
| `middleware($middleware)` | `(array|string $middleware)` | `middleware` | route action (appends) |
| `middlewareFor($methods, $middleware)` | `(string|array $methods, array|string $middleware)` | `middleware_for[$method]` | per-method middleware (:105-110) |
| `withoutMiddleware($middleware)` | `(array|string $middleware)` | `excluded_middleware` | route action |
| `withoutMiddlewareFor($methods, $middleware)` | `(string|array $methods, array|string $middleware)` | `excluded_middleware_for[$method]` | per-method exclusion (:112-118) |
| `where($wheres)` | `(array $wheres)` | **`wheres`** | `getResourceAction()` (:651) |
| `metadata($metadata)` | `(array $metadata)` | `metadata` | action metadata (:659) |
| `shallow($shallow = true)` | `(bool $shallow = true)` | `shallow` | `getShallowName()` (:546) — shallow nested route names |
| `missing($callback)` | `(Closure $callback)` | `missing` | route action (:655) |
| `scoped($fields = [])` | `(array $fields = [])` | `bindingFields` | `setResourceBindingFields()` (:123, :560) |
| `withTrashed($methods = [])` | `(array $methods = [])` | `trashed` | `$route->withTrashed()` for show/edit/update (:127-131) |
| `creatable()` / `destroyable()` | `(): $this` (singletons only) | `creatable` / `destroyable` | singleton defaults (:163-166) |
| `whereNumber` / `whereAlpha` / `whereAlphaNumeric` / `whereUuid` / `whereUlid` | `(array|string $parameters)` | — (direct route setters via `CreatesRegularExpressionRouteConstraints`) | applied when the Pending registers |

Note the two native nouns that are **not** method names at the registrar level (`wheres`, `bindingFields`, `trashed`) — they are array keys. The manifest dispatches their **Pending method** nouns (`where`, `scoped`, `withTrashed`), keeping Rule 1 (key = method name) intact.

### 1.4 Native shortcuts

| Native method | Signature | Line | Internals |
|---|---|---|---|
| `Router::view()` | `view(string $uri, string $view, array $data = [], int|array $status = 200, array $headers = []): Route` | :287 | `match(['GET','HEAD'], $uri, ViewController)` + `setDefaults(['view', 'data', 'status' => is_array($status) ? 200 : $status, 'headers' => is_array($status) ? $status : $headers])` |
| `Router::redirect()` | `redirect(string $uri, string $destination, int $status = 302): Route` | :258 | `any($uri, RedirectController)` + `defaults('destination', ...)`, `defaults('status', ...)` — `any()` = `addRoute(self::$verbs, ...)` (:136, :232), all seven verbs |
| `Router::permanentRedirect()` | `permanentRedirect(string $uri, $destination): Route` | :272 | `redirect($uri, $destination, 301)` |

`ViewController::__invoke()` merges route parameters into `$args['data']` and returns `$response->view($view, $data, $status, $headers)` (ViewController.php:33-46) — route parameters are usable by the template without any declared data. `RedirectController::__invoke()` builds the destination from the remaining route parameters (RedirectController.php:22-53) — a `redirect` entry's URI may carry parameters that the destination reuses.

---

## 2. Manifest schema (`routes:`)

### 2.1 Design rule — the block is a map of `Router` registration method names

The shipped `routes:` block is a bare list whose entries are `Route`-level declarations. The completed schema makes the block a **map whose keys are the native `Router` registration method names** (Design Rule 1), with the shipped list preserved verbatim under the method that actually registers it, `addRoute` (`Router::addRoute($methods, $uri, $action)`, Router.php:554):

> **The dispatch seam follows the return type of the native call:**
> - `addRoute` / `view` / `redirect` / `permanentRedirect` → return `Route` → optional **`builders`** (the shipped `applyBuilder()` seam)
> - `resource` / `apiResource` / `singleton` / `apiSingleton` → return `Pending*Registration` → **`options`** dispatched onto the Pending's fluent methods
> - `group` → returns `Router` (mutates the group stack) → nested **`routes`** (recursive `Routes` map)

Batch forms (`resources()`, `apiResources()`, `singletons()`, `apiSingletons()`) are **not** given keys, per the `Factory::composers()` precedent (gap inventory §2.3: the batch form "is already covered by the per-entry `composer` map per Rule 2"). One call per entry over the singular registrars covers them; additionally, the native batch signature forwards a raw `$options` array into the Pending constructor, which would force the registrar's non-method option nouns (`wheres`, `trashed`, `bindingFields`, `middleware_for`) into the manifest beside the Pending method nouns. Shared options across a batch are expressed by repeating singular entries or YAML anchors. This corrects the inventory §2.2 proposed keys `routes.resources`, `routes.apiResources`, `routes.singletons`, `routes.apiSingletons`.

### 2.2 Complete example

```yaml
routes:
  # Router::addRoute() — the shipped flat surface, shape unchanged
  addRoute:
    - uri: "/"
      methods: GET
      action: App\Http\Controllers\HomeController
      name: home

    - uri: "todos/{todo}"
      methods: DELETE
      action: ZeroToProd\LaravelDeclaration\DeclaredAction
      metadata:
        request: delete-todo
      setDefaults:
        target: todo
        call: delete
        redirect: todos.index

  # Router::group() — shared attributes + nested declarations (recursive)
  group:
    - prefix: admin
      as: admin.
      namespace: App\Http\Controllers\Admin
      middleware: [web, auth]
      where:
        id: '[0-9]+'
      metadata:
        area: admin
      routes:                                  # reserved key: a nested `routes:` map
        addRoute:
          - uri: dashboard
            methods: GET
            action: DashboardController        # namespace attribute -> App\Http\Controllers\Admin\DashboardController
            name: dashboard                    # as attribute        -> admin.dashboard
        resource:
          - name: users
            controller: UserController         # -> App\Http\Controllers\Admin\UserController (namespace)
            options:
              middlewareFor:                   # Rule 2 map form: key = $methods
                destroy: [can:delete-users]
        group:                                 # nested group: prefix/as/namespace concatenate
          - prefix: settings
            as: settings.
            routes:
              addRoute:
                - uri: profile
                  methods: GET
                  action: ProfileController
                  name: profile               # -> admin.settings.profile, URI admin/settings/profile

    - domain: "{account}.example.com"         # group domain; route parameters bind natively
      routes:
        addRoute:
          - uri: tenants
            methods: GET
            action: App\Http\Controllers\TenantController

  # Router::resource() — one RESTful controller per entry, options = Pending fluent methods
  resource:
    - name: photos
      controller: App\Http\Controllers\PhotoController
      options:
        only: [index, show, store, update, destroy]
        middleware: [auth:sanctum]
        whereNumber: photo                     # -> PendingResourceRegistration::whereNumber('photo')
        scoped: true                           # -> scoped()             (bindingFields: [])
        trashed: [show, edit, update]          # -> withTrashed([show, edit, update])
        shallow: true                          # -> shallow()            (shallow nested names)
        names:
          index: gallery.index
        missing: App\Http\Handlers\PhotoMissing   # class-string wrapped in a Closure (Rule 4)

  # Router::apiResource() — resource minus create/edit
  apiResource:
    - name: posts
      controller: App\Http\Controllers\Api\PostController
      options:
        except: [destroy]

  # Router::singleton() / apiSingleton() — show/edit/update defaults
  singleton:
    - name: profile
      controller: App\Http\Controllers\ProfileController
      options:
        creatable: true                        # adds create/store/destroy
  apiSingleton:
    - name: avatar
      controller: App\Http\Controllers\Api\AvatarController

  # Router::view() — native static-view shortcut (GET|HEAD; bypasses DeclaredView)
  view:
    - uri: about
      view: pages.about
      data:
        title: About
      headers:
        X-Frame-Options: DENY
      middleware: [web]                        # optional builders on the returned Route

  # Router::redirect() / permanentRedirect()
  redirect:
    - uri: "old-posts/{post}"
      destination: "posts/{post}"
      status: 302
      where:                                   # optional builders on the returned Route
        post: '[0-9]+'
  permanentRedirect:
    - uri: legacy
      destination: /
```

### 2.3 Key → method → signature map

**Block keys** (each key = a native `Router` method; a list value = one call per item, Rule 2):

| YAML key | Router method | Item class | Item keys (reserved, signature order) |
|---|---|---|---|
| `addRoute` | `addRoute($methods, $uri, $action)` (:554) | `Route` (shipped) | `methods`, `uri`, `action` + builders |
| `group` | `group($attributes, $routes)` (:472) | `RouteGroup` | attribute keys + `routes` |
| `resource` | `resource($name, $controller, $options)` (:347) | `RouteResource` | `name`, `controller`, `options` |
| `apiResource` | `apiResource($name, $controller, $options)` (:382) | `RouteResource` | `name`, `controller`, `options` |
| `singleton` | `singleton($name, $controller, $options)` (:417) | `RouteResource` | `name`, `controller`, `options` |
| `apiSingleton` | `apiSingleton($name, $controller, $options)` (:452) | `RouteResource` | `name`, `controller`, `options` |
| `view` | `view($uri, $view, $data, $status, $headers)` (:287) | `RouteView` | `uri`, `view`, `data`, `status`, `headers` + builders |
| `redirect` | `redirect($uri, $destination, $status)` (:258) | `RouteRedirect` | `uri`, `destination`, `status` + builders |
| `permanentRedirect` | `permanentRedirect($uri, $destination)` (:272) | `RoutePermanentRedirect` | `uri`, `destination` + builders |

**`RouteGroup` item keys** (reserved `routes` + the eight `RouteGroup::merge()` attributes §1.2):

| YAML key | Value shape | Note |
|---|---|---|
| `routes` | `routes:` map (recursive) | reserved — the nested declarations |
| `prefix` | `string` | concatenated parent/child |
| `as` | `string` | **`as`, not `name`** — `formatAs` reads `$old['as']`/`$new['as']` |
| `namespace` | `string` | leading `\` resets the chain (`formatNamespace`) |
| `domain` | `string` | child replaces parent |
| `controller` | `string` | prepended to bare method names (`prependGroupController`) |
| `middleware` | `string \| list<string>` | merged, duplicates preserved |
| `where` | `map<param, regex>` | merged |
| `metadata` | `map<string, mixed>` | assoc maps merge recursively, lists replace |

**`RouteResource.options`** — every entry is one `PendingResourceRegistration` / `PendingSingletonResourceRegistration` method call (§1.3 table): `only`, `except`, `names`, `parameters`, `middleware`, `middlewareFor`, `withoutMiddleware`, `withoutMiddlewareFor`, `where`, `metadata`, `shallow`, `missing`, `scoped`, `withTrashed`, `creatable`, `destroyable`, plus the `where*` regex constraint family. Value shapes are the same four the `Route` builder seam already uses: `true` → zero-arg flag call; `false`/`null` → skipped; list → spread; map/scalar → single argument (`middlewareFor`/`withoutMiddlewareFor` are Rule 2 map forms: key = `$methods`).

### 2.4 Notes / non-goals

1. **Verb dispatch keys (`get`/`post`/…) — non-goal.** In v13 `Router::get()` etc. do not exist as methods: `Router::__call()` routes them into the fluent `RouteRegistrar` attribute API (Router.php:1497-1517), a different registration path than `addRoute`. `methods:` already covers verbs natively, and lowercase verbs never match (`MethodValidator`; declarative-routing.md §2.6). Same reasoning as the withdrawn `models.relations` (gap inventory §2.6): no expressive power, only a second spelling.
2. **Route-level `prefix` builder inside a group — pitfall.** `Router::prefix($uri)` prepends the group prefix at `createRoute()` (:696); a later `Route::prefix()` builder then prepends *its* value to the already-prefixed URI (`Route.php:830-836`): URI order becomes `builder/group/entry` and `action['prefix']` diverges from the URI. Declare prefixes as group attributes; keep the `prefix` builder for ungrouped entries.
3. **Group `excluded_middleware` — excluded.** It would only ride through `RouteGroup::merge`'s generic `array_merge_recursive` into route actions. Per-route `withoutMiddleware` builders express it. The eight documented attributes are the contract; the JSON schema rejects the rest (`additionalProperties: false`).
4. **`Router::view()`'s array `$status`** (array-as-headers) is native-only; the manifest declares `status:` as `int` and `headers:` as the map — same coverage, one spelling.
5. **`missing` Closures are wrapped, never declared.** Both the resource option and the route builder accept only an invokable class-string; the provider wraps it (Rule 4). The wrapper captures only the class-string, so `route:cache` stays safe (declarative-routing.md §2.6).
6. **Unknown keys fail at their native place (Rule 7).** Unknown block/entry/attribute keys are rejected by `manifest.schema.json` (`additionalProperties: false`) via `laravel-declaration:validate` — the same seam declarative-routing.md §2.4 names as the fail-fast gap at DataModel level. Unknown `options` keys are part of the dynamic Pending surface (including macros) and fail at boot with Laravel's own `BadMethodCallException` (`Macroable::__call`).
7. **`route:cache` / `config:cache` safe (Rule 6).** Shortcut and resource defaults hold only strings, lists and maps; group attributes and metadata likewise.

---

## 3. Implementation

### 3.1 `src/Routes.php` — the block model

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use ReflectionAttribute;
use ReflectionProperty;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Routes
{
    use DataModel;

    // Every property name is an Illuminate\Routing\Router registration method name.
    public const string addRoute = 'addRoute';
    public const string group = 'group';
    public const string resource = 'resource';
    public const string apiResource = 'apiResource';
    public const string singleton = 'singleton';
    public const string apiSingleton = 'apiSingleton';
    public const string view = 'view';
    public const string redirect = 'redirect';
    public const string permanentRedirect = 'permanentRedirect';

    /** @var list<Route> */
    #[Describe([Describe::default => []])]
    public array $addRoute;

    /** @var list<RouteGroup> */
    #[Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => RouteGroup::class])]
    public array $group;

    /** @var list<RouteResource> */
    #[Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => RouteResource::class])]
    public array $resource;

    /** @var list<RouteResource> */
    #[Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => RouteResource::class])]
    public array $apiResource;

    /** @var list<RouteResource> */
    #[Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => RouteResource::class])]
    public array $singleton;

    /** @var list<RouteResource> */
    #[Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => RouteResource::class])]
    public array $apiSingleton;

    /** @var list<RouteView> */
    #[Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => RouteView::class])]
    public array $view;

    /** @var list<RouteRedirect> */
    #[Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => RouteRedirect::class])]
    public array $redirect;

    /** @var list<RoutePermanentRedirect> */
    #[Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => RoutePermanentRedirect::class])]
    public array $permanentRedirect;

    /** @return array<string, list<object>> Router method name -> one call per item, in dispatch order. */
    public function registrars(): array
    {
        return array_filter([
            self::addRoute => $this->addRoute,
            self::group => $this->group,
            self::resource => $this->resource,
            self::apiResource => $this->apiResource,
            self::singleton => $this->singleton,
            self::apiSingleton => $this->apiSingleton,
            self::view => $this->view,
            self::redirect => $this->redirect,
            self::permanentRedirect => $this->permanentRedirect,
        ]);
    }

    /** Hydrates one item DataModel per list entry ('type' argument). */
    public static function listOf(mixed $value, array $context, ?ReflectionAttribute $Attribute, ReflectionProperty $Property): array
    {
        $type = $Attribute?->getArguments()[0]['type'] ?? null;

        if ($type === null || ! is_array($value)) {
            return [];
        }

        return array_map(static fn (array $item): object => $type::from($item), array_values($value));
    }

    /** Cast target for `Manifest::$routes` and `RouteGroup::$routes`. */
    public static function fromRoutes(mixed $value, array $context, ?ReflectionAttribute $Attribute, ReflectionProperty $Property): Routes
    {
        return Routes::from(is_array($value) ? $value : []);
    }
}
```

Trait semantics (verified `vendor/zero-to-prod/data-model/src/DataModel.php:222-246`): an absent key takes `Describe::default` *before* any cast, and a present key goes to the cast resolver called with `($value, $context, $Attribute, $Property)`.

### 3.2 `src/RouteGroup.php`

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class RouteGroup
{
    use DataModel;

    public const string routes = 'routes';   // reserved: nested declarations (not a Router method)
    public const string prefix = 'prefix';
    public const string as = 'as';
    public const string namespace = 'namespace';
    public const string domain = 'domain';
    public const string controller = 'controller';
    public const string middleware = 'middleware';
    public const string where = 'where';
    public const string metadata = 'metadata';

    #[Describe([Describe::nullable => true])]
    public ?string $prefix;

    #[Describe([Describe::nullable => true])]
    public ?string $as;

    #[Describe([Describe::nullable => true])]
    public ?string $namespace;

    #[Describe([Describe::nullable => true])]
    public ?string $domain;

    #[Describe([Describe::nullable => true])]
    public ?string $controller;

    /** @var string|list<string>|null */
    #[Describe([Describe::nullable => true])]
    public string|array|null $middleware;

    /** @var array<string, string>|null */
    #[Describe([Describe::nullable => true])]
    public ?array $where;

    /** @var array<string, mixed>|null */
    #[Describe([Describe::nullable => true])]
    public ?array $metadata;

    /** @var Routes */
    #[Describe([Describe::default => [Routes::class, 'from'], Describe::cast => [Routes::class, 'fromRoutes']])]
    public Routes $routes;

    /** @return array<string, mixed> RouteGroup::merge() attributes — every declared key except the reserved routes. */
    public function attributes(): array
    {
        return array_filter([
            self::prefix => $this->prefix,
            self::as => $this->as,
            self::namespace => $this->namespace,
            self::domain => $this->domain,
            self::controller => $this->controller,
            self::middleware => $this->middleware,
            self::where => $this->where,
            self::metadata => $this->metadata,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
```

(`Describe::default => [Routes::class, 'from']` is callable — the trait invokes defaults with `($default)(null, $context, $Attribute, $Property)` and `Routes::from(null)` yields an empty `Routes`.)

### 3.3 `src/RouteResource.php` — one class for all four singular registrars

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class RouteResource
{
    use DataModel;

    public const string name = 'name';
    public const string controller = 'controller';
    public const string options = 'options';

    #[Describe([Describe::required => true])]
    public string $name;

    #[Describe([Describe::required => true])]
    public string $controller;

    /** @var array<string, mixed> map<Pending method name, arguments> */
    #[Describe([Describe::default => []])]
    public array $options;
}
```

### 3.4 `src/RouteView.php`, `src/RouteRedirect.php`, `src/RoutePermanentRedirect.php`

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use LogicException;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class RouteView
{
    use DataModel;

    public const string uri = 'uri';
    public const string view = 'view';
    public const string data = 'data';
    public const string status = 'status';
    public const string headers = 'headers';
    public const string builders = 'builders';

    #[Describe([Describe::required => true])]
    public string $uri;

    #[Describe([Describe::required => true])]
    public string $view;

    /** @var array<string, mixed> */
    #[Describe([Describe::default => []])]
    public array $data;

    #[Describe([Describe::default => 200])]
    public int $status;

    /** @var array<string, mixed> */
    #[Describe([Describe::default => []])]
    public array $headers;

    /** @var array<string, mixed> optional Route fluent builders on the returned Route */
    #[Describe([Describe::default => [], Describe::assign => [self::class, 'extractBuilders']])]
    public array $builders;

    /** @return list<mixed> Router::view($uri, $view, $data, $status, $headers) arguments in signature order. */
    public function arguments(): array
    {
        return [$this->uri, $this->view, $this->data, $this->status, $this->headers];
    }

    /** @param  array<string, mixed>  $context */
    public static function extractBuilders(mixed $val, array $context): array
    {
        return array_diff_key($context, [
            self::uri => true, self::view => true, self::data => true,
            self::status => true, self::headers => true,
        ]);
    }
}
```

```php
final readonly class RouteRedirect
{
    use DataModel;

    public const string uri = 'uri';
    public const string destination = 'destination';
    public const string status = 'status';
    public const string builders = 'builders';

    #[Describe([Describe::required => true])]
    public string $uri;

    #[Describe([Describe::required => true])]
    public string $destination;

    #[Describe([Describe::default => 302])]
    public int $status;

    /** @var array<string, mixed> */
    #[Describe([Describe::default => [], Describe::assign => [self::class, 'extractBuilders']])]
    public array $builders;

    /** @return list<mixed> Router::redirect($uri, $destination, $status) arguments in signature order. */
    public function arguments(): array
    {
        return [$this->uri, $this->destination, $this->status];
    }

    /** @param  array<string, mixed>  $context */
    public static function extractBuilders(mixed $val, array $context): array
    {
        return array_diff_key($context, [self::uri => true, self::destination => true, self::status => true]);
    }
}

final readonly class RoutePermanentRedirect
{
    use DataModel;

    public const string uri = 'uri';
    public const string destination = 'destination';
    public const string builders = 'builders';

    #[Describe([Describe::required => true])]
    public string $uri;

    #[Describe([Describe::required => true])]
    public string $destination;

    /** @var array<string, mixed> */
    #[Describe([Describe::default => [], Describe::assign => [self::class, 'extractBuilders']])]
    public array $builders;

    /** @return list<mixed> Router::permanentRedirect($uri, $destination) arguments in signature order. */
    public function arguments(): array
    {
        return [$this->uri, $this->destination];
    }

    /** @param  array<string, mixed>  $context */
    public static function extractBuilders(mixed $val, array $context): array
    {
        return array_diff_key($context, [self::uri => true, self::destination => true]);
    }
}
```

### 3.5 `src/Route.php` — one additive method (shipped class otherwise unchanged)

```php
/** @return list<mixed> Router::addRoute($methods, $uri, $action) arguments in signature order. */
public function arguments(): array
{
    if ($this->action === null) {
        throw new LogicException("Route for URI [{$this->uri}] must specify an action.");
    }

    // addRoute() does not normalize case; MethodValidator requires uppercase (declarative-routing.md §1.1).
    return [array_map(strtoupper(...), (array) $this->methods), $this->uri, $this->action];
}
```

### 3.6 `src/Manifest.php` — `routes` becomes the `Routes` model

```php
public const string routes = 'routes';

/** @var Routes */
#[Describe([
    Describe::default => [Routes::class, 'from'],
    Describe::cast => [Routes::class, 'fromRoutes'],
])]
public Routes $routes;
```

`RoutesDeclarationServiceProvider` is the only `Manifest->routes` consumer (verified across `src/`); `tests/Feature/RouteTest.php` updates with it.

### 3.7 `src/Providers/RoutesDeclarationServiceProvider.php` — the dynamic dispatch loop

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Closure;
use Illuminate\Routing\PendingResourceRegistration;
use Illuminate\Routing\PendingSingletonResourceRegistration;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use LogicException;
use UnitEnum;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\RouteGroup;
use ZeroToProd\LaravelDeclaration\RouteResource;
use ZeroToProd\LaravelDeclaration\Routes;

/** @internal */
class RoutesDeclarationServiceProvider extends ServiceProvider
{
    public function boot(?Manifest $Manifest = null, ?Router $Router = null): void
    {
        if (! $Manifest instanceof Manifest || ! $Router instanceof Router) {
            return;
        }

        $this->register($Router, $Manifest->routes);
    }

    /**
     * Dynamic dispatch (Design Rules 1–2): every `Routes` property names a
     * `Router` registration method; every item makes exactly one call with its
     * arguments in native signature order. The post-call seam follows the
     * call's return type: Route -> builders, Pending*Registration -> options,
     * Router (group) -> nested routes. Unknown Router methods fail with
     * Laravel's own exceptions (Rule 7).
     */
    private function register(Router $Router, Routes $Routes): void
    {
        foreach ($Routes->registrars() as $method => $items) {
            foreach ($items as $item) {
                match (true) {
                    $item instanceof RouteGroup
                        => $Router->group(
                            $item->attributes(),
                            fn (Router $Router) => $this->register($Router, $item->routes), // Rule 4: Closure where Laravel needs one
                        ),
                    $item instanceof RouteResource
                        => $this->registerResource($Router, $method, $item),
                    default
                        => $this->applyBuilders($Router->{$method}(...$item->arguments()), $item->builders),
                };
            }
        }
    }

    /** applyBuilder() for every builder key of a Route-returning registrar. */
    private function applyBuilders(Route $Route, array $builders): void
    {
        foreach ($builders as $method => $arguments) {
            $this->applyBuilder($Route, $method, $arguments);
        }
    }
            }
        }
    }

    /**
     * resource()/apiResource()/singleton()/apiSingleton() return a pending
     * registration; every declared option is one fluent method call on it.
     * Registration fires at destruction (PendingResourceRegistration::__destruct),
     * inside the active group stack.
     */
    private function registerResource(Router $Router, string $method, RouteResource $Resource): void
    {
        $Pending = $Router->{$method}($Resource->name, $Resource->controller);

        foreach ($Resource->options as $option => $arguments) {
            $this->applyPending($Pending, $option, $arguments);
        }
    }

    /** Same value shapes as applyBuilder(): flag, map-of-calls, spread, single value. */
    private function applyPending(
        PendingResourceRegistration|PendingSingletonResourceRegistration $Pending,
        string $method,
        mixed $arguments,
    ): void {
        if ($arguments === false || $arguments === null) {
            return;
        }

        if ($arguments === true) {
            $Pending->{$method}();

            return;
        }

        if ($method === 'missing' && is_string($arguments)) {
            $Pending->missing($this->wrapMissingHandler($arguments));

            return;
        }

        // Rule 2 map form for two-argument methods: map key = first argument ($methods).
        if ($method === 'middlewareFor' || $method === 'withoutMiddlewareFor') {
            foreach ((array) $arguments as $methods => $middleware) {
                $Pending->{$method}($methods, $middleware);
            }

            return;
        }

        if (is_array($arguments) && array_is_list($arguments)) {
            $Pending->{$method}(...$arguments);

            return;
        }

        $Pending->{$method}($arguments);
    }

    // applyBuilder() and wrapMissingHandler() carry over unchanged from the
    // shipped provider: same shape dispatch (flag / named-args / closure wrap /
    // list spread / single value) onto the returned Illuminate\Routing\Route.
}
```

The `default` arm is the shipped two-step call (`$Router->addRoute(...)` then the builder loop), generalized to every `Route`-returning registrar via `$item->arguments()`.

### 3.8 `manifest.schema.json` delta

```json
"routes": {
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "addRoute": {"type": "array", "items": {"$ref": "#/definitions/route"}},
    "group": {"type": "array", "items": {"$ref": "#/definitions/routeGroup"}},
    "resource": {"type": "array", "items": {"$ref": "#/definitions/routeResource"}},
    "apiResource": {"type": "array", "items": {"$ref": "#/definitions/routeResource"}},
    "singleton": {"type": "array", "items": {"$ref": "#/definitions/routeResource"}},
    "apiSingleton": {"type": "array", "items": {"$ref": "#/definitions/routeResource"}},
    "view": {"type": "array", "items": {"$ref": "#/definitions/routeView"}},
    "redirect": {"type": "array", "items": {"$ref": "#/definitions/routeRedirect"}},
    "permanentRedirect": {"type": "array", "items": {"$ref": "#/definitions/routeRedirect"}}
  }
}
```

```json
"routeGroup": {
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "prefix": {"type": "string"},
    "as": {"type": "string"},
    "namespace": {"type": "string"},
    "domain": {"type": "string"},
    "controller": {"type": "string"},
    "middleware": {"oneOf": [{"type": "string"}, {"type": "array", "items": {"type": "string"}}]},
    "where": {"type": "object", "additionalProperties": {"type": "string"}},
    "metadata": {"type": "object"},
    "routes": {"$ref": "#/definitions/routes"}
  }
},
"routeResource": {
  "type": "object",
  "additionalProperties": false,
  "required": ["name", "controller"],
  "properties": {
    "name": {"type": "string"},
    "controller": {"type": "string"},
    "options": {"type": "object", "additionalProperties": true}
  }
},
"routeView": {
  "type": "object",
  "additionalProperties": false,
  "required": ["uri", "view"],
  "properties": {
    "uri": {"type": "string"},
    "view": {"type": "string"},
    "data": {"type": "object"},
    "status": {"type": "integer"},
    "headers": {"type": "object", "additionalProperties": {"type": "string"}}
  }
},
"routeRedirect": {
  "type": "object",
  "additionalProperties": false,
  "required": ["uri", "destination"],
  "properties": {
    "uri": {"type": "string"},
    "destination": {"type": "string"},
    "status": {"type": "integer"}
  }
}
```

`options` stays open (`additionalProperties: true`) because it is a dynamic dispatch surface (§2.4 note 6); entry and attribute shapes stay closed for fail-fast validation.

---

## 4. Tests

Fixture `tests/Fixtures/manifest/route-registrars.yml` carries the §2.2 example; PEST style per `tests/Feature/RouteRegistrationTest.php`:

```php
$manifest = __DIR__.'/../Fixtures/manifest/route-registrars.yml';

it('registers the flat surface under addRoute', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    $this->get('/')->assertOk();
});

it('merges group attributes natively', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    $this->get('/admin/settings/profile')->assertOk();
    $Route = app(Router::class)->getRoutes()->getByName('admin.settings.profile');
    expect($Route->uri())->toBe('admin/settings/profile')
        ->and($Route->gatherMiddleware())->toContain('web', 'auth');
});

it('registers resource routes with pending options', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    $this->getJson('/photos/5')->assertOk()
        ->and($this->getJson('/photos/abc'))->toThrow(NotFoundHttpException::class);   // whereNumber
    expect(app(Router::class)->getRoutes()->hasNamedRoute('gallery.index'))->toBeTrue();
});

it('registers a resource inside a group', function () use ($manifest): void { /* /admin/users CRUD */ });

it('registers the native view shortcut', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    $this->get('/about')->assertOk()->assertHeader('X-Frame-Options', 'DENY')
        ->and($this->head('/about'))->assertOk();   // GET|HEAD only
});

it('registers redirect shortcuts', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    $this->get('/old-posts/7')->assertRedirect('/posts/7');      // 302, parameter carried
    $this->get('/legacy')->assertStatus(301);
});

it('fails an unknown pending option with Laravel\'s exception', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    expect(fn () => app(Router::class)->getRoutes())->toThrow(BadMethodCallException::class);
});

it('hydrates an empty routes block', function (): void {
    expect(Manifest::from([])->routes->addRoute)->toBe([])->and(Manifest::from([])->routes->group)->toBe([]);
});
```

Migration of existing surfaces (shape change, no shim — house precedent: the `path` → `uri` noun rename):

1. `tests/Fixtures/manifest/app.yml`, `router.yml`, `end-to-end.yml` — wrap each `routes:` list under `addRoute:`.
2. `tests/Feature/RouteTest.php` — `Manifest::from([])->routes->count()` becomes `->routes->addRoute` count (or `count($Manifest->routes->addRoute)`); add `Routes`/`RouteGroup`/`RouteResource` hydration tests.
3. `src/Providers/RoutesDeclarationServiceProvider.php` — replaced by §3.7; the guard early-return (`:21`) must stay covered to hold the 100% gate (gap inventory §5.6).

---

## 5. Verification log (v13.33.0)

All signatures and behaviors re-verified against vendor source on the date of this document:

1. `Router::redirect()` (:258) registers via `any()` → `addRoute(self::$verbs, ...)` (:232, `$verbs` at :136) — all seven verbs; `permanentRedirect()` (:272) delegates with status 301; `view()` (:287) registers `['GET','HEAD']` on `ViewController` with `setDefaults`, where an array `$status` carries the headers and status stays 200.
2. `Router::group(array $attributes, $routes)` (:472) → `updateGroupStack()` (:494) → `mergeWithLastGroup()` (:510) → `RouteGroup::merge()` (:17); Closure routes run as `$routes($this)` (:521-529); the array/string forms delegate to `RouteFileRegistrar::register()`, which only `require`s paths (RouteFileRegistrar.php:28-35) — hence the Closure seam.
3. `RouteGroup::merge` semantics verified per attribute: `formatPrefix` (:124), `formatAs` (:157), `formatNamespace` (:105), `formatWhere` (:142), `formatMetadata`/`mergeMetadata` (:53-87), domain/controller replacement (:19-21); route-level merge runs with `prependExistingPrefix: false` (Router.php:722-729) and group prefix reaches the URI via `Router::prefix($uri)` (:696-703).
4. All eight registrar signatures verified at Router.php:318/347/367/382/402/417/437/452; defaults at ResourceRegistrar.php:21/:28; `creatable`/`destroyable` singleton defaults at :163-166; `only`/`except` at `getResourceMethods()` (:270); option keys `wheres` (:651), `bindingFields` (:123, :560), `trashed` (:127-131), `missing` (:655), `metadata` (:659), `shallow` naming (:546-548), parameter wildcarding (:615).
5. `PendingResourceRegistration` fluent surface verified at PendingResourceRegistration.php:69-317 (`where` writes `wheres`, `scoped` writes `bindingFields`, `withTrashed` writes `trashed`); `PendingSingletonResourceRegistration::creatable()`/`destroyable()` (:94, :106); both use `Macroable` (:10) so unknown options throw `BadMethodCallException`; `__destruct()` → `register()` (:331).
6. `Router::get()`/`post()`/… are `__call`-routed into `RouteRegistrar` (Router.php:1497-1517) — basis for the verb-dispatch non-goal (§2.4 note 1).
7. The `DataModel` trait applies `Describe::default` before any cast and calls cast resolvers with `($value, $context, $Attribute, $Property)` (vendor `zero-to-prod/data-model/src/DataModel.php:222-246`) — basis for §3.1/§3.2 resolver signatures.

Post-implementation, update [declarative-tier1-gap-inventory.md](declarative-tier1-gap-inventory.md): §1 row 3 → `[x]`; §2.2 → resolved by this document (with the batch-form correction of §2.1); §3 row 2 → resolved; §4 item 3 → checked off. Run `composer check` to verify.