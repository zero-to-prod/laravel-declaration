# Declarative Route Registration — Acceptance Test Plan (`routes:`)

**Subject under test:** the manifest's `routes:` key — **implemented** (`[x]` in [README.md](../README.md#routes), Roadmap "HTTP Routing, Pipeline, URLs & Throttling"; mapped in [declarative-framework-api-mapping.md](declarative-framework-api-mapping.md) Domain "Route Registration"). The shipped block is a **map of native `Router` registration method names** — `addRoute`, `group`, `resource`, `apiResource`, `singleton`, `apiSingleton`, `view`, `redirect`, `permanentRedirect` ([declarative-route-registrars.md](declarative-route-registrars.md) §2) — dispatched by [RoutesDeclarationServiceProvider.php](../src/Providers/RoutesDeclarationServiceProvider.php) at boot as identically-named `Router` calls, in a fixed registrar order. The dispatch seam follows the native return type: `addRoute`/`view`/`redirect`/`permanentRedirect` return an `Illuminate\Routing\Route` whose fluent surface receives every remaining entry key as a **builder** call (dynamic dispatch, [declarative-routing.md](declarative-routing.md) §2); `group` recurses into its nested `routes` map with the group stack active; the resource family dispatches each `options` entry onto the `Pending*Registration` fluent surface, which registers at object destruction inside the group scope. Manifest snippets below use the shipped schema (`uri:` under registrar keys); the README's `## Routes` example shows the older flat-list shape whose `path:` key remains accepted as an `uri` alias.

**Source documentation:** [docs/repos/laravel/docs/routing.md](repos/laravel/docs/routing.md) — the vendored Laravel docs are the **system of record** for every behavior below; supporting clauses are cited from [controllers.md](repos/laravel/docs/controllers.md) (the resource-controller family and controller dependency injection), [middleware.md](repos/laravel/docs/middleware.md) (route middleware assignment and exclusion), [authorization.md](repos/laravel/docs/authorization.md) (the `can` middleware) and [session.md](repos/laravel/docs/session.md) (session blocking). This plan contains no behavior sourced from the framework code alone.

**Rule:** one **Given / When / Then** test per unique documented behavior. A test exists only where the vendored docs document the behavior; documented-but-non-declarable behavior is inventoried in §13 (G-1–G-8), and declared surface the vendored docs do not cover is likewise inventoried in §13 (G-9–G-11). Tests are not implemented here.

**Harness prerequisite:** route registration runs once at boot (`RoutesDeclarationServiceProvider::boot`), so every test's **Given** is a manifest declaring its routes before any request is made. The session-blocking tests (AT-30, AT-31) additionally require an atomic-lock-capable cache driver and a non-cookie session driver ([session.md — Session Blocking WARNING](repos/laravel/docs/session.md#session-blocking)); the caching tests (AT-44, AT-45) require an empty `bootstrap/cache` route file before `route:cache` runs.

---

## 1. Coverage map

| `routes:` surface | Documented behavior | Test |
|---|---|---|
| `addRoute` | a route serves its URI by dispatching its action | AT-01 |
| `addRoute` | `get`, `post`, `put`, `patch`, `delete`, `options` each register a route answering that verb | AT-02 |
| `addRoute` + `redirect` | verb routes defined before `any`/`match`/`redirect` routes ensure the request matches the correct route | AT-03 |
| `addRoute` | container dependencies are injected into the route's action | AT-04 |
| `addRoute.uri` | required parameters are captured and injected | AT-05 |
| `addRoute.uri` | multiple parameters are each injected | AT-06 |
| `addRoute.uri` | parameters are injected by order; argument names do not matter | AT-07 |
| `addRoute` action | route parameters are listed after container dependencies | AT-08 |
| `addRoute.uri` | optional `{name?}` parameters | AT-09 |
| `where` | regex constraints constrain a parameter's format | AT-10 |
| `where` | a non-matching constraint returns a 404 | AT-11 |
| `whereNumber`/`whereAlpha`/`whereAlphaNumeric`/`whereUuid`/`whereUlid`/`whereIn` | the preset constraint helpers | AT-12 |
| `where` | an explicit `.*` allows `/` within the last segment | AT-13 |
| `name` | names enable URL/redirect generation via `route()`/`to_route()` | AT-14 |
| `name` | parameters are inserted into generated URLs in their correct positions | AT-15 |
| `name` | extra parameters become the query string | AT-16 |
| `name` | the current request's route is inspected via `named()` | AT-17 |
| `group.middleware` | group middleware applies to every route in the group, in listed order | AT-18 |
| `group.controller` | a group controller serves bare method names | AT-19 |
| `group.domain` / `domain` | subdomain routing with captured parameters | AT-20 |
| `group.prefix` / `prefix` | URIs are prefixed | AT-21 |
| `group.as` | route names are prefixed exactly as specified | AT-22 |
| `group` (nested) | prefixes and names append; separators added automatically | AT-23 |
| `group` (nested) | middleware and `where` conditions merge | AT-24 |
| `middleware` | middleware assigned to a route runs | AT-25 |
| `withoutMiddleware` | route middleware excluded from an individual route within a group | AT-26 |
| `withoutMiddleware` | does not apply to global middleware | AT-27 |
| `can` | `can:{ability},{param}` authorizes before the route; denial returns 403 | AT-28 |
| `can` | a class-string authorizes actions that don't require models | AT-29 |
| `block` | concurrent same-session requests serialize on a session lock | AT-30 |
| `block` | omitted arguments default to a 10-second lock and 10-second wait | AT-31 |
| `addRoute.uri` + action type-hint | implicit binding injects the model | AT-32 |
| — | a not-found implicit binding returns a 404 | AT-33 |
| `withTrashed` | soft-deleted models are retrievable | AT-34 |
| `addRoute.uri` custom key | `{post:slug}` binds by a column other than `id` | AT-35 |
| `scopeBindings` | child bindings are scoped to the parent | AT-36 |
| `withoutScopedBindings` | scoping is explicitly disabled | AT-37 |
| `missing` | the missing handler replaces the 404 for a not-found binding | AT-38 |
| enum binding | a valid enum segment invokes the route | AT-39 |
| enum binding | an invalid enum segment returns a 404 | AT-40 |
| `fallback` | the fallback route executes when no other route matches | AT-41 |
| `name`/`action` | `Route::current`/`currentRouteName`/`currentRouteAction` observe the handling route | AT-42 |
| — | `route:list` lists the declared routes | AT-43 |
| — | cached routes serve; `route:clear` restores live registration | AT-44 |
| — | new routes require a fresh route cache | AT-45 |
| — | `_method` spoofing reaches the declared verb | AT-46 |
| `resource` | the seven documented CRUD routes (verbs/URIs/actions/names) | AT-47 |
| `resource.options.only` / `except` | partial resource routes | AT-48 |
| `apiResource` | `create`/`edit` are excluded | AT-49 |
| `resource.name` (dot notation) | nested resource URIs | AT-50 |
| `resource.options.shallow` | shallow nesting | AT-51 |
| `resource.options.scoped` | scoped nested-resource binding | AT-52 |
| `resource.options.names` | resource route names overridden | AT-53 |
| `resource.options.parameters` | resource parameter renamed | AT-54 |
| `resource.options.withTrashed` | soft-deleted models allowed, optionally per-method | AT-55 |
| `resource.options.missing` | the resource missing handler | AT-56 |
| `singleton` | `show`/`edit`/`update` without an identifier or creation routes | AT-57 |
| `singleton` (nested) | nested singleton URIs | AT-58 |
| `singleton.options.creatable` | `create`/`store`/`destroy` added | AT-59 |
| `singleton.options.destroyable` | `destroy` added without creation routes | AT-60 |
| `apiSingleton` | `create`/`edit` rendered unnecessary | AT-61 |
| `resource.options.middleware` | middleware on all resource methods | AT-62 |
| `resource.options.middlewareFor` | middleware on specific methods | AT-63 |
| `resource.options.withoutMiddlewareFor` | middleware excluded from specific methods | AT-64 |
| registrar order | supplemental routes declared before the resource take precedence | AT-65 |

