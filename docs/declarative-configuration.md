# Declarative Configuration — `Illuminate\Config\Repository` API & Manifest Schema

> Manifest forms in this document are the pre-engine block shapes; see docs/general-purpose-migration-plan.md §2.1 and README for the current forms.

Source of truth: `vendor/laravel/framework/src/Illuminate/Foundation/Bootstrap/LoadConfiguration.php`, `vendor/laravel/framework/src/Illuminate/Config/Repository.php`, `vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php` (`mergeConfigFrom()`), `vendor/laravel/framework/src/Illuminate/Foundation/Console/ConfigCacheCommand.php`, and `Illuminate/Foundation/helpers.php` (`config()`), `laravel/framework` v13.33.0.

Goal: a `config:` block in `manifest/app.yml` that **is the argument to `config([...])`**. Each `<file>.<key>` path in the YAML is a `config()` key: `config: {app: {name: X}}` is `config(['app.name' => 'X'])`. The provider applies it in `register()` with one `Config::set()` per file (§2.5). No new config file, no publish step.

---

## 1. Public API of the config repository

### 1.1 Load order (when the set must run)

`LoadConfiguration::bootstrap()` runs **before** any provider's `register()`:

```php
// LoadConfiguration::bootstrap(), abridged
$app->instance('config', $config = new Repository($items));   // $items: bootstrap/cache/config.php, if cached
if (! $loadedFromCache) {
    $this->loadConfigurationFiles($app, $config);              // ./config/**/*.php, merged over the framework's own config/*.php
}
$app->detectEnvironment(fn () => $config->get('app.env', 'production'));
date_default_timezone_set($config->get('app.timezone', 'UTC'));
```

`loadConfigurationFiles()` keys each file by its path under `config_path()` (`./config/app.php` → `app`, `./config/services/stripe.php` → `services.stripe`). It also merges the framework's defaults for the files Laravel ships (`app`, `auth`, `cache`, `database`, `logging`, `mail`, `queue`, ...): a file the host lacks still has its framework array.

Then every provider runs `register()` (all providers), and only afterwards `boot()` (all providers). A set performed in **`LaravelDeclarationProvider::register()`** is therefore visible to:

- every provider's `boot()`, whatever the provider order,
- the `register()` of providers that register after this one, including every declared provider (§1.3),
- everything runtime: controllers, `config()` / `Config::get()` calls.

It is **not** visible to:

- the `register()` of providers that registered earlier (framework `Illuminate\*` providers, then package providers in discovery order),
- values `LoadConfiguration` already consumed: `app.env` (`App::environment()`) and `app.timezone` (`date_default_timezone_get()`). The repository holds the YAML value; the effect keeps the file value.

`register()` is the Laravel convention for config: `mergeConfigFrom()` is documented for `register()`. The manifest path itself (`laravel-declaration.manifest`) is read there, so it must come from `config/laravel-declaration.php`, not from another provider.

### 1.2 Repository surface (the implementation needs one call)

| Call | Signature | Effect |
|---|---|---|
| `config($key)` / `Config::get($key)` | `($key, $default = null): mixed` | `Arr::get($items, $key)`; `config('app')` is the whole `app` array, `config('cache.stores.redis')` is nested |
| `config([$key => $value])` / `Config::set($key, $value)` | `(array\|string $key, $value = null): void` | `Arr::set($items, $key, $value)` per key; sets the node wholesale, no merge; siblings of the node survive |
| `ServiceProvider::mergeConfigFrom($path, $key)` | `(string $path, string $key): void` | `config()->set($key, array_merge(require $path, config($key, [])))`: the repository's existing values win over the package file. Skipped when the config is cached |

The manifest uses the second row: `Config::set()` with an array of dot-keys. Laravel's own contract applies unchanged: a key's value **replaces** that node whole; every other key under the file survives.

### 1.3 Provider ordering

Declared providers (`providers`) are registered from `boot()`, after the set, so they see the declared values in both `register()` and `boot()`.

---

## 2. Manifest schema proposal (`app.yml`)

### 2.1 Design rule

> **Every `<file>.<key>` path in the `config:` block is a `config()` key, set with `Config::set()`.** The first level is the config file's key (`app` → `./config/app.php`); the second level is a key inside it, and may itself be a dot-path (`stores.redis.connection`).

This is why the implementation is one `Config::set()` per file: the manifest is `config([...])` written in YAML.

### 2.2 Schema

```yaml
config:                  # the config() key space
  <file>:                # ./config/<file>.php — any key Laravel loaded, a package's key, or a new one
    <key>: <value>       # Config::set('<file>.<key>', <value>); <key> may be a dot-path
```

