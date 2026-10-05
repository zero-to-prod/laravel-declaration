# Laravel Declaration

A general-purpose manifest engine for Laravel: one YAML body on `Illuminate\Foundation\Application`, validated by a schema projected from the framework's own classes.

## Prompts
name: plan
```md
Write a context complete implementation plan for <component> defined in `README.md`.
Use these steps:
1. Understand the relevant source code:
   1. src/
   2. tests/
2. Understand the relevant documentation in `docs/repos/laravel/docs`
3. Understand the relevant vendor source code
   1. `vendor/laravel/`
   2. `vendor/laravel/`
4. Decompose the problem
5. Justify each implementation detail by referencing the source of truth

Implementation Goal: implement a yml data structure and php implementation that maps 1-to-1 to the laravel API.

Strategy:
- Use dynamic dispatch to keep naming vertically consistent and code simple
- The keys map to function names, the values map to the function signature
- Every key is a native method name; the forms (README § How a key is read) decide the call — no per-component code.
- Use existing patterns in the codebase

Deliverable: 
- [ ] A context complete Markdown file. 
- [ ] All code examples are complete
- [ ] All implementation details are referenced to the source code
- [ ] No code is implemented.
```
name: plan-validate
```md
Iterate line by line through <plan> and validate the document.
1. Visit the source code
2. Visit the vendor source code
    1. `vendor/laravel/`
    2. `vendor/laravel/`
3. Compare the implementation details with the source of truth
4. Extract the diff and update <plan>

Deliverable:
- [ ] No gaps in implementation are found compared to the source of truth
- [ ] Implementation is mapped 1-to-1 to the api
- [ ] <plan> is updated
```
name: plan-simplify
```markdown
Review <plan> and align it to these goals
- [ ] Look at deep underlying patters to expose commonalities
- [ ] Leverage the commonalities to simplify the code
- [ ] Use the forms table (one ordered decision list) to eliminate switch/match statements
- [ ] Use dynamic dispatch to keep naming vertically aligned and bound to the laravel public api
- [ ] Break existing code that does not map onto the API
- [ ] Identify and eliminate all implementation opinions. 
  - This is a thing wrapper around Laravel's API. 
  - Forward data to the api. Nothing more

Goal: Simplify the code by map cleanly onto the Laravel API.

Deliverable:
- [ ] Simplified p
```
name: plan-test
```markdown
Look through `repos/laravel/docs/` and identify the documentation for <component> referenced in `README.md`. 
Write an acceptance test plan based on the documentation.
This means:
- Find the source documentation
- Extract documented behavior
- Write: `given, when, then` tests based on each unique documented behavior
- Write a reference to the sources that back the test.
The deliverable is a Markdown document in @docs/. Do not implement the tests.
```

## Roadmap


