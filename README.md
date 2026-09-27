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
`laravel-declaration.manifest` (default `manifest/app.yml`) and reads it in
`boot()`. A missing or invalid file registers nothing. Tests:
`tests/Feature/ManifestFactoryTest.php`, `tests/Feature/RouteRegistrationTest.php`.

The top-level key is `app`, holding `providers` and `routes`. Complete structure
per feature under [Providers](#providers) and [Routes](#routes).

## Providers

`app.providers` entries declare a `class`: a
[`ServiceProvider`](https://laravel.com/docs/providers) class-string. At boot,
`LaravelDeclarationProvider` calls `$this->app->register()` with each one, so the
declared providers boot before `app.routes` register. A directory path or a
non-`ServiceProvider` class fails; see `tests/Feature/ManifestFactoryTest.php`.

Complete structure:

```yaml
app:
  providers:
    - class: App\Providers\AppServiceProvider   # ServiceProvider class-string
```

## Routes

`app.routes` entries map 1:1 onto
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
app:
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

## License

MIT. See [LICENSE](LICENSE).
