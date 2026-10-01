# Declarative Router — Acceptance Test Plan (`src/Router.php`)

**Subject under test:** [src/Router.php](../src/Router.php) (`ZeroToProd\LaravelDeclaration\Router`) — the `DataModel` hydrated from the manifest's `router:` key ([Manifest.php](../src/Manifest.php) `?Router $router`). Its twelve properties are `Illuminate\Routing\Router` method names; they are applied by [RouterDeclarationServiceProvider.php](../src/Providers/RouterDeclarationServiceProvider.php) as identically-named `Router` calls in `boot()`: a map property is one call per entry with the key as the first argument (`Binding` keys `pattern`/`model`/`bind`/`middlewareGroup`/`aliasMiddleware`), a `Setter` property is one call with the whole value (`singularResourceParameters`, `resourceParameters`, `resourceVerbs`), and the group-mutation trio (`PrependTo`/`AppendTo`) is one call per item — `matched` (`Append`) is one `matched($callback)` per item.

**Source documentation:** [docs/repos/laravel/docs/routing.md](repos/laravel/docs/routing.md) — the vendored Laravel docs are the **system of record** for every behavior below (upstream equivalents in §10), with [middleware.md](repos/laravel/docs/middleware.md), [controllers.md](repos/laravel/docs/controllers.md), [eloquent.md](repos/laravel/docs/eloquent.md) and [lifecycle.md](repos/laravel/docs/lifecycle.md) corroborating. The vendored docs register these behaviors through `App\Providers\AppServiceProvider`'s `boot()` (`Route::pattern(...)`, `Route::model(...)`, `Route::bind(...)`, `Route::resourceVerbs(...)`) and through the `bootstrap/app.php` `Middleware` configuration object (group append/prepend/remove, aliases); each declared `router:` key is the identically-named `Router`-method counterpart of one documented call. This plan contains no behavior sourced from the framework code alone.

**Rule:** one **Given / When / Then** test per unique documented behavior. A test exists only where the vendored docs document the behavior; declared surface the docs do not cover is inventoried in §9 (G-1–G-9). Tests are not implemented here. Route-assignment syntax inside a test (`middleware: [web]`, `domain: "{account}.example.com"`, resource declarations) is the manifest's [routes](../README.md#routes) surface; the tested behavior is the `router:` key's.

---

## 1. Coverage map

| `Router` property | Documented behavior | Test |
|---|---|---|
| `pattern` | a route parameter is always constrained by the declared regular expression, automatically applied to all routes using that parameter name | AT-01 |
| `pattern` | an incoming request that does not match the pattern constraints is not routed — a 404 HTTP response is returned | AT-02 |
| `pattern` | subdomains may be assigned route parameters just like route URIs, so the pattern constrains a domain parameter of that name too | AT-03 |
| `model` | an explicit binding injects the bound model instance whose ID matches the URI segment | AT-04 |
| `model` | a matching model instance not found in the database generates a 404 HTTP response | AT-05 |
| `model` | resolution runs through the model's overridden `resolveRouteBinding`, which receives the URI-segment value and returns the instance to inject | AT-06 |
| `bind` | the custom resolution logic receives the URI-segment value and the instance it returns is injected into the route | AT-07 |
| `bind` | the doc's `firstOrFail` resolution logic throws `ModelNotFoundException` when uncaught — a 404 HTTP response is sent | AT-08 |
| `middlewareGroup` | middleware grouped under a single key run when the group is assigned to a route with the same syntax as individual middleware | AT-09 |
| `aliasMiddleware` | a defined alias resolves to its middleware when the alias is assigned to a route | AT-10 |
| `pushMiddlewareToGroup` | middleware appended to a group run when the group is assigned to a route; the group's existing members still run | AT-11 |
| `prependMiddlewareToGroup` | middleware prepended to a group sit at the beginning of the group's list — they run before the group's first default member | AT-12 |
| `removeMiddlewareFromGroup` | a middleware removed from a group entirely no longer runs when the group is assigned | AT-13 |
| `resourceParameters` | the resource-name → parameter-name map renames the resource's route parameters (`/users/{admin_user}`) | AT-14 |
| `resourceVerbs` | localized `create`/`edit` verbs change the create and edit URIs of every resource route | AT-15 |
| `singularResourceParameters` | not documented in the vendored docs | §9 G-1 |
| `matched` | not documented in the vendored docs | §9 G-2 |

