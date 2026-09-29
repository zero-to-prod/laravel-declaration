# Laravel Declaration

A declarative plugin for Laravel.

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
composer check   # lint, rector, phpstan, 100% coverage, bc-check — mutates nothing
composer fix     # rector then pint
composer mcp list                      # the server's tools
composer mcp call api '{}'             # call one
```

## Manifest

Your application can be defined by a single file called a `manifest`. 

The default location for this file is `./manifest/app.yml`.

## Config

You can define your applications configuration in the `config` object.

Your existing configurations are merged. The `manifest` values win over existing values.

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

Define your application in the `app` object.

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

Extend functionality:

```php
// app/extensions/store-cache.php — the file IS the extender
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Application;

return static function (Repository $instance, Application $app): Repository {
    return $instance;   // $instance and $app match by name; anything else by type-hint
};
```

## Router

Define your application's global routes in the `router` object.

Complete structure:

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

## View

Define your application's view composers and factories in the `view` object.

Complete structure:

```yaml
view:
  addLocation: [resources/declared-views]      # -> addLocation($location), one call per item
  prependLocation: [resources/theme]           # searched before config('view.paths')
  addNamespace:                                # -> addNamespace($namespace, $hints)
    admin: resources/admin-views               # view('admin::dashboard')
  prependNamespace:
    courier: [resources/overrides/courier]     # overrides a package's views
  replaceNamespace:
    legacy: resources/legacy-views
  addExtension:                                # -> addExtension($extension, $engine)
    html: blade
  share:                                       # -> share($key): every view gets $brand
    brand: Tenant Console
  composer:                                    # -> composer($views, $callback), keyed as Factory::composers()
    App\View\Composers\UserMenu: users.*       # make(UserMenu)->compose($view)
    App\View\Composers\CurrentTenant: '*'      # every view; quote `*`
    App\View\Composers\Nav@primary: [layouts.app, layouts.admin]
  creator:                                     # -> creator($views, $callback), default method `create`
    App\View\Creators\Breadcrumbs: users.show
```

## Providers

Define your applications providers.

Complete structure:

```yaml
providers:
  - class: App\Providers\AppServiceProvider   # ServiceProvider class-string
```

## Routes

Define your applications routes.

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

## Requests

Define your applications requests.

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

## Models

Define your application's Eloquent models in the `models` list. Each class
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
    getRouteKeyName: code                    # -> getRouteKeyName() ≙ #[RouteKey]; router.model binds by it
```

The class:

```php
use ZeroToProd\LaravelDeclaration\DeclaredModel;

final class Flight extends DeclaredModel
{
    public function airline(): BelongsTo { return $this->belongsTo(Airline::class); }
}
```

## License

MIT. See [LICENSE](LICENSE).