| YAML | Type | Maps to | Absent → |
|---|---|---|---|
| `config` | `map<file, map<key, mixed>>` | the `config([...])` argument | nothing set |
| `config.<file>` | **map**; a scalar throws `LogicException` in `register()` | prefix `<file>.` for its keys | — |
| `config.<file>.<key>` | any YAML value | `Config::set('<file>.<key>', <value>)` | the file's value |

A `<file>` no config file declares starts from nothing: the repository holds exactly the declared keys. `config('sentinel.meters')` on a key only the manifest declares works with no extra mechanism.

### 2.3 Full example

```yaml
config:
  app:                           # ./config/app.php
    name: Tenant Console         # config('app.name') -> 'Tenant Console'; every other app.* key survives
  cache:                         # ./config/cache.php
    default: redis               # config('cache.default') -> 'redis'; cache.stores survives
    stores.redis.connection: cache   # one nested key; the rest of stores.redis survives
  laravel-declaration:           # this package's own config — same mechanism, no special case
    mcp.enabled: false           # read in boot(), so it takes effect
  sentinel:                      # a key no file declares: the repository gains it
    meters: true

providers:
  - class: App\Providers\AppServiceProvider   # registers after the set: config('app.name') is 'Tenant Console'
```

### 2.4 Key → `config()` map

| YAML | Repository call the provider makes |
|---|---|
| `config.app.name: X` | `Config::set('app.name', 'X')` |
| `config.cache.stores.redis.connection: X` | `Config::set('cache.stores.redis.connection', 'X')` |
| `config.database.redis: {host: X}` | `Config::set('database.redis', ['host' => 'X'])` — the `redis` map is replaced whole |
| `config.<file>.<key>: V` | `Config::set('<file>.<key>', V)` |

A list under a file key is keyed by index, as `config(['app.0' => ...])` would be.

### 2.5 Registration algorithm (for the provider)

One `DataModel` property on `src/Manifest.php`, a plain array because values are user data:

```php
public const string config = 'config';

/** @var array<string, mixed> config file name => map of its config keys */
#[Describe([Describe::default => []])]
public array $config;
```

In `LaravelDeclarationProvider::register()`, after the package's own `mergeConfigFrom()`:

```php
$Manifest = $this->resolveManifest(Config::string('laravel-declaration.manifest', 'manifest/app.yml'));

$this->app->instance(Manifest::class, $Manifest);   // empty manifest when the file is missing

$this->registerConfig($Manifest);
```

```php
private function registerConfig(Manifest $Manifest): void
{
    foreach ($Manifest->config as $file => $values) {
        if (! is_array($values)) {
            throw new LogicException("The `config.{$file}` entry must be a map of config keys.");
        }

        Config::set(Arr::prependKeysWith($values, "{$file}."));
    }
}
```

`boot()` reads the bound manifest with `$this->app->make(Manifest::class)` for `registerProviders()` and `registerRoutes()`.

### 2.6 Notes / non-goals

- **`Config::set()` semantics, by design.** A key whose value is a map replaces that node whole; to change one nested key, declare its dot-path (`stores.redis.connection`). Rejected alternative: `array_replace_recursive()`. It merges lists by *numeric position*, so a declared `[RateLimiter::class]` would overwrite only index 0 of the file's list, which no Laravel API does.
- **`config:cache`.** `ConfigCacheCommand::getFreshConfiguration()` bootstraps a fresh application, providers included, and writes `config()->all()`. The declared values are therefore **in** `bootstrap/cache/config.php`. At runtime the set runs again on top of the cached repository, which changes nothing. Treat `config` like `./config`: re-run `config:cache` after editing it, or a removed key keeps its cached value.
- **Bootstrap-consumed keys.** `app.env` and `app.timezone` are applied by `LoadConfiguration` before any provider (§1.1). Declaring them changes `config()`, not the environment or the PHP timezone. The same holds for `providers` and `laravel-declaration.manifest`, which are read before the set runs.
- **`.env` interplay.** `.env` values reach the repository through `env()` calls in `./config/*.php`, before providers register, so a declared key wins over them in `config()`. `env('APP_NAME')` called directly still returns the raw `.env` value; read `config()`, never `env()`, in application code.
- **No publish, no new file.** The published `config/laravel-declaration.php` keeps only `manifest` and `mcp.*`; an absent `config` block is `[]`.
- **Not a validation layer.** Values pass through as YAML decoded them: `true`/`false`/`null`/integers arrive typed; nothing re-evaluates strings. Declare literals, not `env(...)` expressions.
- **Scalar under a file key.** `config: {app: "foo"}` hydrates, then `register()` throws `LogicException: The \`config.app\` entry must be a map of config keys.` A scalar `config:` block fails hydration with a `TypeError`.
