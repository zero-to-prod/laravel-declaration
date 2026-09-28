# Laravel Declaration

A declarative plugin for Laravel

## Requirements

- PHP `^8.5`
- [Laravel](https://laravel.com/) 13

## Installation

```bash
composer require zero-to-prod/laravel-declaration
```

### Configuration

CLI install. It asks for every value the package can be configured with and
writes `config/laravel-declaration.php`:

```bash
php artisan laravel-declaration:install
```

Rerunning it is safe: the file reports `created`, `unchanged` or `updated`, and
is only overwritten once you confirm.

To publish the configuration file by itself instead:

```bash
php artisan vendor:publish --tag=laravel-declaration-config
```

## Agent development

The package registers an [MCP](https://modelcontextprotocol.io/) server so
coding agents can read how it is meant to be used. It requires
[`laravel/mcp`](https://github.com/laravel/mcp), and registers nothing without
it.

```bash
composer require --dev laravel/mcp
php artisan mcp:start laravel-declaration
```

Register it with your agent:

```bash
claude mcp add laravel-declaration -- php artisan mcp:start laravel-declaration
```

Three tools are exposed:

- `readme` — this document.
- `api` — the exact signature of every public class, property and method.
  Anything unlisted is internal and may change in any release.
- `install` — what `laravel-declaration:install` does, without a prompt to answer.
  Takes `enabled` and `handle`, each defaulting to the current setting, and
  writes `config/laravel-declaration.php`. A file that already says something else
  is left alone and reported until the call passes `overwrite: true`.

Point the handle somewhere else, or turn the server off, in
`config/laravel-declaration.php`:

```php
'mcp' => [
    'enabled' => true,
    'handle' => 'laravel-declaration',
],
```

## Development

```bash
composer check   # lint, rector, phpstan, 100% coverage, bc-check — mutates nothing
composer fix     # rector then pint
composer mcp list                      # the server's tools
composer mcp call api '{}'             # call one
```

`composer check` requires a coverage driver (Xdebug or pcov); without one Pest
cannot satisfy the `--min=100` gate.

## Manifest

`LaravelDeclarationProvider` resolves the YAML file at
`laravel-declaration.manifest` (default `manifest/app.yml`) in `register()`,
applies its `config` there, applies its `app` block once every eager provider
has registered, and registers its providers and routes in `boot()`. A missing
file registers nothing. Tests:
`tests/Feature/ManifestFactoryTest.php`, `tests/Feature/RouteRegistrationTest.php`,
`tests/Feature/DeclaredRequestTest.php`, `tests/Feature/ConfigRegistrationTest.php`,
`tests/Feature/ApplicationRegistrationTest.php`.

The top-level keys are `config`, `app`, `providers`, `routes` and `requests`.
Complete structure per feature under [Config](#config),
[Application](#application), [Providers](#providers), [Routes](#routes) and
[Requests](#requests).

## Config

The `config` block is the argument to `config([...])`, written in YAML: every
`<file>.<key>` path is a `config()` key, set with `Config::set()` in
`register()`. `app` is `./config/app.php`, so `app: {name: X}` is
`config(['app.name' => 'X'])` and every other `app.*` key survives. A key may
be a dot-path (`stores.redis.connection`) to change one nested value; a map
value replaces that node whole. A file key no config file declares is gained
whole; a scalar under a file key throws a `LogicException`. Every provider's
`boot()` and every declared provider see the values. Keys Laravel consumes
before providers register (`app.env`, `app.timezone`) change in `config()`
only. Values pass through as YAML decoded them — declare literals, not
`env(...)` expressions. `config:cache` bakes the values in, like any config
file: re-run it after editing the `config` block. See
`tests/Feature/ConfigRegistrationTest.php` and
[docs/declarative-configuration.md](docs/declarative-configuration.md).

Complete structure:

```yaml
config:                       # the config() key space
  app:                        # ./config/app.php
    name: Tenant Console      # config('app.name'); other app.* keys survive
  cache:
    stores.redis.connection: cache  # one nested key; the rest of stores.redis survives
  sentinel:                   # a key no file declares: gained whole
    meters: true
```

## Application

The `app` block maps 1:1 onto
[`Illuminate\Foundation\Application`](https://laravel.com/docs/container)
methods: every key is an `Application` (or inherited `Container`) method name,
and its value is that method's argument(s). A map is one call per entry
(`abstract: concrete`); a list is one call per item. `LaravelDeclarationProvider`
applies the block in a `registered()` callback, after every eager provider's
`register()` and once deferred services are known — the phase
`ApplicationBuilder::withBindings()` uses. Every provider's `boot()`, every
declared provider and everything at runtime see the declarations; an eager
provider's `register()` does not. The top-level `app` block is the
`Application`; `config.app` is `./config/app.php`. See
`tests/Feature/ApplicationRegistrationTest.php` and
[docs/declarative-application.md](docs/declarative-application.md).

Keys apply in a fixed order, not document order: the six binding keys,
`instance`, `alias`, `extend`, the paths, `setLocale`, `setFallbackLocale`, then
the hooks. When two keys name the same abstract the later one wins, except the
`*If` keys, which skip an abstract that is already bound (deferred services
included).

A binding value is a class-string or another bound abstract (not
`Class@method`), `~` for a self-binding, or a `.php` file. A list item is the
abstract itself and self-binds. `extend` and the hook keys take a PHP
reference: an invokable FQCN, `Class@method`, `Class::method` (static) or a
namespaced function (load it via Composer `autoload.files`). There is no array
form. Write references plain or single-quoted; double quotes make `\` an
escape.

A string ending in `.php` is a file, required once per process, whose return
value is used — the only way to declare a Closure. It must return a `Closure`
everywhere except `instance`. A `.php` binding is Laravel's factory, called
positionally as `($app, $parameters)`; a `.php` list item binds under its
Closure's return types. A relative file or path resolves under `basePath()`;
one starting with `/` or `\` is used as-is.

`extend` and `registered`/`booting`/`booted` references run through
`Container::call()` with `$instance` (the extended value, `extend` only) and
`$app`. Parameters match by **name**: `Repository $store` in an extender is a
fresh `make()`, not the instance being extended. A `terminating` reference
receives no named arguments — Laravel's `terminate()` calls it, so everything is
injected by type-hint.

`instance` `make()`s a class- or interface-string eagerly, binds a `.php` file's
return value, and binds anything else exactly as YAML decoded it. `use*Path`
rebinds its `path.*` instance and moves its helper (`app_path()`,
`storage_path()`, ...) from then on; config values `./config/*.php` computed
from those helpers keep the old path, so declare them in `config` too. The
config, bootstrap and `.env` paths are consumed before any provider runs and
have no key. `setLocale` dispatches `LocaleUpdated` before the application's
`EventServiceProvider` attaches its listeners.

Complete structure:

```yaml
app:
  bind:                                         # -> bind($abstract, $concrete)
    App\Contracts\Pdf: App\Services\DomPdf      # class-string or another bound abstract
    App\Contracts\Slugger: app/binders/slugger.php   # .php: the returned Closure, called ($app, $parameters)
  bindIf:                                       # -> bindIf(); skipped when bound, deferred services included
    App\Contracts\Cache: App\Services\RedisCache
  singleton:                                    # -> singleton()
    App\Services\TenantContext: ~               # ~ -> self-binding
  singletonIf:                                  # -> singletonIf()
    - App\Services\SlowWarmup                   # list: every item self-binds; no .php under *If
  scoped:                                       # -> scoped(); shared until forgetScopedInstances()
    - app/binders/request-log.php               # .php list item: bound under its Closure's return types
  scopedIf:                                     # -> scopedIf()
    App\Services\BudgetGuard: ~
  instance:                                     # -> instance($abstract, $instance)
    app.signature: "1.0"                        # literal, bound as YAML decoded it
    App\Contracts\Clock: App\Services\Clock     # class-string -> make()d eagerly
    app.rate_limiter: app/instances/limiter.php # .php: its return value, any type
  alias:                                        # -> alias($abstract, $alias); abstract first
    App\Services\TenantContext: context         # app('context') resolves the singleton
  extend:                                       # -> extend($abstract, Closure); receives $instance, $app
    cache.store: app/extensions/store-cache.php
  useAppPath: src                               # -> useAppPath(base_path('src')); rebinds `path`
  useDatabasePath: database                     # -> useDatabasePath()
  useLangPath: resources/lang                   # -> useLangPath(); applied before setLocale
  usePublicPath: public                         # -> usePublicPath()
  useStoragePath: /var/app/storage              # -> useStoragePath(); absolute, used as-is
  setLocale: fr                                 # -> setLocale(); dispatches LocaleUpdated
  setFallbackLocale: en                         # -> setFallbackLocale()
  registered:                                   # -> registered(); fires right after the block, receives $app
    - app/hooks/registered.php
  booting:                                      # -> booting(); first in boot()
    - App\Hooks\WarmConnections
  booted:                                       # -> booted(); last in boot()
    - App\Hooks\Metrics@warm
  terminating:                                  # -> terminating(); after the response, type-hints only
    - App\Hooks\FlushMetrics
```

The extender:

```php
// app/extensions/store-cache.php — the file IS the extender
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Application;

return static function (Repository $instance, Application $app): Repository {
    return $instance;   // $instance and $app match by name; anything else by type-hint
};
```

An unknown key (`singelton`) throws `LogicException` when the manifest is read,
and so does a `.php` list item under `bindIf`/`singletonIf`/`scopedIf`
(Laravel's `bound()` cannot take the file's Closure; use the map form). A `~`
list item, or a `.php` file that returns anything but a `Closure` where one is
required, throws `LogicException` at registration. Everything else passes
through as YAML decoded it and fails with Laravel's own exception at first
`make()`.

## Providers

`providers` entries declare a `class`: a
[`ServiceProvider`](https://laravel.com/docs/providers) class-string. At boot,
`LaravelDeclarationProvider` calls `$this->app->register()` with each one, so the
declared providers boot before `routes` register. A directory path or a
non-`ServiceProvider` class fails; see `tests/Feature/ManifestFactoryTest.php`.

Complete structure:

```yaml
providers:
  - class: App\Providers\AppServiceProvider   # ServiceProvider class-string
```

## Routes

`routes` entries map 1:1 onto
[`Illuminate\Routing\Route`](https://laravel.com/docs/routing) builders: every
key except the three reserved ones (`path`, `methods`, `action`, which feed
`Router::addRoute()`) is a `Route` method name, and its value is that method's
argument. `LaravelDeclarationProvider` uppercases `methods`, then applies the
rest in YAML document order — `prefix` before `domain` (`prefix()` resets
domain binding fields). See `tests/Feature/RouteRegistrationTest.php` and
[docs/declarative-routing.md](docs/declarative-routing.md).

`methods` is exactly one verb — `GET` (implies `HEAD`), `POST`, `PUT`, `PATCH`,
`DELETE`, `OPTIONS` or `HEAD`. `ANY` never matches. Sharing a path across verbs
means separate entries.

`action` accepts every YAML callable form: a `Class@method` string, an invokable
FQCN, or a `[Class, 'method']` array.

Complete structure:

```yaml
routes:
  - path: "users/{user}"
    methods: GET                         # one verb, uppercased by the provider
    action: [App\Http\Controllers\UserController, show]
    name: users.show                     # -> Route::name()
    prefix: api                          # -> Route::prefix() (applied before domain)
    domain: "{account}.example.com"      # -> Route::domain()
    middleware:                          # -> Route::middleware() (appends)
      - auth:sanctum
      - verified
    withoutMiddleware: [web]             # -> Route::withoutMiddleware()
    can:                                 # -> Route::can(ability: ..., models: ...)
      ability: view
      models: user                       # route parameter name or FQCN
    where:                               # -> Route::where()
      user: '[0-9]+'
    setDefaults:                         # -> Route::setDefaults()
      user: 1
    missing: App\Http\Handlers\UserMissingHandler   # -> Route::missing(); invokable class wrapped in a cache-safe Closure
    scopeBindings: true                  # -> Route::scopeBindings(); false skips the call
    withoutScopedBindings: false         # -> Route::withoutScopedBindings(); false skips the call
    withTrashed: true                    # -> Route::withTrashed()
    block:                               # -> Route::block(lockSeconds: ..., waitSeconds: ...)
      lockSeconds: 10
      waitSeconds: 5
    withoutBlocking: false               # -> Route::withoutBlocking(); false skips the call
    metadata:                            # -> Route::metadata()
      group: admin

  - path: "{any}"
    methods: GET
    action: App\Http\Controllers\FallbackController   # fallback routes require an action
    where:
      any: '.*'                          # mirrors Router::fallback(); without it {any} matches one segment
    fallback: true                       # -> Route::fallback()
```

`missing` handlers must be invokable; a non-invokable class throws
`LogicException` when invoked.

## Requests

`requests` entries map 1:1 onto
[`Illuminate\Foundation\Http\FormRequest`](https://laravel.com/docs/validation#form-request-validation)
members: every key except the reserved `name` is a `FormRequest` method or
property name, and its value is what that member returns (or holds). A route
opts in with `metadata: {request: <name>}`, and its action type-hints
`DeclaredRequest`, which Laravel resolves and validates like any
`FormRequest`. See `tests/Feature/DeclaredRequestTest.php` and
[docs/declarative-requests.md](docs/declarative-requests.md).

A string value is a PHP reference, run through `Container::call()` in the
member's place: an invokable FQCN, `Class@method`, `Class::method` (static) or a
namespaced function (load it via Composer `autoload.files`). There is no array
form. Write references plain or single-quoted; double quotes make `\` an escape.

A reference receives `$request` (the `DeclaredRequest`), plus `$validator`
(`withValidator`, `after`, `failedValidation`) or `$factory` (`validator`).
Parameters match by **name**: `DeclaredRequest $req` re-resolves the request
and recurses until PHP crashes.

In a field's rule list, an entry whose rule name (text before the first `:`)
contains `\` is a reference: a class is `make()`d as the rule, and any other
form is called and returns the rule. Everything else, including pipe strings,
passes to Laravel untouched.

Complete structure:

```yaml
requests:
  - name: user                                   # reserved: the handle routes reference
    authorize: App\Http\Gates\CreateUser          # -> authorize(); bool | reference; absent -> true
    rules:                                        # -> rules(); map | reference
      name: [required, string, max:255]           # Laravel rules, untouched
      nickname: nullable|string|max:32            # pipe string, untouched
      role: [required, 'exists:App\Models\Role,name']   # `\` after the `:` -> Laravel param
      slug: [required, App\Rules\Slug]            # rule class -> make()
      email: [required, 'App\Rules\UniqueTenantEmail::forRequest']   # called -> returns the rule
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
    failOnUnknownFields: true                     # -> shouldFailOnUnknownFields() ≙ #[FailOnUnknownFields]

routes:
  - path: users
    methods: POST
    action: [App\Http\Controllers\UserController, store]
    metadata:
      request: user                               # -> Route::metadata(['request' => 'user'])
```

The action:

```php
use ZeroToProd\LaravelDeclaration\DeclaredRequest;

public function store(DeclaredRequest $request): RedirectResponse
{
    $validated = $request->validated();   // runtime accessors unchanged
}
```

An action without a `DeclaredRequest` type-hint does not validate. A
`DeclaredRequest` on a route with no `metadata.request`, or one naming an
undeclared request, throws `LogicException`. `name` is not checked for
uniqueness; the last duplicate wins.

## License

MIT. See [LICENSE](LICENSE).
