# Declarative Config — Acceptance Test Plan (manifest `config:` → `Illuminate\Config\Repository`)

**Subject under test:** the `DataModel` property hydrated from the manifest's `config:` key ([Manifest.php](../src/Manifest.php) `$config` — `map<file, map<key, mixed>>`), applied by [ConfigDeclarationServiceProvider.php](../src/Providers/ConfigDeclarationServiceProvider.php) in `register()` as one `Config::set(Arr::prependKeysWith($values, "$file."))` per file — i.e. the manifest block **is** the argument to `config([...])` against the `Illuminate\Config\Repository` ([declarative-configuration.md](declarative-configuration.md) §1.2, §2.1).

**Source documentation:** [docs/repos/laravel/docs/configuration.md](repos/laravel/docs/configuration.md) (§ Accessing Configuration Values, § Configuration Caching, § Environment Configuration), [packages.md](repos/laravel/docs/packages.md) (§ Default Package Configuration), [lifecycle.md](repos/laravel/docs/lifecycle.md) / [providers.md](repos/laravel/docs/providers.md) (register/boot ordering) — the vendored Laravel docs are the **system of record** for every behavior below (upstream equivalents in §6). The declared surface is README [§ Config](../README.md#config) + [declarative-configuration.md](declarative-configuration.md); that design doc is framework-verified and is cited only for source-derived supplements, flagged per test — never as the sole source of a tested behavior.

**Rule:** one **Given / When / Then** test per unique documented behavior. A test exists only where the vendored docs (or the README's declared-surface statements) document the behavior; declared surface with no doc backing is inventoried in §5 (gaps). Tests are not implemented here.

---

## 1. Coverage map

| `config:` behavior | Documented in | Test |
|---|---|---|
| dot-syntax access of a declared value (`app.name` → `config('app.name')`) | configuration.md | AT-01 |
| default returned when the option does not exist | configuration.md | AT-02 |
| typed retrieval (`Config::string()` …) of declared values; mismatch throws | configuration.md | AT-03 |
| manifest values win over existing values (merge, manifest wins) | README §Config, configuration.md, packages.md | AT-04 |
| siblings of a declared key survive | README §Config | AT-05 |
| a key that is itself a dot-path sets one nested node; the rest of the node survives | README §Config, configuration.md | AT-06 |
| a file key no config file declares is gained whole | README §Config | AT-07 |
| a map value replaces the node wholesale (no recursive merge) | declarative-configuration.md (source-derived) | AT-08 |
| declared values visible to every provider's `boot()` | lifecycle.md, providers.md, packages.md | AT-09 |
| declared values visible to providers registered after the set | lifecycle.md + declarative-configuration.md (source-derived) | AT-10 |
| bootstrap-consumed keys (`app.env`, `app.timezone`) change `config()` only | configuration.md + declarative-configuration.md (source-derived) | AT-11 |
| declared key wins over `.env`-sourced value in `config()`; direct `env()` still raw | configuration.md | AT-12 |
| `config:cache` combines all options — declared values land in the cache | configuration.md, packages.md | AT-13 |
| stale cache keeps removed keys until re-cache; `config:clear` purges | configuration.md | AT-14 |
| YAML literals pass through typed (`true`/`null`/ints), never re-evaluated as `env()` strings | declarative-configuration.md (source-derived), configuration.md | AT-15 |
| scalar under a file key; scalar `config:` block; list under a file key; invisible to earlier registers' `register()` | not documented in the vendored docs | §5 gaps |

---

## 2. Accessing declared values ([configuration.md — Accessing Configuration Values](repos/laravel/docs/configuration.md#accessing-configuration-values))

### AT-01 — a declared value is read via "dot" syntax from anywhere

**Doc says:** "You may easily access your configuration values using the `Config` facade or global `config` function from anywhere in your application. The configuration values may be accessed using 'dot' syntax, which includes the name of the file and option you wish to access."

- **Given** the manifest declares `config: {app: {name: Tenant Console}}`.
- **When** `config('app.name')` — and equivalently `Config::get('app.name')` — is called from a controller/route.
- **Then** both return `'Tenant Console'` (first YAML level = file key, second = the option).

Sources: [configuration.md — Accessing Configuration Values](repos/laravel/docs/configuration.md#accessing-configuration-values); [README — Config](../README.md#config) (`config: app.name` → `config('app.name')`).

### AT-02 — a default is returned when the configuration option does not exist

**Doc says:** "A default value may also be specified and will be returned if the configuration option does not exist."

- **Given** the manifest declares `config: {app: {name: Tenant Console}}` and no `app.nickname` key exists in any config file or the manifest.
- **When** `config('app.nickname', 'Fallback')` is called.
- **Then** `'Fallback'` is returned.

Sources: [configuration.md — Accessing Configuration Values](repos/laravel/docs/configuration.md#accessing-configuration-values).

### AT-03 — declared values support typed retrieval and throw on type mismatch

**Doc says:** "To assist with static analysis, the `Config` facade also provides typed configuration retrieval methods. If the retrieved configuration value does not match the expected type, an exception will be thrown."

- **Given** the manifest declares `config: {app: {name: Tenant Console}}`.
- **When** `Config::string('app.name')` is called.
- **Then** it returns `'Tenant Console'`; and when `Config::integer('app.name')` is called against the same string value, an exception is thrown.

Sources: [configuration.md — Accessing Configuration Values](repos/laravel/docs/configuration.md#accessing-configuration-values).

---

## 3. Precedence & merge semantics (README [§ Config](../README.md#config), [configuration.md](repos/laravel/docs/configuration.md), [packages.md](repos/laravel/docs/packages.md))

### AT-04 — manifest values win over existing configuration values

**Doc says:** README: "Your existing configurations are merged. The `manifest` values win over existing values." configuration.md documents `Config::set('app.timezone', 'America/Chicago')` / `config(['app.timezone' => 'America/Chicago'])` as setting values at runtime — the mechanism the manifest block maps onto.

- **Given** `./config/app.php` (or the `.env` value it reads) provides `app.name = 'Legacy'`, and the manifest declares `config: {app: {name: Tenant Console}}`.
- **When** the application boots and `config('app.name')` is called.
- **Then** `'Tenant Console'` is returned — the manifest value replaced the existing one.

Sources: [README — Config](../README.md#config); [configuration.md — Accessing Configuration Values](repos/laravel/docs/configuration.md#accessing-configuration-values). *Note:* [packages.md — Default Package Configuration](repos/laravel/docs/packages.md#default-package-configuration) documents the opposite precedence for `mergeConfigFrom` (the repository's existing values win over the package file) — the manifest set is not a `mergeConfigFrom`, which is why the manifest can win at all.

### AT-05 — siblings of a declared key survive

**Doc says:** README: "`name: Tenant Console  # config('app.name'); other app.* keys survive`".

- **Given** `./config/app.php` declares `timezone => 'UTC'` and the manifest declares only `config: {app: {name: Tenant Console}}`.
- **When** `config('app.timezone')` and `config('app.name')` are called after boot.
- **Then** `config('app.timezone')` is still `'UTC'` while `config('app.name')` is `'Tenant Console'`. (*Source-derived supplement:* `config('app')` returns the whole `app` array including both keys — [declarative-configuration.md](declarative-configuration.md) §1.2; the vendored docs document only the dot-syntax option access.)

Sources: [README — Config](../README.md#config); [configuration.md — Accessing Configuration Values](repos/laravel/docs/configuration.md#accessing-configuration-values). *Supplement (source-derived):* the set addresses one node and leaves the rest of the file's map intact — [declarative-configuration.md](declarative-configuration.md) §1.2 (`Config::set` per file, `Arr::set` semantics).

### AT-06 — a key that is itself a dot-path sets one nested node; the rest of the node survives

**Doc says:** README: "`stores.redis.connection: cache  # one nested key; the rest of stores.redis survives`".

- **Given** `./config/cache.php` declares `stores.redis` with `driver`, `connection`, and `host` keys, and the manifest declares `config: {cache: {stores.redis.connection: cache}}`.
- **When** `config('cache.stores.redis.connection')`, `config('cache.stores.redis.driver')`, and `config('cache.stores.redis.host')` are called.
- **Then** `connection` is `'cache'` while `driver` and `host` keep their file values.

Sources: [README — Config](../README.md#config); [configuration.md — Accessing Configuration Values](repos/laravel/docs/configuration.md#accessing-configuration-values) (dot syntax composes file + option names); [declarative-configuration.md](declarative-configuration.md) §2.4 (`Config::set('cache.stores.redis.connection', X)`).

### AT-07 — a file key no config file declares is gained whole

**Doc says:** README: "`sentinel:  # a key no file declares: gained whole` / `meters: true`"; declarative-configuration.md: "A `<file>` no config file declares starts from nothing: the repository holds exactly the declared keys."

- **Given** no `./config/sentinel.php` exists and the manifest declares `config: {sentinel: {meters: true}}`.
- **When** `config('sentinel.meters')` is called.
- **Then** `true` is returned, with no extra registration mechanism.

Sources: [README — Config](../README.md#config); [configuration.md — Accessing Configuration Values](repos/laravel/docs/configuration.md#accessing-configuration-values) (dot-syntax get/set against the repository). *Supplement (source-derived):* the repository accepts keys beyond the loaded files — [declarative-configuration.md](declarative-configuration.md) §2.2.

### AT-08 — a map value replaces the node wholesale (no recursive merge)

**Doc says:** declarative-configuration.md: "`Config::set()` semantics, by design. A key whose value is a map replaces that node whole; to change one nested key, declare its dot-path" — the rejected alternative was recursive merging. The README's "manifest values win" backs the win, not the wholesale-replace; this test pins the documented design decision.

- **Given** `./config/database.php` declares `redis` with `host` and `port` keys, and the manifest declares `config: {database: {redis: {host: declared-host}}}`.
- **When** `config('database.redis')` is called after boot.
- **Then** it is exactly `['host' => 'declared-host']` — `port` is gone (declare `database.redis.port` separately to keep it).

Sources: [declarative-configuration.md](declarative-configuration.md) §2.4, §2.6 (source-derived); [README — Config](../README.md#config) (manifest wins).

---

## 4. Lifecycle timing, environment & caching interplay

### AT-09 — declared values are visible to every provider's `boot()`

**Doc says:** lifecycle.md: "After instantiating the providers, the `register` method will be called on all of the providers. Then, once all of the providers have been registered, the `boot` method will be called on each provider. This is so service providers may depend on every container binding being registered and available by the time their `boot` method is executed." packages.md places config merging "within your service provider's `register` method" — the phase the manifest set runs in ([declarative-configuration.md](declarative-configuration.md) §1.1, §2.5).

- **Given** the manifest declares `config: {app: {name: Tenant Console}}` and a service provider's `boot()` reads `config('app.name')`.
- **When** the application boots.
- **Then** the provider's `boot()` sees `'Tenant Console'`, whatever the provider order.

Sources: [lifecycle.md — Service Providers](repos/laravel/docs/lifecycle.md#service-providers); [providers.md — The Boot Method](repos/laravel/docs/providers.md#the-boot-method) ("called after all other service providers have been registered"); [packages.md — Default Package Configuration](repos/laravel/docs/packages.md#default-package-configuration); [declarative-configuration.md](declarative-configuration.md) §1.1.

### AT-10 — declared values are visible to providers registered after the set

**Doc says:** lifecycle.md documents the register-all-then-boot-all order; that a `register()` sees values set by an earlier provider's `register()` is not stated in the vendored docs. declarative-configuration.md §1.1/§1.3: the set runs in the declaration provider's `register()`, so it is visible to "the `register()` of providers that register after this one, including every declared provider".

- **Given** the manifest declares `config: {app: {name: Tenant Console}}` and a *declared* provider (`providers:`) whose `register()` reads `config('app.name')`.
- **When** the application boots and that provider registers.
- **Then** its `register()` sees `'Tenant Console'`.

Sources: [lifecycle.md — Service Providers](repos/laravel/docs/lifecycle.md#service-providers). *Supplement (source-derived):* the set's position in the register phase and declared providers registering from `boot()` — [declarative-configuration.md](declarative-configuration.md) §1.1, §1.3.

### AT-11 — bootstrap-consumed keys change `config()` but not the environment or timezone

**Doc says:** configuration.md: "The current application environment is determined via the `APP_ENV` variable from your `.env` file" and "The current application environment detection can be overridden by defining a server-level `APP_ENV` environment variable." declarative-configuration.md §1.1/§2.6 (source-derived): `LoadConfiguration` consumes `app.env` and `app.timezone` **before** any provider registers, so declaring them changes the repository value, not `App::environment()` or the PHP timezone.

- **Given** the `.env` sets `APP_ENV=production` and the manifest declares `config: {app: {env: staging, timezone: Antarctica/Troll}}`.
- **When** `App::environment()`, `date_default_timezone_get()`, and `config('app.env')` / `config('app.timezone')` are read.
- **Then** the environment is still `production` and the timezone is unchanged, while `config('app.env')` is `'staging'` and `config('app.timezone')` is `'Antarctica/Troll'` (the repository holds the YAML values; the effects keep the file values).

Sources: [configuration.md — Determining the Current Environment](repos/laravel/docs/configuration.md#determining-the-current-environment); [declarative-configuration.md](declarative-configuration.md) §1.1, §2.6 (source-derived).

### AT-12 — a declared key wins over the `.env`-sourced value in `config()`; direct `env()` still returns raw

**Doc says:** configuration.md: `.env` values are "read by the configuration files within the `config` directory using Laravel's `env` function"; README: manifest values win over existing values. declarative-configuration.md §2.6 (source-derived): "`env('APP_NAME')` called directly still returns the raw `.env` value; read `config()`, never `env()`".

- **Given** `.env` sets `APP_NAME=Legacy`, `./config/app.php` uses `'name' => env('APP_NAME')`, and the manifest declares `config: {app: {name: Tenant Console}}`.
- **When** `config('app.name')` and `env('APP_NAME')` are called.
- **Then** `config('app.name')` is `'Tenant Console'` while `env('APP_NAME')` is still `'Legacy'`.

Sources: [configuration.md — Retrieving Environment Configuration](repos/laravel/docs/configuration.md#retrieving-environment-configuration); [README — Config](../README.md#config); [declarative-configuration.md](declarative-configuration.md) §2.6 (source-derived, direct-`env()` half).

### AT-13 — `config:cache` combines all configuration options, including the declared values

**Doc says:** configuration.md: "you should cache all of your configuration files into a single file using the `config:cache` Artisan command. This will combine all of the configuration options for your application into a single file which can be quickly loaded by the framework." declarative-configuration.md §2.6 (source-derived): `ConfigCacheCommand` bootstraps a fresh application with providers included, so the declared values are written into `bootstrap/cache/config.php`.

- **Given** the manifest declares `config: {app: {name: Tenant Console}}` and `php artisan config:cache` runs.
- **When** a request or Artisan command calls `config('app.name')` with the cached configuration loaded.
- **Then** `'Tenant Console'` is served from the cache — no per-request re-set is required.

Sources: [configuration.md — Configuration Caching](repos/laravel/docs/configuration.md#configuration-caching); [declarative-configuration.md](declarative-configuration.md) §2.6 (source-derived). *Note:* packages.md warns against closures in configuration files because of `config:cache` serialization — the manifest is YAML and cannot declare closures, so the hazard is out of scope by construction.

### AT-14 — a stale cache keeps removed keys until re-cache; `config:clear` purges it

**Doc says:** configuration.md: the cached file is what is loaded ("combine all of the configuration options … into a single file"), "You should typically run the `php artisan config:cache` command as part of your production deployment process", and "The `config:clear` command may be used to purge the cached configuration". declarative-configuration.md §2.6: "Treat `config` like `./config`: re-run `config:cache` after editing it, or a removed key keeps its cached value."

- **Given** a cached configuration that still contains `app.name = 'Tenant Console'` from an earlier manifest, and the manifest no longer declares `config.app.name`.
- **When** the application boots with the cache, then `php artisan config:clear` runs and the application boots again.
- **Then** with the cache the removed key still resolves (`'Tenant Console'`), and after `config:clear` the repository reflects the current manifest (the key is absent, `config('app.name')` returns the file/`env` value or `null`).

Sources: [configuration.md — Configuration Caching](repos/laravel/docs/configuration.md#configuration-caching); [declarative-configuration.md](declarative-configuration.md) §2.6 (source-derived, the stale-key half).

### AT-15 — YAML literals pass through typed and are never re-evaluated as `env()` strings

**Doc says:** declarative-configuration.md §2.6: "Values pass through as YAML decoded them: `true`/`false`/`null`/integers arrive typed; nothing re-evaluates strings. Declare literals, not `env(...)` expressions." configuration.md documents that `env()` applies reserved-value parsing (`true` → bool, `null` → null) — parsing the manifest deliberately does not perform: values are set into the repository as decoded, bypassing any `env()` evaluation.

- **Given** the manifest declares `config: {app: {debug: true, retries: 3, vendor: "null"}}`.
- **When** `config('app.debug')`, `config('app.retries')`, and `config('app.vendor')` are called.
- **Then** they return `(bool) true`, `(int) 3`, and the string `'null'` respectively — no `env()`-style reserved-value conversion is applied to the declared literal.

Sources: [declarative-configuration.md](declarative-configuration.md) §2.6 (source-derived); [configuration.md — Environment Variable Types](repos/laravel/docs/configuration.md#environment-variable-types) (the `env()` parsing the manifest path does not go through).

---

## 5. Documentation gaps — declared surface with no backing docs

No acceptance test can be written from `docs/repos/laravel/docs/` for the following; each lists the nearest non-backing documentation and the source-derived basis.

| # | Declared surface | Why no doc-backed test |
|---|---|---|
| G-1 | scalar under a file key (`config: {app: "foo"}`) | The vendored docs never describe the manifest's validation of its own block; [configuration.md](repos/laravel/docs/configuration.md) covers repository access, not declaration shape. Source-derived basis: [declarative-configuration.md](declarative-configuration.md) §2.2, §2.6 — `register()` throws `LogicException: The \`config.app\` entry must be a map of config keys.` ([ConfigDeclarationServiceProvider.php](../src/Providers/ConfigDeclarationServiceProvider.php)). |
| G-2 | scalar `config:` block | Same absence; hydration of a scalar into `array<string, mixed>` fails with a `TypeError` before any provider runs. Source-derived basis: [declarative-configuration.md](declarative-configuration.md) §2.6. |
| G-3 | list under a file key (`config: {view: [resources/theme]}`) | The docs do not discuss list values under a file key; the index-keying (`config(['view.0' => ...])`-equivalent) is implementation mechanics. Source-derived basis: [declarative-configuration.md](declarative-configuration.md) §2.4. |
| G-4 | not visible to `register()` of providers that registered earlier | Register-phase ordering *between* providers (framework `Illuminate\*` and package providers first) is not pinned by the vendored docs — lifecycle.md only pins register-all-then-boot-all. Source-derived basis: [declarative-configuration.md](declarative-configuration.md) §1.1. |
| G-5 | `config:show` / `about --only` reflection of declared values | Documented in [configuration.md — The `about` Command](repos/laravel/docs/configuration.md#the-about-command) as framework diagnostics, but they are commands, not part of the `config:` block's declared behavior — out of the manifest surface. |

---

## 6. Sources

Vendored docs (system of record, relative to `docs/repos/laravel/docs/`) with upstream equivalents:

1. [configuration.md](repos/laravel/docs/configuration.md) — https://laravel.com/docs/configuration — dot-syntax access, defaults, typed getters, runtime set (AT-01…AT-03, AT-04, AT-05, AT-06, AT-07), `APP_ENV` detection (AT-11), `env()` in config files (AT-12), `config:cache`/`config:clear` (AT-13, AT-14), env variable types (AT-15), `config:show`/`about` (G-5).
2. [packages.md](repos/laravel/docs/packages.md) — https://laravel.com/docs/packages — `mergeConfigFrom` in `register()` (AT-09), config-file publication/closure warning (AT-13 note).
3. [lifecycle.md](repos/laravel/docs/lifecycle.md) — https://laravel.com/docs/lifecycle — register-all-then-boot-all ordering (AT-09, AT-10).
4. [providers.md](repos/laravel/docs/providers.md) — https://laravel.com/docs/providers — `boot()` after all registrations (AT-09).
5. [README.md](../README.md) (§ Config) — in-repo feature spec — merge precedence, sibling survival, dot-path keys, undeclared file keys (AT-04…AT-07, AT-12).
6. [declarative-configuration.md](declarative-configuration.md) — in-repo design doc, framework-verified — source-derived supplements only, flagged per test (AT-05, AT-07, AT-08, AT-10, AT-11, AT-13, AT-14, AT-15; G-1…G-4).