---

## 2. Route registration & dispatch ([routing.md — Basic Routing](repos/laravel/docs/routing.md#basic-routing); [The Default Route Files](repos/laravel/docs/routing.md#the-default-route-files); [Available Router Methods](repos/laravel/docs/routing.md#available-router-methods); [Dependency Injection](repos/laravel/docs/routing.md#dependency-injection))

### AT-01 — a declared route serves its URI by dispatching its action

**Doc says:** "The routes defined in `routes/web.php` may be accessed by entering the defined route's URL in your browser. For example, you may access the following route by navigating to `http://example.com/user` in your browser" — `Route::get('/user', [UserController::class, 'index']);`.

- **Given** the manifest declares `routes.addRoute` with `uri: user`, `methods: GET`, `action: [UserController, index]`.
- **When** the application is requested at `/user`.
- **Then** the request is served by `UserController::index` — the declared route answered the entered URL with its controller action.

Sources: [routing.md — The Default Route Files](repos/laravel/docs/routing.md#the-default-route-files).

### AT-02 — each HTTP verb registers a route answering that verb

**Doc says:** "The router allows you to register routes that respond to any HTTP verb" — `Route::get($uri, $callback);` `Route::post(...)` `Route::put(...)` `Route::patch(...)` `Route::delete(...)` `Route::options(...)`.

- **Given** the manifest declares six `routes.addRoute` entries on six distinct URIs, one per verb — `methods: GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS` — each action recording its invocation.
- **When** each URI is requested with its declared verb.
- **Then** every request reaches its declared action — each verb method registered a route that responds to that verb.

Sources: [routing.md — Available Router Methods](repos/laravel/docs/routing.md#available-router-methods).

### AT-03 — verb routes defined before `any`/`match`/`redirect` routes match deterministically

**Doc says:** "> [!NOTE] When defining multiple routes that share the same URI, routes using the `get`, `post`, `put`, `patch`, `delete`, and `options` methods should be defined before routes using the `any`, `match`, and `redirect` methods. This ensures the incoming request is matched with the correct route." The declarative encoding is the fixed registrar order: `addRoute` entries dispatch before `redirect` entries ([declarative-framework-api-mapping.md](declarative-framework-api-mapping.md) — "a `redirect` entry (all seven verbs via `any()`) always overrides an `addRoute` GET on the same URI").

- **Given** the manifest declares an `routes.addRoute` GET entry and a `routes.redirect` entry on the **same URI**, in the documented order (the registrar order places the verb route first).
- **When** that URI is requested with `GET`.
- **Then** the request is matched with exactly one route — the redirect route, the later-defined all-verb route per the documented ordering — deterministically, regardless of the entries' order within the manifest.

Sources: [routing.md — Available Router Methods (NOTE)](repos/laravel/docs/routing.md#available-router-methods); [declarative-framework-api-mapping.md — Route Registration](declarative-framework-api-mapping.md).

### AT-04 — container dependencies are injected into the route's action

**Doc says:** "You may type-hint any dependencies required by your route in your route's callback signature. The declared dependencies will automatically be resolved and injected into the callback by the Laravel [service container]." ([controllers.md — Constructor Injection](repos/laravel/docs/controllers.md#constructor-injection): "The Laravel service container is used to resolve all Laravel controllers. As a result, you are able to type-hint any dependencies your controller may need in its constructor." [controllers.md — Method Injection](repos/laravel/docs/controllers.md#method-injection): "you may also type-hint dependencies on your controller's methods. A common use-case for method injection is injecting the `Illuminate\Http\Request` instance").

- **Given** the manifest declares a route whose action is a controller with a constructor type-hint (a container-bound dependency) and a method type-hinting `Illuminate\Http\Request`.
- **When** the route is requested.
- **Then** the action receives the container-resolved dependency and the current `Request` instance — the declared dependencies were automatically resolved and injected.

Sources: [routing.md — Dependency Injection](repos/laravel/docs/routing.md#dependency-injection); [controllers.md — Constructor Injection](repos/laravel/docs/controllers.md#constructor-injection); [controllers.md — Method Injection](repos/laravel/docs/controllers.md#method-injection).

---

## 3. Route parameters ([routing.md — Route Parameters](repos/laravel/docs/routing.md#route-parameters))

### AT-05 — required parameters are captured and injected

**Doc says:** "Sometimes you will need to capture segments of the URI within your route... You may do so by defining route parameters" — `Route::get('/user/{id}', function (string $id) { return 'User '.$id; });`.

- **Given** the manifest declares `uri: "users/{user}"` with an action echoing its received value.
- **When** `/users/7` is requested.
- **Then** the action received `7` — the `{user}` segment was captured from the URI and injected.

Sources: [routing.md — Required Parameters](repos/laravel/docs/routing.md#required-parameters).

### AT-06 — multiple parameters are each injected

**Doc says:** "You may define as many route parameters as required by your route" — `Route::get('/posts/{post}/comments/{comment}', function (string $postId, string $commentId) {...});`.

- **Given** the manifest declares `uri: "posts/{post}/comments/{comment}"` with an action receiving two values.
- **When** `/posts/1/comments/9` is requested.
- **Then** the action receives `1` and `9` in URI order — both segments were captured.

Sources: [routing.md — Required Parameters](repos/laravel/docs/routing.md#required-parameters).

### AT-07 — parameters are injected by order; argument names do not matter

**Doc says:** "Route parameters are injected into route callbacks / controllers based on their order - the names of the route callback / controller arguments do not matter."

- **Given** the manifest declares `uri: "posts/{post}/comments/{comment}"` whose action names its arguments `$first` and `$second`.
- **When** `/posts/1/comments/9` is requested.
- **Then** `$first` received `1` and `$second` received `9` — the values arrived by parameter order, not by argument name.

Sources: [routing.md — Required Parameters](repos/laravel/docs/routing.md#required-parameters).

### AT-08 — route parameters are listed after container dependencies

**Doc says:** "If your route has dependencies that you would like the Laravel service container to automatically inject into your route's callback, you should list your route parameters after your dependencies" — `Route::get('/user/{id}', function (Request $request, string $id) {...});`. ([controllers.md — Method Injection](repos/laravel/docs/controllers.md#method-injection): "If your controller method is also expecting input from a route parameter, list your route arguments after your other dependencies.")

- **Given** the manifest declares `uri: "users/{user}"` whose action signature is `(Request $request, string $user)`.
- **When** `/users/7` is requested.
- **Then** the action receives the injected `Request` **and** `7` — the route parameter resolved after the container dependencies.

Sources: [routing.md — Parameters and Dependency Injection](repos/laravel/docs/routing.md#parameters-and-dependency-injection); [controllers.md — Method Injection](repos/laravel/docs/controllers.md#method-injection).

### AT-09 — optional parameters

**Doc says:** "Occasionally you may need to specify a route parameter that may not always be present in the URI. You may do so by placing a `?` mark after the parameter name. Make sure to give the route's corresponding variable a default value" — `Route::get('/user/{name?}', function (?string $name = null) { return $name; });`.

- **Given** the manifest declares `uri: "user/{name?}"` whose action accepts an optional string with a default.
- **When** `/user` is requested, and then `/user/john` is requested.
- **Then** the segmentless request serves with the default value (`null`), and `/user/john` serves with `john` — the parameter was optional in the URI.

Sources: [routing.md — Optional Parameters](repos/laravel/docs/routing.md#parameters-optional-parameters).

### AT-10 — `where` regex constraints constrain a parameter's format

**Doc says:** "You may constrain the format of your route parameters using the `where` method on a route instance. The `where` method accepts the name of the parameter and a regular expression defining how the parameter should be constrained" — `->where('user', '[0-9]+')`.

- **Given** the manifest declares `uri: "users/{user}"` with `where: {user: '[0-9]+'}`.
- **When** `/users/7` is requested.
- **Then** the request is served — the numeric segment matched the declared constraint.

Sources: [routing.md — Regular Expression Constraints](repos/laravel/docs/routing.md#parameters-regular-expression-constraints).

### AT-11 — a non-matching constraint returns a 404

**Doc says:** "If the incoming request does not match the route pattern constraints, a 404 HTTP response will be returned."

- **Given** the AT-10 route.
- **When** `/users/some-slug` is requested.
- **Then** the response is a 404 — the segment did not match the route pattern constraint.

Sources: [routing.md — Regular Expression Constraints](repos/laravel/docs/routing.md#parameters-regular-expression-constraints).

### AT-12 — the preset constraint helpers

**Doc says:** "For convenience, some commonly used regular expression patterns have helper methods that allow you to quickly add pattern constraints to your routes" — `->whereNumber('id')`, `->whereAlpha('name')`, `->whereAlphaNumeric('name')`, `->whereUuid('id')`, `->whereUlid('id')`, `->whereIn('category', ['movie', 'song', 'painting'])`.

- **Given** the manifest declares six routes, each with one helper builder key (`whereNumber`, `whereAlpha`, `whereAlphaNumeric`, `whereUuid`, `whereUlid`, `whereIn` with its values list) applied to its parameter.
- **When** each route is requested with a segment matching its documented pattern (a number, alphabetic string, alphanumeric string, UUID, ULID, and one of the enumerated values respectively).
- **Then** every request is served — each helper applied its preset pattern constraint.

Sources: [routing.md — Regular Expression Constraints](repos/laravel/docs/routing.md#parameters-regular-expression-constraints).

### AT-13 — an explicit `.*` constraint allows `/` within the last segment

**Doc says:** "The Laravel routing component allows all characters except `/` to be present within route parameter values. You must explicitly allow `/` to be part of your placeholder using a `where` condition regular expression" — `->where('search', '.*');` "> [!WARNING] Encoded forward slashes are only supported within the last route segment."

- **Given** the manifest declares `uri: "search/{search}"` with `where: {search: '.*'}`.
- **When** `/search/a/b` is requested.
- **Then** the request is served with `a/b` as the parameter value — the explicit `.*` condition allowed the forward slash in the last segment.

Sources: [routing.md — Encoded Forward Slashes](repos/laravel/docs/routing.md#parameters-encoded-forward-slashes).

---

## 4. Named routes ([routing.md — Named Routes](repos/laravel/docs/routing.md#named-routes))

### AT-14 — the declared name enables URL and redirect generation

**Doc says:** "Named routes allow the convenient generation of URLs or redirects for specific routes. You may specify a name for a route by chaining the `name` method onto the route definition" — `->name('profile');` "you may use the route's name when generating URLs or redirects via Laravel's `route` and `redirect` helper functions" — `$url = route('profile');` `return to_route('profile');`.

- **Given** the manifest declares `uri: "user/profile"` with `name: profile`.
- **When** `route('profile')` is called (and `to_route('profile')` is returned from an action).
- **Then** `route('profile')` returns the route's URL and `to_route('profile')` produces a redirect to it — the name was registered on the route.

Sources: [routing.md — Named Routes](repos/laravel/docs/routing.md#named-routes); [routing.md — Generating URLs to Named Routes](repos/laravel/docs/routing.md#generating-urls-to-named-routes).

### AT-15 — parameters are inserted into generated URLs in their correct positions

**Doc says:** "If the named route defines parameters, you may pass the parameters as the second argument to the `route` function. The given parameters will automatically be inserted into the generated URL in their correct positions" — `route('profile', ['id' => 1]);`.

- **Given** the manifest declares `uri: "user/{id}/profile"` with `name: profile`.
- **When** `route('profile', ['id' => 1])` is called.
- **Then** the generated URL is `/user/1/profile` — the parameter was inserted into its position in the URI.

Sources: [routing.md — Generating URLs to Named Routes](repos/laravel/docs/routing.md#generating-urls-to-named-routes).

### AT-16 — extra parameters become the query string

**Doc says:** "If you pass additional parameters in the array, those key / value pairs will automatically be added to the generated URL's query string" — `route('profile', ['id' => 1, 'photos' => 'yes']);` → `http://example.com/user/1/profile?photos=yes`.

- **Given** the AT-15 route.
- **When** `route('profile', ['id' => 1, 'photos' => 'yes'])` is called.
- **Then** the generated URL carries `?photos=yes` after the parameter-filled path.

Sources: [routing.md — Generating URLs to Named Routes](repos/laravel/docs/routing.md#generating-urls-to-named-routes).

### AT-17 — the current request's route is inspected via `named()`

**Doc says:** "If you would like to determine if the current request was routed to a given named route, you may use the `named` method on a Route instance. For example, you may check the current route name from a route middleware" — `if ($request->route()->named('profile')) {...}`.

- **Given** the AT-14 route, a recording middleware that snapshots `$request->route()->named('profile')`, and a second unnamed route behind the same middleware.
- **When** both routes are requested.
- **Then** the snapshot is `true` for the named route and `false` for the other — `named()` identified the current route.

Sources: [routing.md — Inspecting the Current Route](repos/laravel/docs/routing.md#inspecting-the-current-route).

---

## 5. Route groups ([routing.md — Route Groups](repos/laravel/docs/routing.md#route-groups))

> The declarative group is one `routes.group` entry whose attribute keys are the group attributes and whose reserved `routes` key holds the nested declarations ([declarative-route-registrars.md](declarative-route-registrars.md) §2.3). The group's name-prefix attribute is spelled `as` — the group stack's native attribute name.

### AT-18 — group middleware applies to every route in the group, in listed order

**Doc says:** "To assign [middleware] to all routes within a group, you may use the `middleware` method before defining the group. Middleware are executed in the order they are listed in the array."

- **Given** the manifest declares `routes.group` with `middleware: [First, Second]` — recording before-middleware — and two nested `addRoute` entries with different paths and actions.
- **When** both routes are requested.
- **Then** `First` and `Second` both ran on each request, in that listed order — the middleware applied to all routes within the group.

Sources: [routing.md — Middleware (Route Groups)](repos/laravel/docs/routing.md#route-group-middleware).

### AT-19 — a group controller serves bare method names

**Doc says:** "If a group of routes all utilize the same [controller], you may use the `controller` method to define the common controller for all of the routes within the group. Then, when defining the routes, you only need to provide the controller method that they invoke" — `Route::controller(OrderController::class)->group(...)` with `Route::get('/orders/{id}', 'show');`.

- **Given** the manifest declares `routes.group` with `controller: App\Http\Controllers\OrderController` and a nested `addRoute` entry whose action is the bare method string `show`.
- **When** the route is requested.
- **Then** `OrderController::show` handled the request — the bare method name was resolved against the group controller.

Sources: [routing.md — Controllers (Route Groups)](repos/laravel/docs/routing.md#route-group-controllers).

### AT-20 — subdomain routing with captured parameters

**Doc says:** "Route groups may also be used to handle subdomain routing. Subdomains may be assigned route parameters just like route URIs, allowing you to capture a portion of the subdomain for usage in your route or controller. The subdomain may be specified by calling the `domain` method before defining the group" — `Route::domain('{account}.example.com')->group(...)`.

- **Given** the manifest declares a `routes.group` entry with `domain: "{account}.example.com"` and one nested route, plus an ungrouped `addRoute` entry with the route-level `domain:` key (the same `Route::domain()` method applied to the single route).
- **When** each route is requested with a `Host` header of `acme.example.com`.
- **Then** both actions receive `acme` as their `{account}` value — the subdomain portion was captured as a route parameter.

Sources: [routing.md — Subdomain Routing](repos/laravel/docs/routing.md#route-group-subdomain-routing).

### AT-21 — URIs are prefixed

**Doc says:** "The `prefix` method may be used to prefix each route in the group with a given URI. For example, you may want to prefix all route URIs within the group with `admin`" — `Route::prefix('admin')->group(...)` "Matches The '/admin/users' URL".

- **Given** the manifest declares a `routes.group` entry with `prefix: admin` containing a nested `users` route, plus an ungrouped `addRoute` entry with the route-level `prefix: admin` key.
- **When** `/admin/users` is requested (and the route-level route is requested under its prefixed URI).
- **Then** both routes serve — each URI was prefixed with `admin`.

Sources: [routing.md — Route Prefixes](repos/laravel/docs/routing.md#route-group-prefixes).

### AT-22 — route names are prefixed exactly as specified

**Doc says:** "The `name` method may be used to prefix each route name in the group with a given string... The given string is prefixed to the route name exactly as it is specified, so we will be sure to provide the trailing `.` character in the prefix" — `Route::name('admin.')->group(...)` "Route assigned name 'admin.users'".

- **Given** the manifest declares `routes.group` with `as: admin.` and a nested route named `users`.
- **When** `route('admin.users')` is called (the group attribute is the group stack's native `as`, prefixed verbatim).
- **Then** the named route resolves — the route's name is `admin.users`, the group prefix concatenated with the declared name exactly as specified.

Sources: [routing.md — Route Name Prefixes](repos/laravel/docs/routing.md#route-group-name-prefixes).

### AT-23 — nested groups append prefixes and names, adding separators

**Doc says:** "Nested groups attempt to intelligently 'merge' attributes with their parent group. Middleware and `where` conditions are merged while names and prefixes are appended. Namespace delimiters and slashes in URI prefixes are automatically added where appropriate."

- **Given** the manifest declares a nested `routes.group` (prefix `settings`, `as: settings.`, `namespace: App\Http\Controllers\Admin\Settings`) inside a parent group (prefix `admin`, `as: admin.`, `namespace: App\Http\Controllers\Admin`) containing a `profile` route with a bare controller name.
- **When** `/admin/settings/profile` is requested.
- **Then** the request is served by the namespaced controller — the child prefix appended to the parent's (`admin/settings`, slash added), the child name prefix appended to the parent's (`admin.settings.profile`), and the namespace concatenated with the `\` delimiter.

Sources: [routing.md — Route Groups](repos/laravel/docs/routing.md#route-groups).

### AT-24 — nested groups merge middleware and `where` conditions

**Doc says:** "Middleware and `where` conditions are merged while names and prefixes are appended."

- **Given** the manifest declares a nested group under a parent where the parent declares `middleware: [Parent]` and `where: {id: '[0-9]+'}`, and the child declares `middleware: [Child]` and `where: {slug: '[a-z]+'}`; the nested route carries both an `{id}` and a `{slug}` parameter.
- **When** the route is requested with a matching URI (numeric `id`, lowercase `slug`).
- **Then** both `Parent` and `Child` middleware ran, and both constraints applied (a non-numeric `id` or non-lowercase `slug` request instead returns a 404, per AT-11's constraint behavior) — the conditions merged rather than replaced.

Sources: [routing.md — Route Groups](repos/laravel/docs/routing.md#route-groups).

---

## 6. Route-level middleware & guards ([middleware.md](repos/laravel/docs/middleware.md); [authorization.md](repos/laravel/docs/authorization.md); [session.md](repos/laravel/docs/session.md))

### AT-25 — middleware assigned to a route runs

**Doc says:** "If you would like to assign middleware to specific routes, you may invoke the `middleware` method when defining the route" — `Route::get('/profile', ...)->middleware(EnsureTokenIsValid::class);` "You may assign multiple middleware to the route by passing an array of middleware names".

- **Given** the manifest declares a route with `middleware: [EnsureTokenIsValid]` — the doc's example middleware, which redirects tokenless requests.
- **When** the route is requested without the token, and then with the matching token.
- **Then** the tokenless request receives the middleware's redirect and the tokened request reaches the action — the assigned middleware inspected the request.

Sources: [middleware.md — Assigning Middleware to Routes](repos/laravel/docs/middleware.md#assigning-middleware-to-routes); [middleware.md — Defining Middleware](repos/laravel/docs/middleware.md#defining-middleware).

### AT-26 — `withoutMiddleware` excludes route middleware from an individual route within a group

**Doc says:** "When assigning middleware to a group of routes, you may occasionally need to prevent the middleware from being applied to an individual route within the group. You may accomplish this using the `withoutMiddleware` method."

- **Given** the manifest declares a group carrying `EnsureTokenIsValid` and one nested route declaring `withoutMiddleware: [EnsureTokenIsValid]`.
- **When** that route is requested without the token.
- **Then** the request reaches the action — the excluded middleware was not applied to it, while sibling routes in the group still enforce it.

Sources: [middleware.md — Excluding Middleware](repos/laravel/docs/middleware.md#excluding-middleware).

### AT-27 — `withoutMiddleware` does not apply to global middleware

**Doc says:** "The `withoutMiddleware` method can only remove route middleware and does not apply to [global middleware]."

- **Given** the AT-26 application where `EnsureTokenIsValid` is instead registered as **global** middleware ([middleware.md — Global Middleware](repos/laravel/docs/middleware.md#global-middleware)) and the route still declares `withoutMiddleware: [EnsureTokenIsValid]`.
- **When** the route is requested without the token.
- **Then** the request is still rejected by the middleware — the exclusion could not remove global middleware.

Sources: [middleware.md — Excluding Middleware](repos/laravel/docs/middleware.md#excluding-middleware).

### AT-28 — `can` authorizes before the route; denial returns 403

**Doc says:** "Laravel includes a middleware that can authorize actions before the incoming request even reaches your routes or controllers... attached to a route using the `can` [middleware alias]" — `->middleware('can:update,post');` "we're passing the `can` middleware two arguments. The first is the name of the action we wish to authorize and the second is the route parameter we wish to pass to the policy method... If the user is not authorized to perform the given action, an HTTP response with a 403 status code will be returned by the middleware." "For convenience, you may also attach the `can` middleware to your route using the `can` method" — `->can('update', 'post');`.

- **Given** the manifest declares `uri: "post/{post}"` with `can: {ability: update, models: post}` (the `can`-method form expanding to `can:update,post`) and a policy denying `update` for the requesting user.
- **When** the route is requested.
- **Then** the response is a 403 and the action never ran — the `Authorize` middleware authorized the action before the request reached the route or controller, passing the implicitly bound model to the policy method.

Sources: [authorization.md — Via Middleware](repos/laravel/docs/authorization.md#via-middleware).

### AT-29 — a class-string authorizes actions that don't require models

**Doc says:** "Again, some policy methods like `create` do not require a model instance. In these situations, you may pass a class name to the middleware. The class name will be used to determine which policy to use when authorizing the action" — `->middleware('can:create,App\Models\Post');` "you may choose to attach the `can` middleware to your route using the `can` method" — `->can('create', Post::class);`.

- **Given** the manifest declares a route with `can: {ability: create, models: App\Models\Post}` and a policy denying `create` on `App\Models\Post`.
- **When** the route is requested.
- **Then** the response is a 403 — the class name determined the policy used to authorize the model-less action.

Sources: [authorization.md — Actions That Don't Require Models](repos/laravel/docs/authorization.md#middleware-actions-that-dont-require-models).

### AT-30 — concurrent same-session requests serialize on a session lock

**Doc says:** "By default, Laravel allows requests using the same session to execute concurrently... To get started, you may simply chain the `block` method onto your route definition. In this example, an incoming request to the `/profile` endpoint would acquire a session lock. While this lock is being held, any incoming requests to the `/profile` or `/order` endpoints which share the same session ID will wait for the first request to finish executing before continuing their execution" — `->block($lockSeconds = 10, $waitSeconds = 10);`.

- **Given** the manifest declares two routes (`/profile`, `/order`) each with `block: {lockSeconds: 10, waitSeconds: 10}`, sharing a session ID; `/profile`'s action sleeps before responding while both actions record their completion order; the harness uses an atomic-lock-capable cache driver and a non-cookie session driver ([session.md — Session Blocking WARNING](repos/laravel/docs/session.md#session-blocking)).
- **When** both endpoints are requested concurrently with the same session ID.
- **Then** the requests executed one after the other — the second waited for the first request to finish executing before continuing (the lock is per session and spans blocked routes).

Sources: [session.md — Session Blocking](repos/laravel/docs/session.md#session-blocking).

### AT-31 — omitted `block` arguments default to a 10-second lock and 10-second wait

**Doc says:** "If neither of these arguments is passed, the lock will be obtained for a maximum of 10 seconds and requests will wait a maximum of 10 seconds while attempting to obtain a lock" — `->block();`.

- **Given** the AT-30 routes declared with `block: true` (the zero-argument `block()` call) instead of explicit durations.
- **When** two same-session requests race.
- **Then** the requests still serialize — the lock was obtained with the 10-second maximums applied by default.

Sources: [session.md — Session Blocking](repos/laravel/docs/session.md#session-blocking).

---

## 7. Route model binding ([routing.md — Route Model Binding](repos/laravel/docs/routing.md#route-model-binding))

### AT-32 — implicit binding injects the model

**Doc says:** "Laravel automatically resolves Eloquent models defined in routes or controller actions whose type-hinted variable names match a route segment name... Laravel will automatically inject the model instance that has an ID matching the corresponding value from the request URI."

- **Given** the manifest declares `uri: "users/{user}"` whose action type-hints `App\Models\User $user`.
- **When** `/users/1` is requested.
- **Then** the action receives the `User` instance with ID 1 — the type-hint matched the `{user}` segment name and the model was resolved from the database.

Sources: [routing.md — Implicit Binding](repos/laravel/docs/routing.md#implicit-binding).

### AT-33 — a not-found implicit binding returns a 404

**Doc says:** "If a matching model instance is not found in the database, a 404 HTTP response will automatically be generated."

- **Given** the AT-32 route and no `User` with ID 99.
- **When** `/users/99` is requested.
- **Then** the response is a 404 and the action never ran.

Sources: [routing.md — Implicit Binding](repos/laravel/docs/routing.md#implicit-binding).

### AT-34 — `withTrashed` retrieves soft-deleted models

**Doc says:** "Typically, implicit model binding will not retrieve models that have been [soft deleted]. However, you may instruct the implicit binding to retrieve these models by chaining the `withTrashed` method onto your route's definition" — `->withTrashed();`.

- **Given** the AT-32 route declared with `withTrashed: true` and a soft-deleted `User` with ID 1.
- **When** `/users/1` is requested.
- **Then** the soft-deleted model is injected — without `withTrashed` the same request returns a 404 (the default binding behavior).

Sources: [routing.md — Implicit Soft Deleted Models](repos/laravel/docs/routing.md#implicit-soft-deleted-models).

### AT-35 — a custom key binds by a column other than `id`

**Doc says:** "Sometimes you may wish to resolve Eloquent models using a column other than `id`. To do so, you may specify the column in the route parameter definition" — `Route::get('/posts/{post:slug}', function (Post $post) {...});`.

- **Given** the manifest declares `uri: "posts/{post:slug}"` whose action type-hints `App\Models\Post $post`.
- **When** `/posts/first-post` is requested.
- **Then** the action receives the post whose `slug` is `first-post` — the custom key resolved the model by that column.

Sources: [routing.md — Customizing the Key](repos/laravel/docs/routing.md#customizing-the-default-key-name).

### AT-36 — `scopeBindings` scopes child bindings to the parent

**Doc says:** "When implicitly binding multiple Eloquent models in a single route definition, you may wish to scope the second Eloquent model such that it must be a child of the previous Eloquent model... you may instruct Laravel to scope 'child' bindings even when a custom key is not provided. To do so, you may invoke the `scopeBindings` method when defining your route" — `->scopeBindings();`.

- **Given** the manifest declares `uri: "users/{user}/posts/{post}"` with `scopeBindings: true`, two users, and a post belonging only to user 1.
- **When** `/users/2/posts/<that-post-id>` is requested, and then `/users/1/posts/<that-post-id>`.
- **Then** the first request returns a 404 — the child binding was scoped to the parent and did not belong to it — while the second request injects the post.

Sources: [routing.md — Custom Keys and Scoping](repos/laravel/docs/routing.md#implicit-model-binding-scoping).

### AT-37 — `withoutScopedBindings` explicitly disables scoping

**Doc says:** "Similarly, you may explicitly instruct Laravel to not scope bindings by invoking the `withoutScopedBindings` method."

- **Given** the AT-36 route shape with a custom key (`{post:slug}`, which scopes automatically) declared with `withoutScopedBindings: true`, and the same two users.
- **When** `/users/2/posts/<a-slug-belonging-only-to-user-1>` is requested.
- **Then** the request is served with the post injected — the binding was resolved without parent scoping.

Sources: [routing.md — Custom Keys and Scoping](repos/laravel/docs/routing.md#implicit-model-binding-scoping).

### AT-38 — the missing handler replaces the 404 for a not-found binding

**Doc says:** "Typically, a 404 HTTP response will be generated if an implicitly bound model is not found. However, you may customize this behavior by calling the `missing` method when defining your route. The `missing` method accepts a closure that will be invoked if an implicitly bound model cannot be found" — `->missing(function (Request $request) { return Redirect::route('locations.index'); });`.

- **Given** the manifest declares `uri: "locations/{location:slug}"` with `missing:` set to an invokable handler class that redirects to the index route.
- **When** a URI whose model cannot be found is requested.
- **Then** the declared handler's redirect response is returned instead of the 404 — the missing behavior was customized.

Sources: [routing.md — Customizing Missing Model Behavior](repos/laravel/docs/routing.md#customizing-missing-model-behavior).

### AT-39 — a valid enum segment invokes the route

**Doc says:** "Laravel allows you to type-hint a [string-backed Enum] on your route definition and Laravel will only invoke the route if that route segment corresponds to a valid Enum value" — given `enum Category: string { case Fruits = 'fruits'; case People = 'people'; }`.

- **Given** the manifest declares `uri: "categories/{category}"` whose action type-hints `App\Enums\Category $category`.
- **When** `/categories/fruits` is requested.
- **Then** the route is invoked with the `Category::Fruits` case — the segment corresponded to a valid enum value.

Sources: [routing.md — Implicit Enum Binding](repos/laravel/docs/routing.md#implicit-enum-binding).

### AT-40 — an invalid enum segment returns a 404

**Doc says:** "Otherwise, a 404 HTTP response will be returned automatically."

- **Given** the AT-39 route.
- **When** `/categories/vehicles` is requested.
- **Then** the response is a 404 and the action never ran — the segment did not correspond to a valid enum value.

Sources: [routing.md — Implicit Enum Binding](repos/laravel/docs/routing.md#implicit-enum-binding).

---

## 8. Fallback routes ([routing.md — Fallback Routes](repos/laravel/docs/routing.md#fallback-routes))

### AT-41 — the fallback route executes when no other route matches

**Doc says:** "Using the `Route::fallback` method, you may define a route that will be executed when no other route matches the incoming request. Typically, unhandled requests will automatically render a '404' page via your application's exception handler."

- **Given** the manifest declares an `addRoute` entry with `uri: "{any}"`, `where: {any: '.*'}` and `fallback: true` alongside an action-bearing route (a declared fallback requires an action; the `.*` constraint mirrors `Router::fallback()`'s multi-segment match — [declarative-routing.md](declarative-routing.md) §2.3).
- **When** an unregistered URI is requested, and then the registered route is requested.
- **Then** the unregistered request is served by the fallback action instead of the exception handler's 404, while the registered route still serves its own action.

Sources: [routing.md — Fallback Routes](repos/laravel/docs/routing.md#fallback-routes).

---

## 9. Registration observability ([routing.md — Accessing the Current Route](repos/laravel/docs/routing.md#accessing-the-current-route); [Listing Your Routes](repos/laravel/docs/routing.md#listing-your-routes))

### AT-42 — the `Route` facade observes the handling route

**Doc says:** "You may use the `current`, `currentRouteName`, and `currentRouteAction` methods on the `Route` facade to access information about the route handling the incoming request."

- **Given** the manifest declares a named route with a controller action, observed by a recording middleware calling `Route::current()`, `Route::currentRouteName()` and `Route::currentRouteAction()` during the request.
- **When** the route is requested.
- **Then** `Route::current()` returns the `Illuminate\Routing\Route` handling the request, `currentRouteName()` returns the declared name, and `currentRouteAction()` returns the declared action — the registered route is introspectable.

Sources: [routing.md — Accessing the Current Route](repos/laravel/docs/routing.md#accessing-the-current-route).

### AT-43 — `route:list` lists the declared routes

**Doc says:** "The `route:list` Artisan command can easily provide an overview of all of the routes that are defined by your application."

- **Given** the manifest declares routes across the registrar keys (an `addRoute` entry, a `group` entry, and a `resource` entry).
- **When** `php artisan route:list` runs.
- **Then** every declared route appears in the output — the overview reflects the manifest-registered collection (with `-v` expanding middleware, per the documented option).

Sources: [routing.md — Listing Your Routes](repos/laravel/docs/routing.md#listing-your-routes).

---

## 10. Route caching ([routing.md — Route Caching](repos/laravel/docs/routing.md#route-caching))

### AT-44 — cached routes serve; `route:clear` restores live registration

**Doc says:** "To generate a route cache, execute the `route:cache` Artisan command... After running this command, your cached routes file will be loaded on every request." "You may use the `route:clear` command to clear the route cache."

- **Given** the manifest's routes serve before caching; every declared value holds only strings, lists and maps, and `missing` handlers are class-strings wrapped by the provider, keeping the declaration serializable ([declarative-route-registrars.md](declarative-route-registrars.md) §2.4 note 7).
- **When** `php artisan route:cache` runs and a declared route is requested; then `php artisan route:clear` runs and the route is requested again.
- **Then** the route serves in both rounds — from the cached routes file after `route:cache`, and from live registration after `route:clear`.

Sources: [routing.md — Route Caching](repos/laravel/docs/routing.md#route-caching).

### AT-45 — new routes require a fresh route cache

**Doc says:** "Remember, if you add any new routes you will need to generate a fresh route cache."

- **Given** the route cache was generated from the manifest, after which the manifest gains a new route (a fresh application boots with the cache file still present).
- **When** the new route is requested.
- **Then** the request returns a 404 — the cached routes file was loaded instead of re-registering the manifest — and after `route:cache` runs again the new route serves.

Sources: [routing.md — Route Caching](repos/laravel/docs/routing.md#route-caching).

---

## 11. Cross-referenced behavior ([routing.md — Form Method Spoofing](repos/laravel/docs/routing.md#form-method-spoofing))

### AT-46 — `_method` spoofing reaches the declared verb

**Doc says:** "HTML forms do not support `PUT`, `PATCH`, or `DELETE` actions. So, when defining `PUT`, `PATCH`, or `DELETE` routes that are called from an HTML form, you will need to add a hidden `_method` field to the form. The value sent with the `_method` field will be used as the HTTP request method."

- **Given** the manifest declares a `methods: PUT` route.
- **When** a `POST` request carries `_method: PUT` (the `@method('PUT')` field).
- **Then** the declared PUT route handles the request — the spoofed value was used as the HTTP request method.

Sources: [routing.md — Form Method Spoofing](repos/laravel/docs/routing.md#form-method-spoofing). *(Request-layer behavior; included because it exercises the declared verb registration end-to-end. CSRF on the same form is the CSRF domain's behavior — [declarative-csrf-acceptance-test-plan.md](declarative-csrf-acceptance-test-plan.md).)*

---

## 12. Resource controllers ([controllers.md — Resource Controllers](repos/laravel/docs/controllers.md#resource-controllers))

> All resource entries declare under the `resource`/`apiResource`/`singleton`/`apiSingleton` registrar keys with `name`, `controller` and `options`; every `options` entry is one call on the returned `Pending*Registration` ([declarative-route-registrars.md](declarative-route-registrars.md) §1.3, §2.3). The resource routes register at the Pending's destruction, inside the active group scope.

### AT-47 — a resource registers the documented CRUD routes

**Doc says:** "Laravel resource routing assigns the typical create, read, update, and delete ('CRUD') routes to a controller with a single line of code" — `Route::resource('photos', PhotoController::class);` "This single route declaration creates multiple routes to handle a variety of actions on the resource." The documented table: GET `/photos` → `index` → `photos.index`; GET `/photos/create` → `create`; POST `/photos` → `store`; GET `/photos/{photo}` → `show`; GET `/photos/{photo}/edit` → `edit`; PUT/PATCH `/photos/{photo}` → `update`; DELETE `/photos/{photo}` → `destroy` ([Actions Handled by Resource Controllers](repos/laravel/docs/controllers.md#actions-handled-by-resource-controllers)).

- **Given** the manifest declares `routes.resource` with `name: photos`, `controller: PhotoController` and no options.
- **When** `route:list` runs and `/photos/1` is requested with `GET`.
- **Then** the seven documented verb/URI/action/name rows are all present, and the show request dispatched `PhotoController::show` with the bound `{photo}`.

Sources: [controllers.md — Resource Controllers](repos/laravel/docs/controllers.md#resource-controllers); [controllers.md — Actions Handled by Resource Controllers](repos/laravel/docs/controllers.md#actions-handled-by-resource-controllers).

### AT-48 — `only`/`except` restrict the action set

**Doc says:** "When declaring a resource route, you may specify a subset of actions the controller should handle instead of the full set of default actions" — `->only(['index', 'show']);` `->except(['create', 'store', 'update', 'destroy']);`.

- **Given** the manifest declares an `only: [index, show]` resource and an `except: [create, store, update, destroy]` resource.
- **When** `route:list` runs for both.
- **Then** the `only` resource registered exactly its two routes and the `except` resource registered the defaults minus the four named actions.

Sources: [controllers.md — Partial Resource Routes](repos/laravel/docs/controllers.md#restful-partial-resource-routes).

### AT-49 — `apiResource` excludes `create` and `edit`

**Doc says:** "When declaring resource routes that will be consumed by APIs, you will commonly want to exclude routes that present HTML templates such as `create` and `edit`. For convenience, you may use the `apiResource` method to automatically exclude these two routes."

- **Given** the manifest declares `routes.apiResource` with `name: photos`, `controller: PhotoController`.
- **When** `/photos/create` and `/photos/1/edit` are requested, and `/photos` is requested with `GET`.
- **Then** the `create` and `edit` requests return 404s while the remaining API actions serve — the two HTML-template routes were excluded.

Sources: [controllers.md — API Resource Routes](repos/laravel/docs/controllers.md#api-resource-routes).

### AT-50 — nested resources via dot notation

**Doc says:** "To nest the resource controllers, you may use 'dot' notation in your route declaration" — `Route::resource('photos.comments', PhotoCommentController::class);` "This route will register a nested resource that may be accessed with URIs like the following: `/photos/{photo}/comments/{comment}`".

- **Given** the manifest declares `routes.resource` with `name: photos.comments`, `controller: PhotoCommentController`.
- **When** `/photos/1/comments/2` is requested with `GET`.
- **Then** the nested show route serves with both `{photo}` and `{comment}` bound.

Sources: [controllers.md — Nested Resources](repos/laravel/docs/controllers.md#restful-nested-resources).

### AT-51 — shallow nesting

**Doc says:** "you may choose to use 'shallow nesting'" — `Route::resource('photos.comments', CommentController::class)->shallow();` — whose documented table places `index`/`create`/`store` under `/photos/{photo}/comments` and `show`/`edit`/`update`/`destroy` under `/comments/{comment}`.

- **Given** the manifest declares the nested resource with `options: {shallow: true}`.
- **When** `/photos/1/comments` and `/comments/2` are requested with `GET`.
- **Then** both serve — the collection routes kept the parent prefix while the singleton routes use the child URI per the documented table.

Sources: [controllers.md — Shallow Nesting](repos/laravel/docs/controllers.md#shallow-nesting).

### AT-52 — `scoped` enables nested-binding scoping with a custom field

**Doc says:** "By using the `scoped` method when defining your nested resource, you may enable automatic scoping as well as instruct Laravel which field the child resource should be retrieved by" — `Route::resource('photos.comments', PhotoCommentController::class)->scoped(['comment' => 'slug']);` "This route will register a scoped nested resource that may be accessed with URIs like the following: `/photos/{photo}/comments/{comment:slug}`."

- **Given** the manifest declares the nested resource with `options: {scoped: {comment: slug}}`, and a comment whose slug exists but belongs to a different photo.
- **When** `/photos/2/comments/<that-slug>` is requested, and then `/photos/1/comments/<that-slug>`.
- **Then** the first request returns a 404 — the child was resolved scoped to its parent — while the second serves the comment.

Sources: [controllers.md — Scoping Resource Routes](repos/laravel/docs/controllers.md#restful-scoping-resource-routes); [controllers.md — Scoping Nested Resources](repos/laravel/docs/controllers.md#scoping-nested-resources).

### AT-53 — resource route names are overridden

**Doc says:** "By default, all resource controller actions have a route name; however, you can override these names by passing a `names` array with your desired route names" — `->names(['create' => 'photos.build']);`.

- **Given** the manifest declares the resource with `options: {names: {create: photos.build}}`.
- **When** `route('photos.build')` is called.
- **Then** it returns `/photos/create` — the `create` action's name was overridden, and the default `photos.create` name no longer resolves.

Sources: [controllers.md — Naming Resource Routes](repos/laravel/docs/controllers.md#restful-naming-resource-routes).

### AT-54 — resource parameters are renamed

**Doc says:** "By default, `Route::resource` will create the route parameters for your resource routes based on the 'singularized' version of the resource name. You can easily override this on a per resource basis using the `parameters` method" — `->parameters(['users' => 'admin_user']);` "The example above generates the following URI for the resource's `show` route: `/users/{admin_user}`."

- **Given** the manifest declares `routes.resource` with `name: users` and `options: {parameters: {users: admin_user}}`.
- **When** `route:list` runs.
- **Then** the show route's URI reads `/users/{admin_user}` — the parameter name was overridden from the singularized default.

Sources: [controllers.md — Naming Resource Route Parameters](repos/laravel/docs/controllers.md#restful-naming-resource-route-parameters).

### AT-55 — `withTrashed` allows soft-deleted models, optionally per-method

**Doc says:** "you can instruct the framework to allow soft deleted models by invoking the `withTrashed` method when defining your resource route... Calling `withTrashed` with no arguments will allow soft deleted models for the `show`, `edit`, and `update` resource routes. You may specify a subset of these routes by passing an array to the `withTrashed` method" — `->withTrashed(['show']);`.

- **Given** the manifest declares the resource with `options: {withTrashed: [show]}` and a soft-deleted photo.
- **When** `/photos/<deleted-id>` is requested with `GET`, and `/photos/<deleted-id>/edit` is requested with `GET`.
- **Then** the show request serves the soft-deleted model while the edit request returns a 404 — the array form allowed soft-deleted models only for the named subset.

Sources: [controllers.md — Soft Deleted Models](repos/laravel/docs/controllers.md#soft-deleted-models).

### AT-56 — the resource `missing` handler

**Doc says:** "Typically, a 404 HTTP response will be generated if an implicitly bound resource model is not found. However, you may customize this behavior by calling the `missing` method when defining your resource route... invoked if an implicitly bound model cannot be found **for any of the resource's routes**" — `->missing(function (Request $request) { return Redirect::route('photos.index'); });`.

- **Given** the manifest declares the resource with `options: {missing: App\Http\Handlers\PhotoMissing}` — an invokable class-string the provider wraps in the required Closure ([declarative-route-registrars.md](declarative-route-registrars.md) §2.4 note 5).
- **When** `/photos/<missing-id>` is requested with `GET`.
- **Then** the declared handler's redirect response is returned instead of the 404.

Sources: [controllers.md — Customizing Missing Model Behavior](repos/laravel/docs/controllers.md#customizing-missing-model-behavior).

### AT-57 — singletons register `show`/`edit`/`update` without an identifier or creation routes

**Doc says:** "you may register a 'singleton' resource controller" — `Route::singleton('profile', ProfileController::class);` — whose documented table is GET `/profile` → `show` → `profile.show`; GET `/profile/edit` → `edit`; PUT/PATCH `/profile` → `update` — "'creation' routes are not registered for singleton resources, and the registered routes do not accept an identifier since only one instance of the resource may exist."

- **Given** the manifest declares `routes.singleton` with `name: profile`, `controller: ProfileController`.
- **When** `/profile` is requested with `GET`, and `/profile/create` is requested with `GET`.
- **Then** the show request serves `ProfileController::show` while `/profile/create` returns a 404 — no creation routes and no identifier segment were registered.

Sources: [controllers.md — Singleton Resource Controllers](repos/laravel/docs/controllers.md#singleton-resource-controllers).

### AT-58 — nested singletons

**Doc says:** "Singleton resources may also be nested within a standard resource" — `Route::singleton('photos.thumbnail', ThumbnailController::class);` — whose documented table is GET `/photos/{photo}/thumbnail` → `photos.thumbnail.show`; GET `/photos/{photo}/thumbnail/edit` → `edit`; PUT/PATCH `/photos/{photo}/thumbnail` → `update`.

- **Given** the manifest declares `routes.singleton` with `name: photos.thumbnail`, `controller: ThumbnailController`.
- **When** `/photos/1/thumbnail` is requested with `GET`.
- **Then** the nested show route serves with `{photo}` bound and no `{thumbnail}` identifier.

Sources: [controllers.md — Singleton Resource Controllers](repos/laravel/docs/controllers.md#singleton-resource-controllers).

### AT-59 — `creatable` adds create/store/destroy

**Doc says:** "you may invoke the `creatable` method when registering the singleton resource route... a `DELETE` route will also be registered for creatable singleton resources" — the documented table adds GET `/photos/{photo}/thumbnail/create` → `create`, POST `/photos/{photo}/thumbnail` → `store`, and DELETE `/photos/{photo}/thumbnail` → `destroy` to the singleton set.

- **Given** the manifest declares the singleton with `options: {creatable: true}`.
- **When** `route:list` runs.
- **Then** the create, store and destroy rows of the documented table are present alongside the default singleton routes.

Sources: [controllers.md — Creatable Singleton Resources](repos/laravel/docs/controllers.md#creatable-singleton-resources).

### AT-60 — `destroyable` adds destroy without creation routes

**Doc says:** "If you would like Laravel to register the `DELETE` route for a singleton resource but not register the creation or storage routes, you may utilize the `destroyable` method."

- **Given** the manifest declares the singleton with `options: {destroyable: true}`.
- **When** `route:list` runs.
- **Then** the DELETE row is present while no create/store rows were registered.

Sources: [controllers.md — Creatable Singleton Resources](repos/laravel/docs/controllers.md#creatable-singleton-resources).

### AT-61 — `apiSingleton` renders `create`/`edit` unnecessary

**Doc says:** "The `apiSingleton` method may be used to register a singleton resource that will be manipulated via an API, thus rendering the `create` and `edit` routes unnecessary" — `Route::apiSingleton('profile', ProfileController::class);` "API singleton resources may also be `creatable`, which will register `store` and `destroy` routes for the resource."

- **Given** the manifest declares `routes.apiSingleton` with `name: profile`, `controller: ProfileController`.
- **When** `/profile/create` and `/profile/edit` are requested with `GET`, and `/profile` is requested with `GET`.
- **Then** the `create` and `edit` requests return 404s while the singleton show route serves — the HTML-template routes were rendered unnecessary.

Sources: [controllers.md — API Singleton Resources](repos/laravel/docs/controllers.md#api-singleton-resources).

### AT-62 — `middleware` applies to all resource methods

**Doc says:** "You may use the `middleware` method to assign middleware to all routes generated by a resource or singleton resource route" — `Route::resource('users', UserController::class)->middleware(['auth', 'verified']);`.

- **Given** the manifest declares the resource with `options: {middleware: [auth]}` and an `auth` guard that denies guests.
- **When** `/users` is requested with `GET`.
- **Then** the `auth` middleware ran before the action — it applied to all routes generated by the resource.

Sources: [controllers.md — Applying Middleware to all Methods](repos/laravel/docs/controllers.md#applying-middleware-to-all-methods).

### AT-63 — `middlewareFor` applies middleware to specific methods

**Doc says:** "You may use the `middlewareFor` method to assign middleware to one or more specific methods of a given resource controller" — `->middlewareFor('show', 'auth');` `->middlewareFor(['show', 'update'], 'auth');`.

- **Given** the manifest declares the resource with `options: {middlewareFor: {show: auth}}` (the two-argument setter in map form — one call per entry).
- **When** `/users/1` and `/users` are requested with `GET`.
- **Then** the show request ran `auth` while the index request did not — the middleware was assigned only to the named method.

Sources: [controllers.md — Applying Middleware to Specific Methods](repos/laravel/docs/controllers.md#applying-middleware-to-specific-methods).

### AT-64 — `withoutMiddlewareFor` excludes middleware from specific methods

**Doc says:** "You may use the `withoutMiddlewareFor` method to exclude middleware from specific methods of a resource controller" — `->withoutMiddlewareFor('index', ['auth', 'verified'])`.

- **Given** the manifest declares the resource inside a group carrying `auth`, with `options: {withoutMiddlewareFor: {index: auth}}`.
- **When** `/users` (index) and `/users/1` (show) are requested with `GET`.
- **Then** the index request bypassed `auth` while the show request still enforced it — the middleware was excluded only from the named method.

Sources: [controllers.md — Excluding Middleware from Specific Methods](repos/laravel/docs/controllers.md#excluding-middleware-from-specific-methods).

### AT-65 — supplemental routes declared before the resource take precedence

**Doc says:** "If you need to add additional routes to a resource controller beyond the default set of resource routes, you should define those routes before your call to the `Route::resource` method; otherwise, the routes defined by the `resource` method may unintentionally take precedence over your supplemental routes" — `Route::get('/photos/popular', ...); Route::resource('photos', PhotoController::class);`.

- **Given** the manifest declares an `addRoute` entry for `photos/popular` and the `photos` resource (the registrar order places `addRoute` before `resource`, encoding the documented order).
- **When** `/photos/popular` and `/photos/1` are requested with `GET`.
- **Then** `/photos/popular` dispatches the supplemental action — not the resource's `show` — while `/photos/1` dispatches `show`.

Sources: [controllers.md — Supplementing Resource Controllers](repos/laravel/docs/controllers.md#restful-supplementing-resource-controllers).

---

## 13. Documentation gaps

### Documented behavior with no doc-backed `routes:`-surface test

| # | Documented content | Why no test |
|---|---|---|
| G-1 | `Route::match(['get', 'post'], ...)` and `Route::any(...)` ([routing.md — Available Router Methods](repos/laravel/docs/routing.md#available-router-methods)) | Not expressible: each declared entry registers exactly one verb (`methods:` is single-verb — a decided non-goal in [declarative-route-registrars.md](declarative-route-registrars.md) §2.4 note 1, since `methods:` covers verbs natively and separate entries share a path). The all-verb shape arrives only via `redirect` entries (AT-03). |
| G-2 | "any HTML forms pointing to `POST`, `PUT`, `PATCH`, or `DELETE` routes that are defined in the `web` routes file should include a CSRF token field. Otherwise, the request will be rejected." ([routing.md — CSRF Protection](repos/laravel/docs/routing.md#csrf-protection)) | CSRF-domain behavior, owned by [declarative-csrf-acceptance-test-plan.md](declarative-csrf-acceptance-test-plan.md) (its AT-01 cites this clause). |
| G-3 | "> [!WARNING] When using route parameters in redirect routes, the following parameters are reserved by Laravel and cannot be used: `destination` and `status`." ([routing.md — Redirect Routes](repos/laravel/docs/routing.md#redirect-routes)); "the following parameters are reserved by Laravel and cannot be used: `view`, `data`, `status`, and `headers`." ([routing.md — View Routes](repos/laravel/docs/routing.md#view-routes)) | Warnings only — the docs document no positive behavior to assert for a reserved-parameter declaration. |
| G-4 | Global parameter constraints: `Route::pattern('id', '[0-9]+')` applied to all routes using that parameter name ([routing.md — Global Constraints](repos/laravel/docs/routing.md#parameters-global-constraints)) | `Router`-level state, not a per-route declaration — belongs to the `router:` configuration surface ([declarative-router-bindings.md](declarative-router-bindings.md)). |
| G-5 | "> [!WARNING] Route names should always be unique." ([routing.md — Named Routes](repos/laravel/docs/routing.md#named-routes)) | Advisory; the docs document no duplicate-name behavior to assert. |
| G-6 | "you may wish to specify request-wide default values for URL parameters... you may use the [URL::defaults method]" ([routing.md — Generating URLs to Named Routes (NOTE)](repos/laravel/docs/routing.md#generating-urls-to-named-routes)) | URL-generation behavior — belongs to the `url:` surface (Roadmap "URL Generation & Signed URLs"). |
| G-7 | Explicit binding: `Route::model(...)`, `Route::bind(...)`, `resolveRouteBinding`/`resolveChildRouteBinding` ([routing.md — Explicit Binding](repos/laravel/docs/routing.md#explicit-binding)) | `Router`-level binders, not per-route declarations — belongs to the `router:` surface ("Router Configuration & Binders"). |
| G-8 | Group-wide scoped bindings: `Route::scopeBindings()->group(...)` ([routing.md — Custom Keys and Scoping](repos/laravel/docs/routing.md#implicit-model-binding-scoping)) | The per-route `scopeBindings` builder expresses it (AT-36); the group attribute is not part of the closed `RouteGroup` attribute contract ([declarative-route-registrars.md](declarative-route-registrars.md) §2.3). |
| G-9 | Rate limiting: `RateLimiter::for(...)`, `throttle` middleware, 429 responses ([routing.md — Rate Limiting](repos/laravel/docs/routing.md#rate-limiting)) | Roadmap item "Rate Limiter: `rate_limiter:`" — not part of the `routes:` surface. |
| G-10 | CORS: `HandleCors` handling `OPTIONS` requests ([routing.md — Cross-Origin Resource Sharing (CORS)](repos/laravel/docs/routing.md#cors)) | Global-middleware/config behavior, outside the `routes:` surface. |
| G-11 | Batch registrars `resources`, `apiResources`, `singletons`, `apiSingletons` ([controllers.md — Resource Controllers](repos/laravel/docs/controllers.md#resource-controllers)) | Not manifest keys — one call per entry over the singular registrars covers them (Rule 2, [declarative-route-registrars.md](declarative-route-registrars.md) §2.1). |

### Declared `routes:` surface the vendored docs do not cover

| # | Declared surface | Note |
|---|---|---|
| G-12 | `setDefaults` (and its DeclaredView `factory` entry), `metadata`, `withoutBlocking` | Documented only on the declared surface ([README.md — Routes](../README.md#routes), [declarative-routing.md](declarative-routing.md) §2.4, [declarative-view-factory.md](declarative-view-factory.md)); no vendored-doc clause states `Route::setDefaults`, `Route::metadata`, or `Route::withoutBlocking` behavior, so no doc-backed test exists here. |
| G-13 | `methods` verb uppercase normalization (`methods: patch` → `PATCH`) | Provider normalization documented on the declared surface ([README.md — Routes](../README.md#routes) "one verb, uppercased by the provider"); the vendored docs show uppercase verb methods only. |
| G-14 | View/redirect shortcut verbs (GET\|HEAD; all verbs) | The vendored docs never state which verbs `Route::view`/`Route::redirect` register; the observable is framework-derived, so it appears only as AT-03's documented-ordering consequence for redirects. |

---

## 14. Upstream sources

The vendored docs mirror upstream Laravel documentation:

- Routing: https://laravel.com/docs/routing
- Controllers (Resource Controllers, Dependency Injection): https://laravel.com/docs/controllers
- Middleware (Assigning/Excluding Middleware): https://laravel.com/docs/middleware
- Authorization (Via Middleware): https://laravel.com/docs/authorization
- Session (Session Blocking): https://laravel.com/docs/session#session-blocking