# Declarative HTTP Kernel — Acceptance Test Plan (`src/Kernel.php`)

**Subject under test:** [src/Kernel.php](../src/Kernel.php) (`ZeroToProd\LaravelDeclaration\Kernel`) — the `DataModel` hydrated from the manifest's `kernel:` key ([Manifest.php](../src/Manifest.php) `?Kernel $kernel`). Its thirteen properties are `Illuminate\Foundation\Http\Kernel` method names; they are applied by [KernelDeclarationServiceProvider.php](../src/Providers/KernelDeclarationServiceProvider.php) as identically-named `Kernel` calls when the container first resolves `Illuminate\Contracts\Http\Kernel` (`callAfterResolving`, queued in `boot()`): a list property is one call per item in declaration order (the `Prepend` values are called in reverse so the first declared item lands first), a map property is one call per entry with the key as the target or anchor argument (the `PrependTo` values are called in reverse so the first declared item lands first), the `set*` keys are one bulk call, and `whenRequestLifecycleIsLongerThan` maps each threshold key to a handler reference.

**Source documentation:** [docs/repos/laravel/docs/middleware.md](repos/laravel/docs/middleware.md) — the vendored Laravel docs are the **system of record** for every behavior below (upstream equivalents in §10), with [lifecycle.md](repos/laravel/docs/lifecycle.md), [requests.md](repos/laravel/docs/requests.md), [routing.md](repos/laravel/docs/routing.md) and [container.md](repos/laravel/docs/container.md) corroborating. The vendored docs register middleware through the `bootstrap/app.php` `Middleware` configuration object (`$middleware->append(...)`, `->use(...)`, `->group(...)`); each declared `kernel:` key is the identically-named `Kernel`-method counterpart of one documented configuration call. This plan contains no behavior sourced from the framework code alone.

**Rule:** one **Given / When / Then** test per unique documented behavior. A test exists only where the vendored docs document the behavior; declared surface the docs do not cover is inventoried in §9 (G-1–G-2), and documented behavior the declared surface cannot express is likewise inventoried in §9 (G-3–G-7). Tests are not implemented here.

---

## 1. Coverage map

| `Kernel` property | Documented behavior | Test |
|---|---|---|
| `pushMiddleware` | middleware appended to the global stack runs during every HTTP request to the application | AT-01 |
| `pushMiddleware` | `append` adds the middleware to the end of the list of global middleware | AT-02 |
| `prependMiddleware` | `prepend` adds a middleware to the beginning of the list | AT-02 |
| `setGlobalMiddleware` | the default stack of global middleware may be provided to the `use` method to manage the stack manually | AT-03 |
| `setGlobalMiddleware` | the manually provided stack may then be adjusted as necessary (an omitted default no longer runs) | AT-04 |
| `pushMiddleware` | a middleware inspects and filters the request: it rejects it (redirect) before the application handles it, or allows it to proceed | AT-05 |
| `pushMiddleware` | a global middleware can perform its task after the request is handled, on the outgoing response | AT-06 |
| `pushMiddleware` | all middleware are resolved via the service container — constructor type-hints are injected | AT-07 |
| `pushMiddleware` | `withoutMiddleware` does not apply to global middleware | AT-08 |
| `appendMiddlewareToGroup` | middleware grouped under a single key run when the group is assigned to a route with the same syntax as individual middleware | AT-09 |
| `prependMiddlewareToGroup` | the doc's `prependToGroup` form — prepended members run when the group is assigned to a route | AT-10 |
| `setMiddlewareGroups` | Laravel's default `web`/`api` middleware groups may be redefined entirely | AT-11 |
| `setMiddlewareAliases` | once an alias is defined, it may be used when assigning the middleware to routes | AT-12 |
| `setMiddlewarePriority` | middleware execute in priority order even when assigned to the route in another order | AT-13 |
| `addToMiddlewarePriorityBefore` | the given middleware is inserted before another middleware in the priority list | AT-14 |
| `addToMiddlewarePriorityAfter` | the given middleware is inserted after another middleware in the priority list | AT-15 |
| `pushMiddleware` | a global middleware defining `terminate` has it called automatically after the response is sent | AT-16 |
| `pushMiddleware` | `terminate` runs on a fresh instance resolved from the service container | AT-17 |
| `prependToMiddlewarePriority` | not documented in the vendored docs | §9 gaps |
| `appendToMiddlewarePriority` | not documented in the vendored docs | §9 gaps |
| `whenRequestLifecycleIsLongerThan` | not documented in the vendored docs | §9 gaps |