---

## 2. Route parameter patterns ([routing.md — Regular Expression Constraints](repos/laravel/docs/routing.md#parameters-regular-expression-constraints))

### AT-01 — the declared pattern is automatically applied to all routes using that parameter name

**Doc says:** "If you would like a route parameter to always be constrained by a given regular expression, you may use the `pattern` method. You should define these patterns in the `boot` method of your application's `App\Providers\AppServiceProvider` class" — `Route::pattern('id', '[0-9]+');` — and "Once the pattern has been defined, it is automatically applied to all routes using that parameter name," the doc's example route noting "Only executed if `{id}` is numeric...".

- **Given** the manifest declares `router.pattern: {id: '[0-9]+'}` — the doc's example constraint, expressed on the `Router` surface — and two routes whose paths both use `{id}` (`users/{id}` and `posts/{id}`), each action echoing what `$request->route('id')` holds.
- **When** `users/7` is requested, and then `posts/7`.
- **Then** both actions ran and both echoed `7` — the one declared pattern constrained the `{id}` parameter of every route using that parameter name, not just one route.

Sources: [routing.md — Global Constraints](repos/laravel/docs/routing.md#parameters-global-constraints).

### AT-02 — a request whose parameter violates the pattern is not routed: 404

**Doc says:** "If the incoming request does not match the route pattern constraints, a 404 HTTP response will be returned."

- **Given** AT-01's declaration and routes.
- **When** `users/abc` is requested — a `{id}` value the declared pattern does not match.
- **Then** the request receives a 404 HTTP response and no route action ran — the incoming request did not match the route's pattern constraints.

Sources: [routing.md — Regular Expression Constraints](repos/laravel/docs/routing.md#parameters-regular-expression-constraints).

### AT-03 — the pattern constrains a domain parameter too

**Doc says:** "Subdomains may be assigned route parameters just like route URIs" ([Subdomain Routing](repos/laravel/docs/routing.md#route-group-subdomain-routing)) — the doc's `Route::domain('{account}.example.com')` example — while the pattern "is automatically applied to all routes using that parameter name" ([Global Constraints](repos/laravel/docs/routing.md#parameters-global-constraints)); a domain parameter is a route parameter of that name.

- **Given** the manifest declares `router.pattern: {account: '[a-z]+'}` and one route with `domain: "{account}.example.com"` (the doc's subdomain form, expressed on the routes surface) whose action echoes `$request->route('account')`.
- **When** the route is requested with subdomain `acme`, and then with subdomain `Acme9` — the latter matching the domain template but violating the declared pattern.
- **Then** the `acme` request reached the action and echoed `acme`, while the `Acme9` request received a 404 — the pattern constrained the domain parameter just like a URI parameter.

Sources: [routing.md — Global Constraints](repos/laravel/docs/routing.md#parameters-global-constraints); [routing.md — Subdomain Routing](repos/laravel/docs/routing.md#route-group-subdomain-routing).

---

## 3. Explicit model binding ([routing.md — Explicit Binding](repos/laravel/docs/routing.md#explicit-binding))

### AT-04 — the explicit binding injects the model instance whose ID matches the segment

**Doc says:** "You are not required to use Laravel's implicit, convention based model resolution in order to use model binding. You can also explicitly define how route parameters correspond to models. To register an explicit binding, use the router's `model` method to specify the class for a given parameter. You should define your explicit model bindings at the beginning of the `boot` method of your `AppServiceProvider` class" — `Route::model('user', User::class);` — and "Since we have bound all `{user}` parameters to the `App\Models\User` model, an instance of that class will be injected into the route. So, for example, a request to `users/1` will inject the `User` instance from the database which has an ID of `1`."

- **Given** the manifest declares `router.model: {user: App\Models\User}` — the doc's example binding — a persisted `User` whose key is `1`, and a route `users/{user}` assigned the default `web` group (whose members include `Illuminate\Routing\Middleware\SubstituteBindings`) whose action reports what `$request->route('user')` holds; the action type-hints nothing, so only the explicit binding can resolve `{user}`.
- **When** `users/1` is requested.
- **Then** the action received the persisted `App\Models\User` instance whose ID is `1` — the `{user}` parameter was bound to the declared model class.

Sources: [routing.md — Explicit Binding](repos/laravel/docs/routing.md#explicit-binding); [middleware.md — Laravel's Default Middleware Groups](repos/laravel/docs/middleware.md#laravels-default-middleware-groups).

### AT-05 — a missing model generates a 404

**Doc says:** "If a matching model instance is not found in the database, a 404 HTTP response will be automatically generated."

- **Given** AT-04's declaration and route, with no `User` persisted whose key is `999`.
- **When** `users/999` is requested.
- **Then** the request receives a 404 HTTP response and no route action ran — the matching model instance was not found in the database.

Sources: [routing.md — Explicit Binding](repos/laravel/docs/routing.md#explicit-binding).

### AT-06 — resolution runs through the model's overridden `resolveRouteBinding`

**Doc says:** ([Customizing the Resolution Logic](repos/laravel/docs/routing.md#customizing-the-resolution-logic)) "Alternatively, you may override the `resolveRouteBinding` method on your Eloquent model. This method will receive the value of the URI segment and should return the instance of the class that should be injected into the route."

- **Given** the manifest declares `router.model: {post: App\Models\Post}` where `Post` overrides `resolveRouteBinding($value)` to look the model up by slug, a persisted `Post` with slug `hello-world`, and a route `posts/{post}` assigned `web` whose action reports what `$request->route('post')` holds.
- **When** `posts/hello-world` is requested.
- **Then** the action received that `Post` instance — the binding resolved through the model's overridden `resolveRouteBinding`, which received the URI-segment value and returned the injected instance.

Sources: [routing.md — Customizing the Resolution Logic](repos/laravel/docs/routing.md#customizing-the-resolution-logic).

---

## 4. Custom binding resolution ([routing.md — Customizing the Resolution Logic](repos/laravel/docs/routing.md#customizing-the-resolution-logic))

### AT-07 — the custom binder receives the URI-segment value and its returned instance is injected

**Doc says:** "If you wish to define your own model binding resolution logic, you may use the `Route::bind` method. The closure you pass to the `bind` method will receive the value of the URI segment and should return the instance of the class that should be injected into the route" — the doc's example closure resolving `User::where('name', $value)`. The closure form has no YAML expression; the declared surface expresses it as a binder reference (`bind: user: App\Routing\Binders\UserByName`, called with the segment value per [README — Router](../README.md#router)), which is the framework-source counterpart inventoried in §9 G-3.

- **Given** the manifest declares `router.bind: {user: App\Routing\Binders\UserByName}` — a binder whose `bind` method implements the doc's name lookup (`User::where('name', $value)`, resolved with `first()`; the doc example's `firstOrFail()` terminal is AT-08's subject) — a persisted user named `david`, and a route `users/{user}` assigned `web` whose action reports what `$request->route('user')` holds.
- **When** `users/david` is requested.
- **Then** the action received that `User` instance — the binder received the URI-segment value (`david`) and the instance it returned was injected into the route.

Sources: [routing.md — Customizing the Resolution Logic](repos/laravel/docs/routing.md#customizing-the-resolution-logic).

### AT-08 — the binder's `firstOrFail` yields a 404 when uncaught

**Doc says:** the section's example resolution ends in `firstOrFail()`; [eloquent.md — Not Found Exceptions](repos/laravel/docs/eloquent.md#not-found-exceptions) — "The `findOrFail` and `firstOrFail` methods will retrieve the first result of the query; however, if no result is found, an `Illuminate\Database\Eloquent\ModelNotFoundException` will be thrown" and "If the `ModelNotFoundException` is not caught, a 404 HTTP response is automatically sent back to the client."

- **Given** AT-07's declaration with the binder written with `firstOrFail()` (the doc's example logic) and no persisted user named `nobody`.
- **When** `users/nobody` is requested.
- **Then** the request receives a 404 HTTP response — the binder's `firstOrFail` threw `ModelNotFoundException`, which was not caught and was sent back as a 404.

Sources: [routing.md — Customizing the Resolution Logic](repos/laravel/docs/routing.md#customizing-the-resolution-logic); [eloquent.md — Not Found Exceptions](repos/laravel/docs/eloquent.md#not-found-exceptions).

---

## 5. Middleware groups ([middleware.md — Middleware Groups](repos/laravel/docs/middleware.md#middleware-groups))

### AT-09 — a declared group's members run when the group is assigned to a route

**Doc says:** "Sometimes you may want to group several middleware under a single key to make them easier to assign to routes" and "Middleware groups may be assigned to routes and controller actions using the same syntax as individual middleware" — `->middleware('group-name')`. The doc's example defines the group with `First::class` and `Second::class`.

- **Given** the manifest declares `router.middlewareGroup: {group-name: [App\Http\Middleware\First, App\Http\Middleware\Second]}` — the doc's example group expressed on the `Router` surface — and a route assigned `middleware: [group-name]` (the doc's group-assignment syntax), each member recording its run.
- **When** the route is requested.
- **Then** both `First` and `Second` executed — the middleware grouped under a single key ran when the group was assigned to the route with the same syntax as individual middleware.

Sources: [middleware.md — Middleware Groups](repos/laravel/docs/middleware.md#middleware-groups).

---

## 6. Middleware aliases ([middleware.md — Middleware Aliases](repos/laravel/docs/middleware.md#middleware-aliases))

### AT-10 — a defined alias resolves to its middleware when assigned to a route

**Doc says:** "Middleware aliases allow you to define a short alias for a given middleware class, which can be especially useful for middleware with long class names" — `$middleware->alias(['subscribed' => EnsureUserIsSubscribed::class])` — and "Once the middleware alias has been defined in your application's `bootstrap/app.php` file, you may use the alias when assigning the middleware to routes" — `->middleware('subscribed')`.

- **Given** the manifest declares `router.aliasMiddleware: {subscribed: App\Http\Middleware\EnsureUserIsSubscribed}` — the doc's example alias expressed on the `Router` surface — and a route assigned `middleware: [subscribed]`, where the middleware's `handle` rejects a user without an active subscription (the doc's alias purpose).
- **When** the route is requested by a user without an active subscription.
- **Then** the `EnsureUserIsSubscribed` middleware executed — the alias resolved to the declared middleware class when it was assigned to the route by its short name.

Sources: [middleware.md — Middleware Aliases](repos/laravel/docs/middleware.md#middleware-aliases).

---

## 7. Group mutations ([middleware.md — Laravel's Default Middleware Groups](repos/laravel/docs/middleware.md#laravels-default-middleware-groups))

The documented default `web` group contains `Illuminate\Session\Middleware\StartSession` and `Illuminate\Routing\Middleware\SubstituteBindings`; [lifecycle.md — HTTP / Console Kernels](repos/laravel/docs/lifecycle.md#http-console-kernels) attributes session state to the stack: "These middleware handle reading and writing the HTTP session." Tests in this section declare no `kernel:` middleware keys (the kernel/router precedence is §9 G-5).

### AT-11 — middleware appended to a default group run when the group is assigned

**Doc says:** "If you would like to append or prepend middleware to these groups, you may use the `web` and `api` methods within your application's `bootstrap/app.php` file. The `web` and `api` methods are convenient alternatives to the `appendToGroup` method" — the example appends `EnsureUserIsSubscribed::class` to `web`.

- **Given** the manifest declares `router.pushMiddlewareToGroup: {web: [App\Http\Middleware\EnsureUserIsSubscribed]}` — the doc's append to the default `web` group expressed on the `Router` surface — and a route assigned `middleware: [web]`, where the appended middleware records its run.
- **When** the route is requested.
- **Then** the appended middleware executed **and** the route still had session state — the append joined the group's existing members (the default `web` group still includes `StartSession`) rather than replacing the group.

Sources: [middleware.md — Laravel's Default Middleware Groups](repos/laravel/docs/middleware.md#laravels-default-middleware-groups); [middleware.md — Middleware Groups](repos/laravel/docs/middleware.md#middleware-groups); [lifecycle.md — HTTP / Console Kernels](repos/laravel/docs/lifecycle.md#http-console-kernels).

### AT-12 — middleware prepended to a default group sit at the beginning of its list

**Doc says:** the same section's append-or-prepend sentence and its prepend example — `$middleware->api(prepend: [EnsureTokenIsValid::class])` (the section calls the `web` and `api` methods "convenient alternatives to the `appendToGroup` method"; the doc's `prependToGroup` example form appears in its [Middleware Groups](repos/laravel/docs/middleware.md#middleware-groups) section); [routing.md — Route Group Middleware](repos/laravel/docs/routing.md#route-group-middleware) states the ordering observable: "Middleware are executed in the order they are listed in the array."

- **Given** the manifest declares `router.prependMiddlewareToGroup: {web: [App\Http\Middleware\Probe]}` — a before-middleware that records what `$request->hasSession()` holds at its turn — and a route assigned `middleware: [web]` that reports session availability.
- **When** the route is requested.
- **Then** `Probe` recorded `hasSession() === false` — it ran before the group's first default member `StartSession`, at the beginning of the group's list — while the route itself still had session state (the default members follow in their listed order).

Sources: [middleware.md — Middleware Groups](repos/laravel/docs/middleware.md#middleware-groups), [Laravel's Default Middleware Groups](repos/laravel/docs/middleware.md#laravels-default-middleware-groups); [routing.md — Route Group Middleware](repos/laravel/docs/routing.md#route-group-middleware).

### AT-13 — middleware removed from a default group entirely no longer runs

**Doc says:** "Or, you may remove a middleware entirely" — `$middleware->web(remove: [StartSession::class]);` — removing a member from the group.

- **Given** the manifest declares `router.removeMiddlewareFromGroup: {web: [Illuminate\Routing\Middleware\SubstituteBindings]}` alongside AT-04's `router.model: {user: App\Models\User}` declaration, and the same `users/{user}` route assigned `web` — with a persisted `User` whose key is `1`.
- **When** `users/1` is requested.
- **Then** the action received the raw segment string `'1'`, not the `User` instance AT-04 injects — the removed member no longer ran its part of the group, so the binding substitution it performs is absent.

Sources: [middleware.md — Laravel's Default Middleware Groups](repos/laravel/docs/middleware.md#laravels-default-middleware-groups); [routing.md — Explicit Binding](repos/laravel/docs/routing.md#explicit-binding) (the injected instance AT-04 shows the same declaration before removal).

---

## 8. Resource route globals ([controllers.md — Resource Controllers](repos/laravel/docs/controllers.md#resource-controllers))

Resource routes register through the documented `Route::resource` surface ([controllers.md](repos/laravel/docs/controllers.md#resource-controllers) — the declared `routes.resource` item is the manifest's expression of it, registering as `Router::resource($name, $controller)` per [Routes.php](../src/Routes.php)) — the declared globals are read when those routes are created.

### AT-14 — the resource-name → parameter-name map renames the resource's route parameters

**Doc says:** ([Naming Resource Route Parameters](repos/laravel/docs/controllers.md#restful-naming-resource-route-parameters)) "By default, `Route::resource` will create the route parameters for your resource routes based on the "singularized" version of the resource name. You can easily override this on a per resource basis using the `parameters` method. The array passed into the `parameters` method should be an associative array of resource names and parameter names" — the example `['users' => 'admin_user']` generating "the following URI for the resource's `show` route: `/users/{admin_user}`". The declared key carries that map globally (one `Router::resourceParameters($parameters)` call with the whole map).

- **Given** the manifest declares `router.resourceParameters: {users: admin_user}` — the doc's example map expressed on the `Router` surface — and a declared `users` resource route whose actions echo the show parameter.
- **When** `/users/7` is requested.
- **Then** the resource's `show` action ran and echoed `admin_user` = `7` — the resource's route parameter was named by the map (`/users/{admin_user}`), not by the singularized resource name.

Sources: [controllers.md — Naming Resource Route Parameters](repos/laravel/docs/controllers.md#restful-naming-resource-route-parameters).

### AT-15 — localized resource verbs change the create and edit URIs

**Doc says:** ([Localizing Resource URIs](repos/laravel/docs/controllers.md#restful-localizing-resource-uris)) "By default, `Route::resource` will create resource URIs using English verbs and plural rules. If you need to localize the `create` and `edit` action verbs, you may use the `Route::resourceVerbs` method. This may be done at the beginning of the `boot` method within your application's `App\Providers\AppServiceProvider`" — the example `['create' => 'crear', 'edit' => 'editar']` producing `/publicacion/crear` and `/publicacion/{publicaciones}/editar` — the doc's [Actions Handled by Resource Controllers](repos/laravel/docs/controllers.md#actions-handled-by-resource-controllers) table places `create` at `/photos/create` and `edit` at `/photos/{photo}/edit` by default.

- **Given** the manifest declares `router.resourceVerbs: {create: crear, edit: editar}` — the doc's example verbs expressed on the `Router` surface — and a declared `photos` resource route.
- **When** `/photos/crear` is requested, and then `/photos/7/editar`.
- **Then** the resource's `create` action ran for the first request and its `edit` action for the second — the localized verbs replaced `create` and `edit` in the resource's URIs.

Sources: [controllers.md — Localizing Resource URIs](repos/laravel/docs/controllers.md#restful-localizing-resource-uris), [Actions Handled by Resource Controllers](repos/laravel/docs/controllers.md#actions-handled-by-resource-controllers).

---

## 9. Documentation gaps — declared surface with no backing docs, and documented behavior with no declarable surface

G-1–G-2: no acceptance test can be written from `docs/repos/laravel/docs/` for the following; each lists the nearest non-backing documentation. These need either an upstream doc reference or a source-derived test (out of scope for this plan). G-3–G-9 are the inverse: documented or declared behavior the vendored docs do not cover.

| # | Declared surface | Why no doc-backed test |
|---|---|---|
| G-1 | `singularResourceParameters` | The docs document only the default — resource parameters "based on the "singularized" version of the resource name" ([controllers.md — Naming Resource Route Parameters](repos/laravel/docs/controllers.md#restful-naming-resource-route-parameters)). The override the key exists for — `false` pluralizing the parameter (`{posts}`, not `{post}`, per [README — Router](../README.md#router)) — is undocumented; a `true` declaration would be indistinguishable from the framework default, so no non-vacuous doc-backed test exists. The key itself exists by the block's 1:1 design rule ([declarative-router.md](declarative-router.md) §2.1). In-repo mapping: [declarative-router-configuration.md](declarative-router-configuration.md) §1.3. |
| G-2 | `matched` | The `RouteMatched` event appears nowhere in `docs/repos/laravel/docs/`. Nearest non-backing docs: [events.md — Manually Registering Events](repos/laravel/docs/events.md#manually-registering-events) (the listener-reference forms `Class@method` / `handle` the declared items use). The dispatch timing (listeners fire at route match, before route middleware, with `(RouteMatched $event)`) and the string-resolution (`handle` default, `__invoke` fallback) are framework source: [declarative-router-configuration.md](declarative-router-configuration.md) §1.1, §1.4. |
| G-3 | (inverse) string-reference binders | `bind` values are `Class` / `Class@method` strings ([README — Router](../README.md#router)); the docs document only the closure form — "The closure you pass to the `bind` method will receive the value of the URI segment" ([routing.md — Customizing the Resolution Logic](repos/laravel/docs/routing.md#customizing-the-resolution-logic)). The string resolution (`make(Class)->bind($value, $route)`; a bare invokable fails, unlike `matched`'s `__invoke` fallback) is framework source: [declarative-router-bindings.md](declarative-router-bindings.md) §1.4. AT-07/AT-08 express the doc's resolution behavior through the declared string form. |
| G-4 | (inverse) parameterized items in groups | The README notes group/alias "Items may be aliases (`throttle:60,1`)"; the docs document `:`-parameters only "when defining the route" ([middleware.md — Middleware Parameters](repos/laravel/docs/middleware.md#middleware-parameters)), not for group members. |
| G-5 | (inverse) kernel/router precedence | The README states "`kernel:` middleware keys win for any group/alias the kernel also declares (its setters re-sync the router); router-only keys persist". The re-sync (`Kernel::syncMiddlewareToRouter`) and its ordering are framework source: [declarative-router-configuration.md](declarative-router-configuration.md) §1.1 (consequences 1–3). The docs document only the `Middleware` object's group/alias calls ([middleware.md](repos/laravel/docs/middleware.md#middleware-groups)) with no router-relation. Tests mutating default groups declare no `kernel:` middleware keys. |
| G-6 | (inverse) group-mutation edge semantics | `pushMiddlewareToGroup` creates a missing group; `prependMiddlewareToGroup`/`removeMiddlewareFromGroup` are silent no-ops on a missing group or absent member. Framework source: [declarative-router-configuration.md](declarative-router-configuration.md) §1.3. The docs document the trio only against defined/default groups (AT-11–AT-13). |
| G-7 | (inverse) console persistence | The README states "`router:` keys work even when the HTTP kernel never resolves (console)" — the declaration applies in `boot()` without kernel resolution. Framework source: [declarative-router-configuration.md](declarative-router-configuration.md) §1.1 (consequence 4). Nearest non-backing docs: [lifecycle.md](repos/laravel/docs/lifecycle.md#http-console-kernels) (the two kernels). |
| G-8 | (inverse) partial verb map | A non-empty `resourceVerbs` map merges over the statics, so a `create`-only declaration keeps the English `edit` verb. Framework source: [declarative-router-configuration.md](declarative-router-configuration.md) §1.3. The doc's example localizes both verbs (AT-15). |
| G-9 | (inverse) `route:cache` interplay | The README instructs to "re-run `route:cache` after editing" the resource globals, "unlike the middleware and `matched` keys". The docs document generating and refreshing the route cache ([routing.md — Route Caching](repos/laravel/docs/routing.md#route-caching)) but not that the resource globals bake into the cached URIs. |

---

## 10. Sources

Vendored docs (**system of record**, relative to `docs/repos/laravel/docs/`) with upstream equivalents:

1. [routing.md](repos/laravel/docs/routing.md) — https://laravel.com/docs/routing — Regular Expression Constraints incl. Global Constraints and the constraint-mismatch 404 (§2 AT-01–AT-03), Route Group Middleware ordering (§7 AT-12), Route Model Binding / Explicit Binding / Customizing the Resolution Logic (§3–§4, §7 AT-13), Route Caching (§9 G-9).
2. [middleware.md](repos/laravel/docs/middleware.md) — https://laravel.com/docs/middleware — Middleware Groups (§5, §7), Laravel's Default Middleware Groups incl. the append/prepend/remove calls and the `web` group table (§3 AT-04, §7 AT-11–AT-13), Middleware Aliases (§6), Middleware Parameters (§9 G-4).
3. [controllers.md](repos/laravel/docs/controllers.md) — https://laravel.com/docs/controllers — Resource Controllers incl. Actions Handled by Resource Controllers, Naming Resource Route Parameters, Localizing Resource URIs (§8).
4. [eloquent.md](repos/laravel/docs/eloquent.md) — https://laravel.com/docs/eloquent — Not Found Exceptions (`firstOrFail` → `ModelNotFoundException` → 404) (§4 AT-08).
5. [lifecycle.md](repos/laravel/docs/lifecycle.md) — https://laravel.com/docs/lifecycle — HTTP / Console Kernels (§7 session corroboration, §9 G-7 nearest-non-backing).
6. [events.md](repos/laravel/docs/events.md) — https://laravel.com/docs/events — Manually Registering Events (§9 G-2 nearest-non-backing only; no `RouteMatched` behavior documented).

In-repo subject/mapping context (no tested behavior sourced from these):

7. [declarative-router.md](declarative-router.md) — the `router:` block's design rule and the `pattern` mapping contract; cited in §9 G-1.
8. [declarative-router-bindings.md](declarative-router-bindings.md) — the framework-verified `model`/`bind` mapping; cited in §9 G-3.
9. [declarative-router-configuration.md](declarative-router-configuration.md) — the framework-verified middleware-registry, resource-globals and `matched` mapping; cited in §9 G-1/G-2/G-5/G-6/G-8.
10. [declarative-tier1-gap-inventory.md](declarative-tier1-gap-inventory.md) — the Tier 1 gap rows this surface resolves.
11. [README.md — Router](../README.md#router) — the declared `router:` surface and its `Router`-method counterparts.