### Core Architecture, Container & Configuration
- [x] [Service Container & Application](#application): `registered:` | AC
- [x] [Configuration Repository](#config): `make: Illuminate\Config\Repository` | AC
- [x] [Service Providers](#providers):`register:` | AC

### HTTP Kernel & Middleware Pipeline
- [x] [HTTP Kernel & Middleware Pipeline](#kernel): `afterResolving: Illuminate\Foundation\Http\Kernel`
- [ ] CSRF Verification & Route Exclusions: `csrf:`
- [ ] HTTP Precognition: `precognition:`

### HTTP Routing, Pipeline, URLs & Throttling
- [x] [Router Configuration & Binders](#router): `afterResolving: Illuminate\Routing\Router`
- [x] [Route Registration](#routes): `Router::addRoute` …
- [ ] URL Generation & Signed URLs: `url:`
- [ ] Rate Limiter: `rate_limiter:`

### View Layer, Blade Engine & Presentation
- [x] [View Factory & Namespaces](#view): `afterResolving: Illuminate\View\Factory` | AC
- [x] [Blade Compiler & Directives](#blade): `afterResolving: Illuminate\View\Compilers\BladeCompiler` | AC
- [x] [Pagination View Resolvers & Styling](#pagination): `Illuminate\Pagination\Paginator:`

### Request Lifecycle, Input Resolution & Validation
- [x] [Form Request Declaration](#requests): `requests:`
- [x] [Validation Factory & Custom Rules](#requests): `afterResolving: Illuminate\Validation\Factory` (documented under Requests) | AC

### Response Generation, Redirects & Transport
- [/] [Response Factory & Macros](#response): `afterResolving: Illuminate\Routing\ResponseFactory`
- [ ] Redirector & Redirect Responses: `redirect:`
- [ ] Cookies & Cookie Jar: `cookie:`
- [ ] API Resources & JSON Serialization: `resources:`

### Database Connection, Query Builder, Transactions & Seeding
- [/] [Database Connection & Transactions](#database): `make: Illuminate\Database\Connection`
- [ ] Database Query Builder (Table-Level Queries): `queries:` / `queries.table`
- [x] [Database Schema & Blueprint](#schema): `schema:`
- [ ] Database Seeding & Factories: `seeds:`

### Eloquent ORM & Query Builder
- [x] [Eloquent Model Configuration & Lifecycle](#models): `models:`
- [/] [Eloquent Query Builder (Model Queries)](#queries): `queries:`

### Security, Identity & Access Control
- [/] [Authorization Gates & Policies](#gate): `afterResolving: Illuminate\Auth\Access\Gate`
- [ ] Authentication Manager & Guards: `auth:`
- [ ] Session Store & Flash Data: `session:`
- [ ] Hashing & Encryption: `hashing:`, `encryption:`
- [ ] API Token Authentication (Sanctum): `sanctum:`

### Events, Async & Realtime Systems
- [ ] Events & Dispatcher: `events:`
- [ ] Queues, Workers & Bus: `queues:`, `bus:`
- [ ] Mail & Mailables: `mail:`
- [ ] Notifications & Channels: `notifications:`
- [ ] Broadcasting & WebSockets: `broadcasting:`

### Operations, Console, Storage & Systems
- [ ] Artisan Console Commands: `commands:`
- [ ] Task Scheduling: `schedule:`
- [ ] Cache Repository & Stores: `cache:`
- [ ] Filesystem & Storage Disks: `storage:`
- [ ] Image Manipulation: `image:`
- [ ] Redis: `redis:`
- [ ] Localization & Translation Loader: `lang:`
- [ ] Logging & Context Repository: `logging:`, `context:`
- [ ] Application Telemetry & Monitoring (Pulse): `pulse:`

### Processes, Concurrency & Extensibility
- [ ] Processes & Concurrency: `process:`, `concurrency:`
- [ ] HTTP Client Factory: `http:`
- [ ] Exception Handling & Reporting: `exceptions:`
- [ ] Feature Flags: `features:`
- [ ] Full-Text Search (Scout): `scout:`

## Requirements

- PHP `^8.4`
- [Laravel](https://laravel.com/) 13

## Installation

```bash
composer require zero-to-prod/laravel-declaration
```

### Configuration

Interactive CLI installation. Writes values to `config/laravel-declaration.php`:

```bash
php artisan laravel-declaration:install
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=laravel-declaration-config
```

## Agent development

The package ships with an [MCP](https://modelcontextprotocol.io/) server for agent development.

```bash
composer require --dev laravel/mcp
php artisan mcp:start laravel-declaration
```

Register it with your agent:

```bash
claude mcp add laravel-declaration -- php artisan mcp:start laravel-declaration
```

## Development

```bash
composer check   # lint, rector, phpstan, 100% coverage, schema check — mutates nothing
composer fix     # rector then pint
composer schema  # regenerate manifest.schema.json from its own x-manifest.classes
composer mcp list                      # the server's tools
composer mcp call api '{}'             # call one
```

The schema is generated, never hand-edited:

```bash
php artisan declaration:generate-manifest-schema [classes…] [--from=] [--out=] [--check] [--report]
```

`classes` are the native FQCNs to project; omitted, they come from the prior schema's `x-manifest.classes`, so the
scope lives in the schema it generates and nowhere else. `--from` names the prior whose curated prose and
`x-manifest` curation re-merge on top of the regenerated shapes (default: the `--out` file), `--check` fails when
the regenerated bytes differ from the file (part of `composer check`), `--report` prints the curation-only and
`TODO` keys and writes nothing.

## Manifest

Your application is defined by a single YAML file, the `manifest`. The default location is `./manifest/app.yml`
(`laravel-declaration.manifest`).

The manifest is a **body on `Illuminate\Foundation\Application`**: every root key is one of its methods, applied
in manifest order when the package registers, and the value is that method's argument(s). Timing is written with
the application's own lifecycle methods — `make` for a service that is already resolved, `registered`, `booting`
and `booted` for the three boot hooks, `afterResolving` for a body on a service when it first resolves. Anything
beneath those keys is a body on whichever object the call returns or hands to its callback, read by the same ten
rules. Five root keys are data (`requests`, `models`, `queries`, `schema`, `extra`): stored, never dispatched.

```yaml
# manifest/app.yml — root receiver: Illuminate\Foundation\Application
make:                                                # the services already resolved at register()
  Illuminate\Config\Repository:
    set: {app.name: Tenant Console}
registered:                                          # once every provider has registered
  bind: {App\Contracts\Pdf: App\Services\DomPdf}
  register: [App\Providers\AppServiceProvider]
afterResolving:                                      # when each service first resolves
  Illuminate\Routing\Router:
    addRoute:
      - {methods: GET, uri: /, action: App\Http\HomeController, name: home}
Illuminate\Pagination\Paginator:                     # a static receiver
  useBootstrapFive: ~
schema:                                              # a data key, consumed by declaration:migrate
  create: {users: {id: ~, timestamps: ~}}
```

Validate it against `manifest.schema.json` — the only validation layer:

```bash
php artisan laravel-declaration:validate [--manifest=]
```

### How a key is read

Every key is a native method name; its value is read against that method's signature, first match wins:

1. `~` or `true` — the method is called with no arguments. **A present key always calls**; omit the key to opt out.
2. a scalar — one call with that argument. `false` is a scalar: `shallow: false` calls `shallow(false)`.
3. a list — one call per item, unless the first parameter is array-typed (or curated so), in which case the list
   is the argument. A list item that is a map is a row (rule 5).
4. a map whose keys are **not** parameter names — one call per entry, `method($key, $value)`; a list value fans out
   per item; a map value under a closure parameter is a body on the closure's argument (`afterResolving: {FQCN:
   {…}}`, `create: {users: {…}}`).
5. a map whose first key **is** a parameter name — one call with named arguments; every other key rides the
   **return value** (`addRoute: [{methods, uri, action, name: home, where: {…}}]`, `foreignId: {column: user_id,
   constrained: users, cascadeOnDelete: ~}`).
6. a map under a closure-typed parameter (or `registered`/`booting`/`booted`) — a body on the closure's argument.

Arguments pass through untouched, with four curated resolvers the schema names per parameter: `closure`
(`Class@method`, `Class::method`, an invokable class or a function, wrapped so its positional arguments are paired
by name and the rest injected by the container; or a `.php` file returning a Closure), `phpFile` (a `.php` file's
return value), `concrete` (a class-string, `~` or a `.php` resolver) and `path` (relative paths resolve under
`base_path()`). Everything else is Laravel's: `methods: patch` is passed verbatim (write `PATCH`),
`instance: {Clock: App\Clock}` binds the string (use `singleton`), an unknown key fails with PHP's or Laravel's own
exception, and a key whose method is static (`Illuminate\Pagination\Paginator`) is addressed at the root by its FQCN.

## Config

Set configuration through `Illuminate\Config\Repository::set`. The repository is already resolved when the package
registers, so it is addressed with `make`; a dotted key is one `config()` path, and a map value replaces that node
whole.

```yaml
make:
  Illuminate\Config\Repository:
    set:                                  # -> set($key, $value), one call per entry
      app.name: Tenant Console            # config('app.name'); other app.* keys survive
      cache.stores.redis.connection: cache  # one nested key; the rest of stores.redis survives
      sentinel: {meters: true}            # a key no file declares: gained whole
```

## Application

Container bindings, paths, locale and lifecycle hooks are `Illuminate\Foundation\Application` methods. Declare
them at the root for register time, or under `registered:` to apply once every provider has registered — the place
for bindings that must win over the application's own providers.

```yaml
registered:
  bind:                                         # -> bind($abstract, $concrete), one call per entry
    App\Contracts\Pdf: App\Services\DomPdf      # class-string or another bound abstract
    App\Contracts\Slugger: app/binders/slugger.php   # .php: the returned Closure, called ($app, $parameters)
  bindIf:                                       # -> bindIf(); skipped when bound, deferred services included
    App\Contracts\Cache: App\Services\RedisCache
  singleton:                                    # -> singleton()
    App\Services\TenantContext: ~               # ~ -> self-binding
    App\Contracts\Clock: App\Services\Clock     # the native way to share one instance
  singletonIf:
    - App\Services\SlowWarmup                   # list: every item binds its own name
  scoped:                                       # -> scoped(); shared until forgetScopedInstances()
    - App\Services\RequestLog
  instance:                                     # -> instance($abstract, $instance)
    app.signature: "1.0"                        # literal, bound as YAML decoded it
    app.rate_limiter: app/instances/limiter.php # .php: its return value, any type
  alias:                                        # -> alias($abstract, $alias); abstract first
    App\Services\TenantContext: context         # app('context') resolves the singleton
  extend:                                       # -> extend($abstract, Closure); receives $instance, $app
    cache.store: app/extensions/store-cache.php
  tag:                                          # -> tag($abstracts, $tags)
    App\Reports\Cpu: reports
  when:                                         # -> when($concrete)->needs()->give(): a body on the builder
    App\Http\Controllers\PhotoController:
      needs: App\Contracts\Filesystem
      give: App\Services\LocalFs                # or a .php file; a list for a typed variadic
  useAppPath: src                               # -> useAppPath(base_path('src')); rebinds `path`
  useLangPath: resources/lang                   # relative paths resolve under base_path()
  useStoragePath: /var/app/storage              # absolute, used as-is
  setLocale: fr                                 # -> setLocale(); dispatches LocaleUpdated
  setFallbackLocale: en
  resolving:                                    # -> resolving($abstract, Closure), one call per entry
    App\Services\Transistor: app/listeners/warm.php
  registered:                                   # -> registered($callback): a reference per item …
    - app/hooks/registered.php
  booting:                                      # … or a map: a body on the application at that moment
    - App\Hooks\WarmConnections
  booted:
    make:
      Illuminate\Database\Connection:           # a service other providers resolved first
        listen: [App\Listeners\LogQuery]
  terminating:                                  # -> terminating(); after the response, injected by the container
    - App\Hooks\FlushMetrics
```

Extend functionality:

```php
// app/extensions/store-cache.php — the file IS the extender
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Application;

return static function (Repository $instance, Application $app): Repository {
    return $instance;   // called ($instance, $app) by the container
};
```

## Providers

Register the application's service providers with `register`, at register time and in manifest order; they boot
with every other provider.

```yaml
register:
  - App\Providers\AppServiceProvider      # -> register($provider), one call per item
```

## Router

Every `Illuminate\Routing\Router` method is a key under `afterResolving.Illuminate\Routing\Router`, applied in
manifest order when the router first resolves: configuration and binders first, then the registration methods
(`addRoute`, `group`, `resource`, `view`, `redirect`, …). A route's extra keys ride the returned `Route`.

```yaml
afterResolving:
  Illuminate\Routing\Router:
    pattern:                    # -> pattern($key, $pattern), one call per entry
      id: '[0-9]+'              # every {id} of every route created afterwards
      account: '[a-z]+'         # domain parameters too: {account}.example.com
    model:                      # -> model($key, $class), one call per entry
      user: App\Models\User     # {user} -> User::resolveRouteBinding($value); 404 when null
    bind:                       # -> bind($key, $binder), one call per entry
      post: App\Routing\PostBinder     # make(PostBinder)->bind($value, $route)
      team: App\Routing\Teams@bySlug   # make(Teams)->bySlug($value, $route)
    middlewareGroup:            # -> middlewareGroup($name, $middleware), one call per entry
      tenant:                   # middleware: [tenant] expands to these at dispatch
        - auth
        - App\Http\Middleware\TenantIdentified
    aliasMiddleware:            # -> aliasMiddleware($name, $class), one call per entry
      subscribed: App\Http\Middleware\EnsureSubscription
    prependMiddlewareToGroup:   # -> prependMiddlewareToGroup($group, $middleware), one call per item (reversed: declared order lands at the head)
      web: [App\Http\Middleware\TenantLocate]
    pushMiddlewareToGroup:      # -> pushMiddlewareToGroup($group, $middleware), one call per item
      api: [App\Http\Middleware\RequestTracing]
    removeMiddlewareFromGroup:  # -> removeMiddlewareFromGroup($group, $middleware), one call per item
      api: [App\Http\Middleware\StatefulGuard]
    singularResourceParameters: false   # -> singularResourceParameters(false): {posts}, not {post}
    resourceParameters:         # -> resourceParameters($parameters), the whole map
      posts: item
    resourceVerbs:              # -> resourceVerbs($verbs), the whole map
      create: nuevo
    matched:                    # -> matched($callback), one call per item
      - App\Listeners\LogMatched@handle

    addRoute:                   # -> addRoute($methods, $uri, $action), one call per row
      - uri: "users/{user}"
        methods: GET                         # passed verbatim: write the verb Laravel expects
        action: [App\Http\Controllers\UserController, show]
        name: users.show                     # every other key rides the returned Route: -> name()
        prefix: api                          # -> prefix()
        domain: "{account}.example.com"      # -> domain()
        middleware: [auth:sanctum, verified] # -> middleware(), one call per item
        withoutMiddleware: [web]             # -> withoutMiddleware()
        can: {ability: view, models: user}   # -> can(ability: …, models: …)
        where: {user: '[0-9]+'}              # -> where($name, $expression), one call per entry
        setDefaults: {user: 1}               # -> setDefaults($defaults)
        setBindingFields: {user: slug}       # -> setBindingFields($fields)
        missing: App\Http\Handlers\UserMissing   # -> missing(Closure): a `closure` reference, called ($request, $exception)
        scopeBindings: ~                     # -> scopeBindings()
        withTrashed: ~                       # -> withTrashed()
        block: {lockSeconds: 10, waitSeconds: 5}   # -> block(lockSeconds: …, waitSeconds: …)
        metadata: {group: admin}             # -> metadata($metadata)
      - uri: "{any}"
        methods: GET
        action: App\Http\Controllers\FallbackController
        where: {any: '.*'}                   # mirrors Router::fallback(); without it {any} matches one segment
        fallback: ~                          # -> fallback()

    group:                      # -> group($attributes, $routes): the native attributes + a body on the router inside
      - attributes: {prefix: admin, as: admin., middleware: [web], where: {id: '[0-9]+'}}
        routes:
          addRoute:
            - {uri: dashboard, methods: GET, action: App\Http\Admin\DashboardController, name: dashboard}
          group:                # nested groups concatenate prefix/as/namespace
            - attributes: {prefix: settings, as: settings.}
              routes: {addRoute: [{uri: profile, methods: GET, action: App\Http\Admin\ProfileController, name: profile}]}

    resource:                   # -> resource($name, $controller); the other keys ride the PendingResourceRegistration
      - name: photos
        controller: App\Http\Controllers\PhotoController
        only: [index, show]     # -> only([index, show])
        middleware: [web]
        whereNumber: photo
        scoped: ~               # -> scoped()
        names: {index: gallery.index}
        missing: App\Http\Handlers\PhotoMissing
    apiResource:
      - {name: posts, controller: App\Http\Controllers\PostController, except: [destroy]}
    singleton:
      - {name: profile, controller: App\Http\Controllers\ProfileController, creatable: ~}
    view:                       # -> view($uri, $view, $data, $status, $headers) (GET|HEAD)
      - {uri: about, view: pages.about, data: {title: About}, headers: {X-Frame-Options: DENY}}
    redirect:                   # -> redirect($uri, $destination, $status)
      - {uri: "old-posts/{post}", destination: "posts/{post}", status: 302}
    permanentRedirect:
      - {uri: legacy, destination: /}
```

`singularResourceParameters` / `resourceParameters` / `resourceVerbs` are `ResourceRegistrar` global statics for
resource routes: re-run `route:cache` after editing them. An unknown key riding a `PendingResourceRegistration`
fails at boot with Laravel's own `BadMethodCallException`. The HTTP kernel syncs its own middleware groups and
aliases to the router when it resolves, so a group both declare is the kernel's.

## Kernel

`Illuminate\Foundation\Http\Kernel` methods, applied when the HTTP kernel first resolves. The callback is matched
by type, so the concrete FQCN fires for the contract-bound `Illuminate\Contracts\Http\Kernel`.

```yaml
afterResolving:
  Illuminate\Foundation\Http\Kernel:
    pushMiddleware:                             # -> pushMiddleware($middleware), one call per item
      - App\Http\Middleware\GlobalLast
    prependMiddleware:                          # -> prependMiddleware($middleware), one call per item (reversed)
      - App\Http\Middleware\GlobalFirst
    setGlobalMiddleware:                        # -> setGlobalMiddleware($middleware), the whole list
      - App\Http\Middleware\CustomGlobalStack
    appendMiddlewareToGroup:                    # -> appendMiddlewareToGroup($group, $middleware), one call per item
      web: App\Http\Middleware\TrackWebActivity
      api:
        - App\Http\Middleware\EnforceJsonResponse
    prependMiddlewareToGroup:                   # -> prependMiddlewareToGroup($group, $middleware), reversed
      web: App\Http\Middleware\WebMaintenanceBypass
    setMiddlewareGroups:                        # -> setMiddlewareGroups($groups), the whole map
      custom:
        - App\Http\Middleware\CustomMiddleware
    setMiddlewareAliases:                       # -> setMiddlewareAliases($aliases), the whole map
      subscribed: App\Http\Middleware\EnsureUserIsSubscribed
      token_auth: App\Http\Middleware\EnsureTokenIsValid
    setMiddlewarePriority:                      # -> setMiddlewarePriority($priority), the whole list
      - App\Http\Middleware\HighPriority
      - App\Http\Middleware\LowPriority
    prependToMiddlewarePriority:                # -> prependToMiddlewarePriority($middleware), reversed
      - App\Http\Middleware\UltraHighPriority
    appendToMiddlewarePriority:                 # -> appendToMiddlewarePriority($middleware)
      - App\Http\Middleware\UltraLowPriority
    addToMiddlewarePriorityBefore:              # -> addToMiddlewarePriorityBefore($before, $middleware)
      Illuminate\Routing\Middleware\SubstituteBindings: App\Http\Middleware\PreSubstituteBindings
    addToMiddlewarePriorityAfter:               # -> addToMiddlewarePriorityAfter($after, $middleware)
      Illuminate\Routing\Middleware\SubstituteBindings: App\Http\Middleware\PostSubstituteBindings
    whenRequestLifecycleIsLongerThan:           # -> whenRequestLifecycleIsLongerThan($threshold, $handler)
      250: App\Listeners\ReportSlowRequest      # milliseconds; a `closure` reference called ($startedAt, $request, $response)
```

## View

`Illuminate\View\Factory` methods, applied when the view factory first resolves.

```yaml
afterResolving:
  Illuminate\View\Factory:
    addLocation: [resources/declared-views]      # -> addLocation($location), one call per item; relative under base_path()
    prependLocation: [resources/theme]           # searched before config('view.paths')
    addNamespace:                                # -> addNamespace($namespace, $hints), one call per entry
      admin: resources/admin-views               # view('admin::dashboard')
    prependNamespace:
      courier: [resources/overrides/courier]     # overrides a package's views
    replaceNamespace:
      legacy: resources/legacy-views
    addExtension:                                # -> addExtension($extension, $engine)
      html: blade
    share:                                       # -> share($key, $value), one call per entry
      brand: Tenant Console
    composer:                                    # -> composer($views, $callback)
      - {views: users.*, callback: App\View\Composers\UserMenu}          # make(UserMenu)->compose($view)
      - {views: '*', callback: App\View\Composers\CurrentTenant}         # every view; quote `*`
      - {views: [layouts.app, layouts.admin], callback: App\View\Composers\Nav@primary}
    creator:                                     # -> creator($views, $callback), default method `create`
      users.show: App\View\Creators\Breadcrumbs  # the entry form for one view
```

When another provider resolved the factory before this package registered (its finder has cached finds),
address it once every provider has booted:

```yaml
booted:
  make:
    Illuminate\View\Factory:
      prependLocation: [resources/theme]
      flushFinderCache: ~                        # -> flushFinderCache(): declared locations win over earlier finds
      flushState: ~                              # -> flushState(): resets sections, stacks, components and fragments
```

View routes also declare a render-time Factory dispatch: `setDefaults.factory` maps one `Illuminate\View\Factory`
method name to its argument list — `factory: {file: resources/legal/terms.html}` — dispatched as
`$Factory->{$method}(...$arguments)` (docs/declarative-view-factory.md).

## Blade

`Illuminate\View\Compilers\BladeCompiler` methods, applied when the compiler first resolves. `callable`
parameters take `closure` references.

```yaml
afterResolving:
  Illuminate\View\Compilers\BladeCompiler:
    directive:                          # -> directive($name, $handler); receives $expression
      uppercase: App\Blade\Directives@uppercase
    if:                                 # -> if($name, $callback); declares @admin / @unlessadmin conditionals
      admin: App\Blade\Conditions@isAdmin
    component:                          # -> component($class, $alias), one call per entry
      App\View\Components\Alert: alert
    components:                         # -> components($components), the whole map (alias -> class)
      alert: App\View\Components\Alert
    anonymousComponentPath:             # -> anonymousComponentPath($path, $prefix), one call per row
      - path: resources/views/components
        prefix: ui
    anonymousComponentNamespace:        # -> anonymousComponentNamespace($directory, $prefix)
      - directory: resources/views/namespaced
        prefix: ns
    stringable:                         # -> stringable($class, $handler); echo handler, receives $target
      App\ValueObjects\Money: App\Blade\Money::render
    withoutDoubleEncoding: ~            # -> withoutDoubleEncoding(): {{ $html }} is not double-encoded
```

## Pagination

`Illuminate\Pagination\Paginator`'s presets are static methods, so the paginator is a root key addressed by its
FQCN. Keys apply in manifest order; each preset overwrites both default views, so the last one declared wins,
and `defaultView` / `defaultSimpleView` override a preset declared before them. A present key calls: omit a
preset to leave it unapplied (`useTailwind: false` still calls it).

```yaml
Illuminate\Pagination\Paginator:
  useTailwind: ~                      # -> Paginator::useTailwind()
  useBootstrap: ~                     # -> Paginator::useBootstrap() — alias for useBootstrapFour()
  useBootstrapThree: ~                # -> Paginator::useBootstrapThree()
  useBootstrapFour: ~                 # -> Paginator::useBootstrapFour()
  useBootstrapFive: ~                 # -> Paginator::useBootstrapFive()
  defaultView: pagination::custom     # -> Paginator::defaultView($view)
  defaultSimpleView: pagination::simple-custom   # -> Paginator::defaultSimpleView($view)
```

## Requests

Define your applications requests in the `requests` data list; a route names one through `metadata: {request: …}`.

Complete structure:

```yaml
requests:
  - name: user                                   # reserved: the handle routes reference
    authorize: App\Http\Gates\CreateUser          # -> authorize(); bool | reference | Gate-call map (see Gate); absent -> true
    rules:                                        # -> rules(); map | reference
      name: [required, string, max:255]           # Laravel rules, untouched
      nickname: nullable|string|max:32            # pipe string, untouched
      role: [required, 'exists:App\Models\Role,name']   # `\` after the `:` -> Laravel param
      slug: [required, App\Rules\Slug]            # rule class -> make()
      email: [required, 'App\Rules\UniqueTenantEmail::forRequest']   # called -> returns the rule
      discount:                                   # conditional rules ≙ Rule::when() / Rule::unless()
        - unless:                                 # -> unless($condition, $rules): $rules apply when falsy
            condition: App\Rules\IsAdmin          # bool | reference receiving $request
            rules: [prohibited]
    messages:                                     # -> messages(); map | reference
      name.required: A name is required.
    attributes:                                   # -> attributes(); map | reference
      email: email address
    validationData: App\Http\Hooks\Data@handle    # -> validationData(); absent -> $this->all()
    prepareForValidation: App\Http\Hooks\TitleCaseName@handle   # -> prepareForValidation()
    passedValidation: App\Http\Hooks\Audit@handle # -> passedValidation()
    withValidator: App\Http\Hooks\Extra@handle    # -> withValidator(); receives $validator
    after:                                        # -> after(); list of references, each receives $validator
      - App\Validation\ValidateUserStatus
    validator: App\Http\Hooks\Build@make          # -> validator(); receives $factory; replaces rules/messages/attributes
    failedValidation: App\Http\Hooks\Respond@handle     # runs, then Laravel throws ValidationException
    failedAuthorization: App\Http\Hooks\Deny@handle     # runs, then Laravel throws AuthorizationException
    redirect: /users                              # -> $redirect ≙ #[RedirectTo]
    redirectRoute: users.index                    # -> $redirectRoute ≙ #[RedirectToRoute]
    redirectAction: App\Http\Controllers\UserController@index   # -> $redirectAction
    errorBag: user                                # -> $errorBag ≙ #[ErrorBag]
    stopOnFirstFailure: true                      # -> $stopOnFirstFailure ≙ #[StopOnFirstFailure]
    shouldFailOnUnknownFields: true               # -> shouldFailOnUnknownFields() ≙ #[FailOnUnknownFields]

afterResolving:
  Illuminate\Routing\Router:
    addRoute:
      - uri: users
        methods: POST
        action: [App\Http\Controllers\UserController, store]
        metadata:
          request: user                           # -> Route::metadata(['request' => 'user'])
```

Factory-wide custom rules are `Illuminate\Validation\Factory` registry methods, applied when the validator
first resolves (docs/declarative-validator.md):

```yaml
afterResolving:
  Illuminate\Validation\Factory:
    extend:                                       # -> extend($rule, $extension, $message = null)
      uppercase: App\Validators\Uppercase@check  # Class@method | bare class-string (default method `validate`)
    extendImplicit:                               # runs even when the field is absent/empty
      - rule: phone                               # the row form names the optional $message
        extension: App\Validators\Phone
        message: 'The :attribute must be a phone number.'
    extendDependent:                              # parameters may reference other fields
      guardedMin: App\Validators\GuardedMin@check
    replacer:                                     # -> replacer($rule, $replacer); default method `replace`
      uppercase: App\Validators\Uppercase@replace
```

The action:

```php
use ZeroToProd\LaravelDeclaration\DeclaredRequest;

public function store(DeclaredRequest $request): RedirectResponse
{
    $validated = $request->validated();   // runtime accessors unchanged
}
```

## Gate

Register policies and abilities on the shared gate with `Illuminate\Auth\Access\Gate` methods, applied when the
gate first resolves (the concrete class is matched by type for the contract binding; docs/declarative-gate.md):

```yaml
afterResolving:
  Illuminate\Auth\Access\Gate:
    policy:                                            # -> policy($class, $policy), one call per entry
      App\Models\Post: App\Policies\PostPolicy
    define:                                            # -> define($ability, $callback), one call per entry
      publish: App\Gates\PublishGate@publish           # 'Class@method' | bare invokable class-string
```

A request's `authorize` map form declares one native Gate call for the request user — the key is
the `Illuminate\Contracts\Auth\Access\Gate` method name (`check`, `any`, `none`, `allows`,
`denies`, `inspect`, `authorize`, `raw`) and the value is a map of that method's native
parameter names:

| YAML key | Native parameter | Resolution |
|---|---|---|
| `ability` | `$ability` of `allows`/`denies`/`authorize`/`inspect`/`raw` | passes through |
| `abilities` | `$abilities` of `check`/`any`/`none` | a string or a list |
| `arguments` | `$arguments` (default `[]`) | a class-string passes through, a route parameter name resolves to its bound value, a quoted literal unquotes, a non-string passes through; a list resolves per entry |

```yaml
requests:
  - name: post
    authorize:                                       # ≙ Gate::inspect('update', <route-bound {post}>)
      inspect:
        ability: update
        arguments: post                              # native can:update,post semantics
    rules:
      title: [required, string]

  - name: comments
    authorize:                                       # ≙ Gate::check('viewAny', App\Models\Comment) → bool
      check:
        abilities: viewAny
        arguments: App\Models\Comment
    rules:
      body: [required, string]
```

Denied semantics are native: a `bool` denial runs the declared `failedAuthorization` reference,
then throws `AuthorizationException`; a denied `Response` (`inspect`, `raw`) is `->authorize()`d
inside `passesAuthorization()` and throws there; `Gate::authorize()` throws directly.

## Models

Define your application's Eloquent models in the `models` data list. Each class
extends `ZeroToProd\LaravelDeclaration\DeclaredModel`, and its entry is the
class body: every key is a `Model` property (or `observe`, `addGlobalScope`,
`getRouteKeyName`). Relations, accessors and local scopes stay methods on the
class.

Complete structure:

```yaml
models:
  - class: App\Models\Flight                 # reserved: final class Flight extends DeclaredModel
    connection: mysql                        # -> $connection ≙ #[Connection]
    table: my_flights                        # -> $table ≙ #[Table(name)]
    primaryKey: flight_id                    # -> $primaryKey ≙ #[Table(key)]
    keyType: string                          # -> $keyType ≙ #[Table(keyType)]
    incrementing: false                      # -> $incrementing ≙ #[WithoutIncrementing]
    timestamps: true                         # -> $timestamps ≙ #[WithoutTimestamps] when false
    dateFormat: U                            # -> $dateFormat ≙ #[DateFormat]
    attributes: {delayed: false, options: '[]'}   # -> $attributes: raw, storable defaults
    casts: {delayed: boolean, options: array}     # -> $casts; casts() in the class is merged over it
    fillable: [name, code]                   # -> $fillable ≙ #[Fillable]
    guarded: ['*']                           # -> $guarded ≙ #[Guarded]; [] ≙ #[Unguarded]
    hidden: [secret]                         # -> $hidden ≙ #[Hidden]
    visible: []                              # -> $visible ≙ #[Visible]
    appends: [label]                         # -> $appends ≙ #[Appends]
    with: [airline]                          # -> $with: eager loaded by every query
    withCount: [passengers]                  # -> $withCount
    touches: [airline]                       # -> $touches ≙ #[Touches]
    refreshes: [status]                      # -> $refreshes ≙ #[Refreshes]
    perPage: 25                              # -> $perPage
    dispatchesEvents: {created: App\Events\FlightCreated}   # -> $dispatchesEvents
    observables: [boarding]                  # -> $observables
    observe: [App\Observers\FlightObserver]  # -> observe() at boot ≙ #[ObservedBy]
    addGlobalScope: [App\Models\Scopes\NotCancelled]   # -> addGlobalScope() at boot ≙ #[ScopedBy]
    getRouteKeyName: code                    # -> getRouteKeyName() ≙ #[RouteKey]; Router::model binds by it
```

The class:

```php
use ZeroToProd\LaravelDeclaration\DeclaredModel;

final class Flight extends DeclaredModel
{
    public function airline(): BelongsTo { return $this->belongsTo(Airline::class); }
}
```

## Queries

Declare reusable Eloquent query pipelines in the `queries` data list. The reserved
key `name` names the query. `model` roots the query on an Eloquent model class
(`App\Models\Flight`); `relation` roots it on a bound route parameter relation
(`user.posts`). Every other key is an `Illuminate\Database\Eloquent\Builder`
method name, and its value is that method's argument(s). A terminal execution
method (`paginate`, `simplePaginate`, `cursorPaginate`, `get`, `first`,
`firstOrFail`, `sole`, `count`, `exists`, `value`, `pluck`) executes the
pipeline, defaulting to `get()`. Dynamic request arguments stay in **local
scopes** on the Model class (`#[Scope]`).

Complete structure:

```yaml
queries:
  # Route parameter relation with scopes, eager loading and pagination
  - name: user-posts
    relation: user.posts                         # route parameter {user} -> $user->posts()
    where: [status, published]                   # -> where('status', '=', 'published')
    with: [author]                               # -> with(['author'])
    withCount: [comments]                        # -> withCount(['comments'])
    scopes: [featured]                           # -> local scope featured() on Post
    latest: published_at                         # -> latest('published_at')
    paginate: 10                                 # terminal -> paginate(10)

  # Direct model root with scalar aggregate terminal
  - name: active-flight-count
    model: App\Models\Flight                     # model root -> Flight::query()
    where: [status, active]
    count: true                                  # terminal -> count()
```

Declared query pipelines can be executed directly via `DeclaredQuery::run()` or
resolved automatically in `DeclaredView` `data:` mappings:

```yaml
afterResolving:
  Illuminate\Routing\Router:
    addRoute:
      - uri: "users/{user}/posts"
        methods: GET
        action: ZeroToProd\LaravelDeclaration\DeclaredView
        name: users.posts
        middleware: [web]                            # SubstituteBindings binds {user}
        setDefaults:
          view: users.posts
          data:
            title: User Articles                     # literal string
            posts: user-posts                        # declared query handle!
```

## Database

Query listeners are `Illuminate\Database\Connection::listen($callback)` calls. `DB::` resolves connections through
the database manager rather than the container, so address the connection with `make` once every provider has
booted; `make` resolves the default connection (`db.connection`).

```yaml
booted:
  make:
    Illuminate\Database\Connection:
      listen:                             # -> listen(Closure), one call per item: a `closure` reference receiving $query
        - App\Listeners\LogQueries@handle
```

## Schema

Declare your database schema in the `schema` data key (docs/declarative-schema.md,
docs/declarative-schema-table-operations.md). It is a body on
`Illuminate\Database\Schema\Builder` — `create`, `table`, `rename`, `drop`,
`dropIfExists` — driven by `php artisan declaration:migrate` in the order
dropIfExists → drop → rename → create → table, each call guarded against the live
schema (a table is created when missing, a column added when missing or changed when
`change: ~` is declared, an index or foreign key added when missing, a `drop*` run
when its target exists). A table body is a `Blueprint`: every key is a `Blueprint`
method name and its value is that method's argument(s) — `~` for no arguments, a
list for one call per item, a map keyed by parameter names for one call whose
extra keys ride the returned column, index or foreign key (`unique: ~` -> `->unique()`).

Complete structure:

```yaml
schema:
  create:                             # -> create($table, $callback); skipped when the table exists
    users:
      id: ~                           # -> $table->id()
      string:
        - name                        # -> $table->string('name')
        - column: email               # named parameters match the native method's parameters
          unique: ~                   # -> ->unique() rides the ColumnDefinition
        - password
      timestamp:
        column: email_verified_at
        nullable: ~                   # -> ->nullable()
      foreignId:
        column: team_id
        constrained: teams            # -> ->constrained('teams') returns the ForeignKeyDefinition …
        cascadeOnDelete: ~            # … -> ->cascadeOnDelete() rides it
      rememberToken: ~
      timestamps: ~

  table:                              # -> table($table, $callback); every action is guard-checked
    users:
      renameColumn:
        from: login                   # -> $table->renameColumn('login', 'email')
        to: email
      dropColumn: [obsolete]          # -> $table->dropColumn('obsolete')
      dropTimestamps: ~               # -> $table->dropTimestamps()

  rename:                             # -> rename($from, $to); one call per entry
    old_users: users
  drop:                               # -> drop($table); fails loudly when the table is missing
    - legacy
  dropIfExists:                       # -> dropIfExists($table); one call per item
    - scratch
```

Run it:

```bash
php artisan declaration:migrate [--connection=]   # alias: laravel-declaration:migrate
```

## Response

Register macros on the shared `Illuminate\Routing\ResponseFactory`, applied when the factory first resolves. The
handler is a `closure` reference: it receives the macro's arguments and the container injects the rest.

```yaml
afterResolving:
  Illuminate\Routing\ResponseFactory:
    macro:                              # -> macro($name, $macro), one call per entry
      csv: App\Http\Responses\Csv@make  # response()->csv(...) -> Csv@make(...)
```

Declarative response *forms* — `make`, `view`, `json`, `noContent`, `stream`, `download` — are not yet manifest surfaces (docs/declarative-tier1-remaining.md).

## License

MIT. See [LICENSE](LICENSE).