---

## 2. The global middleware stack ([middleware.md — Global Middleware](repos/laravel/docs/middleware.md#global-middleware))

### AT-01 — middleware appended to the global stack runs during every HTTP request

**Doc says:** "If you want a middleware to run during every HTTP request to your application, you may append it to the global middleware stack in your application's `bootstrap/app.php` file" — `$middleware->append(EnsureTokenIsValid::class);`.

- **Given** the manifest declares `kernel.pushMiddleware: [<Recorder>]` — the doc's append expressed on the `Kernel` surface, where `Recorder` records each request it sees — and two routes with different paths and actions.
- **When** both routes are requested (two requests).
- **Then** `Recorder` observed both requests — the middleware ran during every HTTP request to the application, not only on one route.

Sources: [middleware.md — Global Middleware](repos/laravel/docs/middleware.md#global-middleware).

### AT-02 — append lands at the end of the global list, prepend at its beginning

**Doc says:** "The `append` method adds the middleware to the end of the list of global middleware. If you would like to add a middleware to the beginning of the list, you should use the `prepend` method." The documented default stack ends with the normalization middleware: [requests.md — Input Trimming and Normalization](repos/laravel/docs/requests.md#input-trimming-and-normalization) — "These middleware will automatically trim all incoming string fields on the request, as well as convert any empty string fields to `null`."

- **Given** the manifest declares `kernel.prependMiddleware: [<First>]` and `kernel.pushMiddleware: [<Last>]` — `First` and `Last` are before-middleware that each snapshot `$request->input()` into the request before calling `$next` — and a route that echoes both snapshots; the request carries a padded string field (`'  pad  '`) and an empty-string field.
- **When** the route is requested.
- **Then** `First`'s snapshot shows the padded string untrimmed and the empty field still an empty string — it ran before the default global stack, at the beginning of the list — and `Last`'s snapshot shows the trimmed string and the field converted to `null` — it ran after the normalization middleware, at the end of the list.

Sources: [middleware.md — Global Middleware](repos/laravel/docs/middleware.md#global-middleware); [requests.md — Input Trimming and Normalization](repos/laravel/docs/requests.md#input-trimming-and-normalization) (the documented default-stack members whose effects bracket both positions).

### AT-03 — the default stack provided to the global middleware runs as declared

**Doc says:** "If you would like to manage Laravel's global middleware stack manually, you may provide Laravel's default stack of global middleware to the `use` method" — the example list contains, among others, `TrimStrings::class` and `ConvertEmptyStringsToNull::class`.

- **Given** the manifest declares `kernel.setGlobalMiddleware:` with the doc's example stack — the documented default members — plus a declared `<Recorder>` appended to the list, and a route echoing `$request->input()`; the request carries a padded string field and an empty-string field.
- **When** the route is requested.
- **Then** the recorder ran and the input was trimmed and null-converted — the provided stack is the global stack and its default members (`TrimStrings`, `ConvertEmptyStringsToNull`) operated during the request.

Sources: [middleware.md — Manually Managing Laravel's Default Global Middleware](repos/laravel/docs/middleware.md#manually-managing-laravels-default-global-middleware); [requests.md — Input Trimming and Normalization](repos/laravel/docs/requests.md#input-trimming-and-normalization).

### AT-04 — the manually provided stack is the entire stack: it may be adjusted as necessary

**Doc says:** "Then, you may adjust the default middleware stack as necessary" — the `use` list defines the whole global stack; [requests.md — Disabling Input Normalization](repos/laravel/docs/requests.md#input-trimming-and-normalization) observes the same from the remove side: removing the two middleware "from your application's middleware stack" disables trimming and null-conversion "for all requests".

- **Given** the manifest declares `kernel.setGlobalMiddleware:` with the doc's example stack minus `TrimStrings` and `ConvertEmptyStringsToNull` — the doc's adjustment expressed by omission — and a route echoing `$request->input()`; the request carries a padded string field and an empty-string field.
- **When** the route is requested.
- **Then** the padded string arrives untrimmed and the empty field is not `null` — the omitted defaults no longer run, because the manually provided list is the whole global middleware stack.

Sources: [middleware.md — Manually Managing Laravel's Default Global Middleware](repos/laravel/docs/middleware.md#manually-managing-laravels-default-global-middleware); [requests.md — Disabling Input Normalization](repos/laravel/docs/requests.md#disabling-input-normalization).

---

## 3. Middleware execution semantics ([middleware.md — Defining Middleware](repos/laravel/docs/middleware.md#defining-middleware))

### AT-05 — a global middleware filters the request: reject before the application, or allow it deeper

**Doc says:** "Middleware provide a convenient mechanism for inspecting and filtering HTTP requests entering your application" ([Introduction](repos/laravel/docs/middleware.md#introduction)) and, for the doc's example middleware, "if the given `token` does not match our secret token, the middleware will return an HTTP redirect to the client; otherwise, the request will be passed further into the application." [lifecycle.md](repos/laravel/docs/lifecycle.md#http-console-kernels) places this before routing: "The HTTP kernel is also responsible for passing the request through the application's middleware stack."

- **Given** the manifest declares `kernel.pushMiddleware: [EnsureTokenIsValid]` — the doc's example middleware, which redirects to `/home` unless the `token` input matches — and a route returning its own output.
- **When** the route is requested without the token, and then with the matching token.
- **Then** the tokenless request receives the middleware's redirect to `/home` and never shows the route output, and the tokened request receives the route output — the middleware examined the request and rejected or allowed it before it reached the application.

Sources: [middleware.md — Introduction](repos/laravel/docs/middleware.md#introduction), [Defining Middleware](repos/laravel/docs/middleware.md#defining-middleware); [lifecycle.md — HTTP / Console Kernels](repos/laravel/docs/lifecycle.md#http-console-kernels), [Routing](repos/laravel/docs/lifecycle.md#routing).

### AT-06 — a global middleware can act on the outgoing response after the request is handled

**Doc says:** "Of course, a middleware can perform tasks before or after passing the request deeper into the application" — the doc's `AfterMiddleware` performs its task after `$response = $next($request);` returns. [lifecycle.md — Finishing Up](repos/laravel/docs/lifecycle.md#finishing-up): "the response will travel back outward through the route's middleware, giving the application a chance to modify or examine the outgoing response."

- **Given** the manifest declares `kernel.pushMiddleware: [<AfterMiddleware>]` — the doc's after-middleware shape, which modifies the response after delegating with `$next` — and a route returning its output.
- **When** the route is requested.
- **Then** the response received by the client carries the middleware's modification — the task ran after the request was handled by the application.

Sources: [middleware.md — Middleware and Responses](repos/laravel/docs/middleware.md#middleware-and-responses); [lifecycle.md — Finishing Up](repos/laravel/docs/lifecycle.md#finishing-up).

### AT-07 — declared middleware are resolved via the service container

**Doc says (note):** "All middleware are resolved via the [service container](repos/laravel/docs/container.md), so you may type-hint any dependencies you need within a middleware's constructor."

- **Given** the manifest declares `kernel.pushMiddleware: [<DependencyMiddleware>]` whose constructor type-hints a container-resolvable dependency and whose `handle` emits dependency-derived data, and a route.
- **When** the route is requested.
- **Then** the response shows the dependency-derived data — the service container injected the middleware's constructor argument.

Sources: [middleware.md — Defining Middleware](repos/laravel/docs/middleware.md#defining-middleware) (note); [container.md](repos/laravel/docs/container.md) (the cross-referenced container).

---

## 4. Route middleware exclusions ([middleware.md — Excluding Middleware](repos/laravel/docs/middleware.md#excluding-middleware))

### AT-08 — `withoutMiddleware` on a route does not remove global middleware

**Doc says:** "The `withoutMiddleware` method can only remove route middleware and does not apply to global middleware."

- **Given** the manifest declares `kernel.pushMiddleware: [EnsureTokenIsValid]` (AT-05's middleware) and a route declaring `withoutMiddleware: [EnsureTokenIsValid]` (the declared surface's expression of `Route::withoutMiddleware()`).
- **When** the route is requested without the token.
- **Then** the request is still redirected to `/home` — the route's exclusion removed nothing from the global middleware stack.

Sources: [middleware.md — Excluding Middleware](repos/laravel/docs/middleware.md#excluding-middleware).

---

## 5. Middleware groups ([middleware.md — Middleware Groups](repos/laravel/docs/middleware.md#middleware-groups))

### AT-09 — middleware appended to a group run when the group is assigned to a route

**Doc says:** "Sometimes you may want to group several middleware under a single key to make them easier to assign to routes. You may accomplish this using the `appendToGroup` method" — `$middleware->appendToGroup('group-name', [First::class, Second::class]);` — and "Middleware groups may be assigned to routes and controller actions using the same syntax as individual middleware" — `->middleware('group-name')`.

- **Given** the manifest declares `kernel.setMiddlewareGroups: {'group-name': []}` — the group defined, empty, so the Kernel surface's append can join it (§9 G-7) — and `kernel.appendMiddlewareToGroup: {'group-name': [<First>, <Second>]}` — the doc's two-member append — and a route assigned `middleware: ['group-name']` (the doc's same syntax as individual middleware).
- **When** the route is requested.
- **Then** both `First` and `Second` executed, in the order they were declared — the appended middleware joined the group under the single key and ran with it when the group was assigned to the route.

Sources: [middleware.md — Middleware Groups](repos/laravel/docs/middleware.md#middleware-groups).

### AT-10 — middleware prepended to a group run when the group is assigned to a route

**Doc says:** the same section's example calls `$middleware->prependToGroup('group-name', [First::class, Second::class]);` directly beside `appendToGroup`.

- **Given** the manifest declares `kernel.setMiddlewareGroups: {'group-name': [<First>]}` and `kernel.prependMiddlewareToGroup: {'group-name': [<Prepended>, <Second>]}` — the doc's two-member prepend beside the plan's declared group — and a route assigned `middleware: ['group-name']`.
- **When** the route is requested.
- **Then** `Prepended` and `Second` executed as part of the group, ahead of the group's `First`, with `Prepended` — the first declared — landing first.

Sources: [middleware.md — Middleware Groups](repos/laravel/docs/middleware.md#middleware-groups).

### AT-11 — a default middleware group can be redefined entirely

**Doc says:** "If you would like to manually manage all of the middleware within Laravel's default `web` and `api` middleware groups, you may redefine the groups entirely" — the example redefines `web` via `$middleware->group('web', [...])`. The documented [default `web` group](repos/laravel/docs/middleware.md#laravels-default-middleware-groups) contains `Illuminate\Session\Middleware\StartSession`, and [lifecycle.md](repos/laravel/docs/lifecycle.md#http-console-kernels) attributes session state to the stack: "These middleware handle reading and writing the HTTP session."

- **Given** the manifest declares `kernel.setMiddlewareGroups: {web: [<RecorderA>, <RecorderB>]}` — the default `web` group redefined entirely with two declared middleware — and a route assigned `middleware: [web]` (the doc's group-assignment syntax).
- **When** the route is requested.
- **Then** exactly `RecorderA` and `RecorderB` ran, and no session state is available to the route (the session-starting default member `StartSession` is no longer in the redefined group) — the group now contains only what was declared.

Sources: [middleware.md — Manually Managing Laravel's Default Middleware Groups](repos/laravel/docs/middleware.md#manually-managing-laravels-default-middleware-groups), [Laravel's Default Middleware Groups](repos/laravel/docs/middleware.md#laravels-default-middleware-groups); [lifecycle.md — HTTP / Console Kernels](repos/laravel/docs/lifecycle.md#http-console-kernels).

---

## 6. Middleware aliases ([middleware.md — Middleware Aliases](repos/laravel/docs/middleware.md#middleware-aliases))

### AT-12 — a defined alias resolves to its middleware when assigned to a route

**Doc says:** "Middleware aliases allow you to define a short alias for a given middleware class, which can be especially useful for middleware with long class names" — `$middleware->alias(['subscribed' => EnsureUserIsSubscribed::class])` — and "Once the middleware alias has been defined in your application's `bootstrap/app.php` file, you may use the alias when assigning the middleware to routes" — `->middleware('subscribed')`.

- **Given** the manifest declares `kernel.setMiddlewareAliases: {subscribed: <EnsureUserIsSubscribed>}` — the doc's example alias — and a route assigned `middleware: [subscribed]`, where the middleware's `handle` rejects a user without an active subscription (the doc's alias purpose).
- **When** the route is requested by a user without an active subscription.
- **Then** the `EnsureUserIsSubscribed` middleware executed — the alias resolved to the declared middleware class when it was assigned to the route by its short name.

Sources: [middleware.md — Middleware Aliases](repos/laravel/docs/middleware.md#middleware-aliases).

---

## 7. Sorting middleware ([middleware.md — Sorting Middleware](repos/laravel/docs/middleware.md#sorting-middleware))

### AT-13 — priority order wins over the order middleware are assigned on the route

**Doc says:** "Rarely, you may need your middleware to execute in a specific order but not have control over their order when they are assigned to the route. In these situations, you may specify your middleware priority using the `priority` method" — with the full priority list. [routing.md — Route Group Middleware](repos/laravel/docs/routing.md#route-group-middleware) states the baseline it overrides: "Middleware are executed in the order they are listed in the array."

- **Given** the manifest declares `kernel.setMiddlewarePriority: [<High>, <Low>]` and a route assigning both middleware in the opposite order, `middleware: [<Low>, <High>]` — each records its execution order onto the request.
- **When** the route is requested.
- **Then** `High` executed before `Low` — the priority list ordered them despite the route's listing order.

Sources: [middleware.md — Sorting Middleware](repos/laravel/docs/middleware.md#sorting-middleware); [routing.md — Route Group Middleware](repos/laravel/docs/routing.md#route-group-middleware) (the listing-order baseline).

### AT-14 — middleware inserted before an anchor runs before it, regardless of listing order

**Doc says:** "The `prependToPriorityList` method inserts the given middleware before another middleware" — `$middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureTokenIsValid::class)`. The observable: [routing.md — Implicit Binding](repos/laravel/docs/routing.md#implicit-binding) — Laravel "will automatically inject the model instance" for a type-hinted `{user}` segment, so middleware running before the `SubstituteBindings` anchor sees the raw URI segment, not the model.

- **Given** the manifest declares `kernel.addToMiddlewarePriorityBefore: {'Illuminate\Routing\Middleware\SubstituteBindings': [<PreBindings>]}` and a route `users/{user}` assigning `[SubstituteBindings, PreBindings]` (the anchor listed first), where `PreBindings` records what `$request->route('user')` holds at its turn.
- **When** the route is requested with a numeric `{user}` segment.
- **Then** `PreBindings` recorded the raw segment value — it ran while `{user}` was still unbound, i.e. it was inserted before the `SubstituteBindings` anchor in the priority list.

Sources: [middleware.md — Sorting Middleware](repos/laravel/docs/middleware.md#sorting-middleware); [routing.md — Route Model Binding](repos/laravel/docs/routing.md#route-model-binding), [Implicit Binding](repos/laravel/docs/routing.md#implicit-binding).

### AT-15 — middleware inserted after an anchor runs after it, regardless of listing order

**Doc says:** "while the `appendToPriorityList` method inserts it after another middleware" — `$middleware->appendToPriorityList(after: SubstituteBindings::class, append: EnsureUserIsSubscribed::class)`.

- **Given** the manifest declares `kernel.addToMiddlewarePriorityAfter: {'Illuminate\Routing\Middleware\SubstituteBindings': [<PostBindings>]}` and a route `users/{user}` assigning `[PostBindings, SubstituteBindings]` (the anchor listed second), where `PostBindings` records what `$request->route('user')` holds at its turn.
- **When** the route is requested with a numeric `{user}` segment matching a persisted model.
- **Then** `PostBindings` recorded the retrieved model instance — it ran after `{user}` was already bound, i.e. it was inserted after the `SubstituteBindings` anchor in the priority list.

Sources: [middleware.md — Sorting Middleware](repos/laravel/docs/middleware.md#sorting-middleware); [routing.md — Route Model Binding](repos/laravel/docs/routing.md#route-model-binding), [Implicit Binding](repos/laravel/docs/routing.md#implicit-binding).

---

## 8. Terminable middleware ([middleware.md — Terminable Middleware](repos/laravel/docs/middleware.md#terminable-middleware))

### AT-16 — a global middleware's `terminate` runs automatically after the response is sent

**Doc says:** "Sometimes a middleware may need to do some work after the HTTP response has been sent to the browser. If you define a `terminate` method on your middleware ..., the `terminate` method will automatically be called after the response is sent to the browser"; "The `terminate` method should receive both the request and the response. Once you have defined a terminable middleware, you should add it to the list of routes or global middleware in your application's `bootstrap/app.php` file."

- **Given** the manifest declares `kernel.pushMiddleware: [<TerminatingMiddleware>]` — the doc's example shape: `handle` returns `$next($request)`, `terminate(Request $request, Response $response)` records work — and a route.
- **When** the route is requested and the response is sent (the kernel's terminate phase).
- **Then** `terminate` executed after the response was sent, receiving the request and the response, and its recorded artifact exists — the route's output is unaffected by it.

Sources: [middleware.md — Terminable Middleware](repos/laravel/docs/middleware.md#terminable-middleware); [lifecycle.md — Finishing Up](repos/laravel/docs/lifecycle.md#finishing-up).

### AT-17 — `terminate` runs on a fresh instance resolved from the service container

**Doc says:** "When calling the `terminate` method on your middleware, Laravel will resolve a fresh instance of the middleware from the [service container](repos/laravel/docs/container.md). If you would like to use the same middleware instance when the `handle` and `terminate` methods are called, register the middleware with the container using the container's `singleton` method."

- **Given** the manifest declares `kernel.pushMiddleware: [<TerminatingMiddleware>]` with no singleton registration — the doc's default — where `handle` and `terminate` each record the object identity they ran with.
- **When** the route is requested and the kernel terminates.
- **Then** the recorded identities differ — a fresh instance was resolved from the service container for the `terminate` call.

Sources: [middleware.md — Terminable Middleware](repos/laravel/docs/middleware.md#terminable-middleware).

---

## 9. Documentation gaps — declared surface with no backing docs, and documented behavior with no declarable surface

G-1–G-2: no acceptance test can be written from `docs/repos/laravel/docs/` for the following; each lists the nearest non-backing documentation. These need either an upstream doc reference or a source-derived test (out of scope for this plan). G-3–G-7 are the inverse: documented behavior the declared surface cannot express.

| # | Declared surface | Why no doc-backed test |
|---|---|---|
| G-1 | `prependToMiddlewarePriority`, `appendToMiddlewarePriority` | The docs document only the *anchored* priority insertions — `prependToPriorityList` "inserts the given middleware before another middleware", `appendToPriorityList` "after another middleware" ([middleware.md — Sorting Middleware](repos/laravel/docs/middleware.md#sorting-middleware)); an unanchored insert at the list edge is undocumented. The anchored forms are expressed by `addToMiddlewarePriorityBefore`/`After` (AT-14, AT-15). In-repo mapping: [declarative-kernel.md](declarative-kernel.md) §2.4. |
| G-2 | `whenRequestLifecycleIsLongerThan` | Request-duration handlers have zero matches in the vendored docs. Nearest non-backing docs: [lifecycle.md](repos/laravel/docs/lifecycle.md) (the lifecycle the handler observes) and [pulse.md — Slow Requests](repos/laravel/docs/pulse.md#slow-requests-card) (a Pulse dashboard card with its own 1,000ms default threshold — a different surface). The threshold keys and the handler reference dispatch are framework-source only: [declarative-kernel.md](declarative-kernel.md) §2.5 (`wrapDurationHandler`). |
| G-3 | (inverse) array anchors for priority insertion | "The `before` and `after` arguments may also be an array of middleware classes" ([middleware.md — Sorting Middleware](repos/laravel/docs/middleware.md#sorting-middleware)), but `addToMiddlewarePriorityBefore`/`After` is a map whose anchor is the YAML key — a scalar string — so an array anchor has no declarable expression. |
| G-4 | (inverse) `replace` / `remove` | The docs document replacing default group entries (`$middleware->web(replace: ...)`, `remove:` — [middleware.md — Laravel's Default Middleware Groups](repos/laravel/docs/middleware.md#laravels-default-middleware-groups)) and removing middleware from the stack (`$middleware->remove([...])` — [requests.md — Disabling Input Normalization](repos/laravel/docs/requests.md#disabling-input-normalization)); `kernel:` has no `replace`/`remove` keys — the nearest expressions are wholesale redefinition via `setMiddlewareGroups` (AT-11) and `setGlobalMiddleware` (AT-04). |
| G-5 | (inverse) default-group auto-application | "By default, the `web` and `api` middleware groups are automatically applied to your application's corresponding `routes/web.php` and `routes/api.php` files by the `bootstrap/app.php` file" ([middleware.md — Manually Managing Laravel's Default Middleware Groups](repos/laravel/docs/middleware.md#manually-managing-laravels-default-middleware-groups) note) — a `bootstrap/app.php` surface the `kernel:` block does not own; the tests assign groups to declared routes explicitly (AT-09–AT-11). |
| G-6 | (inverse) shared `handle`/`terminate` instance | The doc's singleton alternative — "register the middleware with the container using the container's `singleton` method" ([middleware.md — Terminable Middleware](repos/laravel/docs/middleware.md#terminable-middleware)) — is a container-registration surface; `kernel:` declares only stack membership, so AT-17 tests the documented default (a fresh instance per `terminate`) only. |
| G-7 | mutating an undefined group | `appendMiddlewareToGroup`/`prependMiddlewareToGroup` on a group that has not been defined throws `InvalidArgumentException` (framework source, [declarative-kernel.md](declarative-kernel.md) §1.4); the vendored docs document no undefined-group behavior for `appendToGroup` — nearest non-backing doc: [middleware.md — Middleware Groups](repos/laravel/docs/middleware.md#middleware-groups). |

---

## 10. Sources

Vendored docs (**system of record**, relative to `docs/repos/laravel/docs/`) with upstream equivalents:

1. [middleware.md](repos/laravel/docs/middleware.md) — https://laravel.com/docs/middleware — Global Middleware (§2), Defining Middleware incl. the container note and Middleware and Responses (§3), Excluding Middleware (§4), Middleware Groups incl. Laravel's Default Middleware Groups and their manual management (§5), Middleware Aliases (§6), Sorting Middleware (§7, §9 G-1/G-3), Terminable Middleware (§8, §9 G-6), Middleware Parameters (route-level surface — not declared here).
2. [lifecycle.md](repos/laravel/docs/lifecycle.md) — https://laravel.com/docs/lifecycle — HTTP / Console Kernels, Routing, Finishing Up (§3 corroboration, §5 AT-11 corroboration, §9 G-2 nearest-non-backing).
3. [requests.md](repos/laravel/docs/requests.md) — https://laravel.com/docs/requests — Input Trimming and Normalization (§2 AT-02/03/04, §9 G-4).
4. [routing.md](repos/laravel/docs/routing.md) — https://laravel.com/docs/routing — Route Group Middleware (§7 baseline), Route Model Binding / Implicit Binding (§7 observables).
5. [container.md](repos/laravel/docs/container.md) — https://laravel.com/docs/container — the container middleware resolution cross-references (§3 AT-07, §8 AT-17).

In-repo subject/mapping context (no tested behavior sourced from these):

6. [declarative-kernel.md](declarative-kernel.md) — the framework-verified mapping contract; cited in §9 G-1/G-2/G-7 only.
7. [README.md — Kernel](../README.md#kernel) — the declared `kernel:` surface and its `Middleware`-object counterparts.