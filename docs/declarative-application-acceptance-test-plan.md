# Declarative Application — Acceptance Test Plan (`src/App.php`)

**Subject under test:** `src/App.php` (`ZeroToProd\LaravelDeclaration\App`) — the `DataModel` hydrated from the manifest's `app:` key ([declarative-application.md](declarative-application.md) §2.5). Every property declares one `Application`/`Container` API call; a map is one call per entry, a list is one call per item.

**Source documentation:** [docs/repos/laravel/docs/](repos/laravel/docs/) — the vendored Laravel docs are the **system of record** for every behavior below (upstream equivalents in §8). This plan contains no behavior sourced from the framework code alone; where a test's mechanics need a source-derived supplement, it is flagged.

**Rule:** one **Given / When / Then** test per unique documented behavior. A test exists only where the vendored docs document the behavior; declared surface the docs do not cover is inventoried in §7 (gaps) with the nearest non-backing docs. Tests are not implemented here.

---

## 1. Coverage map

| `App` property | Documented behavior | Test |
|---|---|---|
| `bind` | interface → implementation injection; closure resolver with sub-dependencies; closure return-type inference | AT-01, AT-02, AT-03 |
| `bindIf` | binds only if the type has no binding yet | AT-04 |
| `singleton` | resolved one time; same instance afterwards | AT-05 |
| `singletonIf` | binds only if the type has no binding yet | AT-06 |
| `scoped` | resolved once per request/job lifecycle; flushed at a new lifecycle | AT-07 |
| `scopedIf` | binds only if the type has no binding yet | AT-08 |
| `instance` | given instance always returned on subsequent calls | AT-09 |
| `extend` | decorates resolved services; closure receives service + container | AT-10 |
| `tag` | tagged bindings resolved together via `tagged()` | AT-11 |
| `when` / `needs` / `give` | contextual: per-class implementations; primitives; typed variadics | AT-12, AT-13, AT-14 |
| `resolving` | fires on each resolution; object passed before its consumer | AT-15 |
| `setLocale` | runtime default language | AT-16 |
| `setFallbackLocale` | fallback language when the default lacks a string | AT-17 |
| `useAppPath` | `app_path()` contract | AT-18 |
| `useDatabasePath` | `database_path()` contract | AT-19 |
| `useLangPath` | `lang_path()` contract | AT-20 |
| `usePublicPath` | `public_path()` contract | AT-21 |
| `useStoragePath` | `storage_path()` contract | AT-22 |
| `registered` | application's registered behavior | AT-23 |
| `booting` | after all `register()` calls, just before `boot()` | AT-24 |
| declared bindings | available to every provider's `boot()` (register-then-boot) | AT-25 |
| `alias`, `booted`, `terminating`, `afterResolving`, `useBootstrapPath`, `useEnvironmentPath` | not documented in the vendored docs | §7 gaps |

---

## 2. Service container — bindings ([container.md](repos/laravel/docs/container.md))

### AT-01 — `bind` injects the declared implementation where the interface is type-hinted

**Doc says:** "bind(EventPusher::class, RedisEventPusher::class) … tells the container that it should inject the `RedisEventPusher` when a class needs an implementation of `EventPusher`. Now we can type-hint the `EventPusher` interface in the constructor of a class that is resolved by the container."

- **Given** the manifest declares `app.bind: {App\Contracts\EventPusher: App\Services\RedisEventPusher}` and a container-resolved class whose constructor type-hints `EventPusher`.
- **When** that class is resolved by the container.
- **Then** the injected `EventPusher` is a `RedisEventPusher`.

Sources: [container.md — Binding Interfaces to Implementations](repos/laravel/docs/container.md#binding-interfaces-to-implementations); [providers.md — The Register Method](repos/laravel/docs/providers.md#the-register-method) (bindings are registered from service providers).

### AT-02 — `bind` closure resolver receives the container and resolves sub-dependencies

**Doc says:** "register a binding using the `bind` method, passing the class or interface name … along with a closure that returns an instance of the class … we receive the container itself as an argument to the resolver. We can then use the container to resolve sub-dependencies of the object we are building."

- **Given** the manifest maps an abstract to a `.php` file returning `function (Application $app) { return new Transistor($app->make(PodcastParser::class)); }` under `app.bind`.
- **When** the abstract is resolved via `make()`.
- **Then** the resolver received the container, and the built object carries the container-built `PodcastParser` sub-dependency.

Sources: [container.md — Binding Basics / Simple Bindings](repos/laravel/docs/container.md#simple-bindings).

### AT-03 — `bind` infers the abstract from the closure's return type

**Doc says:** "you may omit providing the class or interface name that you wish to register as a separate argument and instead allow Laravel to infer the type from the return type of the closure you provide to the `bind` method."

- **Given** an `app.bind` list item is a `.php` file returning `function (Application $app): Transistor { … }` — no abstract is named.
- **When** `Transistor::class` is resolved.
- **Then** it resolves through the declared closure (the return type registered the binding).

Sources: [container.md — Binding Basics / Simple Bindings](repos/laravel/docs/container.md#simple-bindings).

### AT-04 — `bindIf` registers only when the type has no binding yet

**Doc says:** "You may use the `bindIf` method to register a container binding only if a binding has not already been registered for the given type."

- **Given** a binding for the abstract is already registered (e.g. by another provider) and `app.bindIf` declares a different concrete for the same abstract.
- **When** the abstract is resolved.
- **Then** the originally registered concrete is returned — the `bindIf` declaration did not overwrite it.

Sources: [container.md — Binding Basics / Simple Bindings](repos/laravel/docs/container.md#simple-bindings).

### AT-05 — `singleton` resolves one time and shares the instance

**Doc says:** "The `singleton` method binds a class or interface into the container that should only be resolved one time. Once a singleton binding is resolved, the same object instance will be returned on subsequent calls into the container."

- **Given** `app.singleton: {App\Services\TenantContext: App\Services\TenantContext}`.
- **When** the abstract is resolved twice.
- **Then** both resolutions return the identical instance (`===`).

Sources: [container.md — Binding A Singleton](repos/laravel/docs/container.md#binding-a-singleton).

### AT-06 — `singletonIf` registers only when the type has no binding yet

**Doc says:** "You may use the `singletonIf` method to register a singleton container binding only if a binding has not already been registered for the given type."

- **Given** a binding for the abstract already exists and `app.singletonIf` declares a different concrete for it.
- **When** the abstract is resolved.
- **Then** the original binding is untouched.

Sources: [container.md — Binding A Singleton](repos/laravel/docs/container.md#binding-a-singleton).

### AT-07 — `scoped` shares within one request/job lifecycle, then flushes

**Doc says:** "The `scoped` method binds a class or interface into the container that should only be resolved one time within a given Laravel request / job lifecycle … instances registered using the `scoped` method will be flushed whenever the Laravel application starts a new 'lifecycle', such as when a Laravel Octane worker processes a new request or when a Laravel queue worker processes a new job."

- **Given** `app.scoped: {App\Services\RequestLog: <concrete>}` resolved once in the current lifecycle.
- **When** the abstract is resolved again within the same lifecycle, and again after the application starts a new lifecycle (scoped instances flushed — simulated in tests with the flush mechanism Octane/queue workers invoke; see gap note in §7).
- **Then** the second same-lifecycle resolution returns the identical instance; the post-flush resolution returns a new instance.

Sources: [container.md — Binding Scoped Singletons](repos/laravel/docs/container.md#binding-scoped-singletons). *Supplement (source-derived):* the flush mechanism is `forgetScopedInstances()` — [declarative-application.md](declarative-application.md) §1.3.

### AT-08 — `scopedIf` registers only when the type has no binding yet

**Doc says:** "You may use the `scopedIf` method to register a scoped container binding only if a binding has not already been registered for the given type."

- **Given** a binding for the abstract already exists and `app.scopedIf` declares a different concrete for it.
- **When** the abstract is resolved.
- **Then** the original binding is untouched.

Sources: [container.md — Binding Scoped Singletons](repos/laravel/docs/container.md#binding-scoped-singletons).

### AT-09 — `instance` always returns the given instance

**Doc says:** "You may also bind an existing object instance into the container using the `instance` method. The given instance will always be returned on subsequent calls into the container."

- **Given** `app.instance: {App\Contracts\Clock: App\Services\Clock}` (the concrete is built and bound as an existing instance).
- **When** the abstract is resolved twice.
- **Then** both calls return the same instance that was bound.

Sources: [container.md — Binding Instances](repos/laravel/docs/container.md#binding-instances). *Supplement (source-derived):* a class-string value is `make()`d eagerly before binding — [declarative-application.md](declarative-application.md) §2.2.

---

## 3. Service container — extension, tagging, contextual bindings, events ([container.md](repos/laravel/docs/container.md))

### AT-10 — `extend` decorates the resolved service

**Doc says:** "The `extend` method allows the modification of resolved services … you may run additional code to decorate or configure the service. The `extend` method accepts two arguments, the service class you're extending and a closure that should return the modified service. The closure receives the service being resolved and the container instance."

- **Given** `app.extend: {cache.store: app/extensions/store-cache.php}` where the file returns `function ($service, $app) { return new DecoratedStore($service); }`.
- **When** the service is resolved by a consumer.
- **Then** the consumer receives the decorated instance, and the closure received the original service and the container.

Sources: [container.md — Extending Bindings](repos/laravel/docs/container.md#extending-bindings).

### AT-11 — `tag` groups bindings that `tagged()` resolves together

**Doc says:** "After registering the `Report` implementations, you can assign them a tag using the `tag` method: `$this->app->tag([CpuReport::class, MemoryReport::class], 'reports');` … Once the services have been tagged, you may easily resolve them all via the container's `tagged` method."

- **Given** `app.bind` registers the report implementations and `app.tag: {reports: [App\Reports\CpuReport, App\Reports\MemoryReport]}`.
- **When** `tagged('reports')` is resolved.
- **Then** it yields one instance of each tagged implementation.

Sources: [container.md — Tagging](repos/laravel/docs/container.md#tagging).

### AT-12 — contextual binding injects a different implementation into each class

**Doc says:** "Sometimes you may have two classes that utilize the same interface, but you wish to inject different implementations into each class … `$this->app->when(PhotoController::class)->needs(Filesystem::class)->give(…)`."

- **Given** `app.when` declares `PhotoController: {needs: Filesystem, give: <local-disk factory>}` and `UploadController: {needs: Filesystem, give: <s3-disk factory>}`.
- **When** each controller is resolved by the container.
- **Then** `PhotoController` receives the local-disk implementation and `UploadController` receives the s3-disk implementation for the same interface.

Sources: [container.md — Contextual Binding](repos/laravel/docs/container.md#contextual-binding).

### AT-13 — contextual binding injects a primitive

**Doc says:** "a class that receives some injected classes, but also needs an injected primitive value such as an integer. You may easily use contextual binding to inject any value your class may need: `->needs('$variableName')->give($value)`."

- **Given** `app.when: {App\Http\Controllers\UserController: {needs: '$userId', give: 7}}`.
- **When** `UserController` is resolved.
- **Then** its constructor primitive `$userId` is `7`.

Sources: [container.md — Binding Primitives](repos/laravel/docs/container.md#binding-primitives).

### AT-14 — contextual binding of typed variadics from an array of class names

**Doc says:** "For convenience, you may also just provide an array of class names to be resolved by the container whenever `Firewall` needs `Filter` instances: `->needs(Filter::class)->give([NullFilter::class, ProfanityFilter::class])`."

- **Given** `app.when: {App\Services\Firewall: {needs: Filter, give: [App\Services\NullFilter, App\Services\ProfanityFilter]}}` where `Firewall` declares `Filter ...$filters`.
- **When** `Firewall` is resolved.
- **Then** its variadic `$filters` holds one container-resolved instance per declared class name, in the declared order.

Sources: [container.md — Binding Typed Variadics](repos/laravel/docs/container.md#binding-typed-variadics).

### AT-15 — `resolving` fires per resolution with the object before its consumer

**Doc says:** "The service container fires an event each time it resolves an object. You may listen to this event using the `resolving` method … the object being resolved will be passed to the callback, allowing you to set any additional properties on the object before it is given to its consumer." (`resolving(Transistor::class, …)` for the type; `resolving(fn (mixed $object, …))` for any type.)

- **Given** `app.resolving: {App\Services\Transistor: <reference>}` whose callback records the object and sets a property on it.
- **When** `Transistor` is resolved twice.
- **Then** the callback runs for each resolution, receives the `Transistor` instance and the container, and the set property is visible to the consumer.

Sources: [container.md — Container Events](repos/laravel/docs/container.md#container-events). The documented *any-type* form has no key in `App::$resolving`'s shape — see §7 gap G-9.

---

## 4. Locale ([localization.md](repos/laravel/docs/localization.md))

### AT-16 — `setLocale` sets the runtime default language

**Doc says:** "The default language for your application is stored in the `config/app.php` configuration file's `locale` configuration option, which is typically set using the `APP_LOCALE` environment variable … You may modify the default language for a single HTTP request at runtime using the `setLocale` method provided by the `App` facade." and "You may use the `currentLocale` and `isLocale` methods on the `App` facade to determine the current locale."

- **Given** `app.setLocale: fr` and language files published for `fr`.
- **When** the application boots, then `currentLocale()` / `isLocale('fr')` are consulted and a translation string is retrieved.
- **Then** the current locale is `fr` and translation strings come from the `fr` language files.

Sources: [localization.md — Configuring the Locale](repos/laravel/docs/localization.md#configuring-the-locale); [localization.md — Determining the Current Locale](repos/laravel/docs/localization.md#determining-the-current-locale); [localization.md — Publishing the Language Files](repos/laravel/docs/localization.md#publishing-the-language-files) (`lang` directory).

### AT-17 — fallback language serves strings missing from the default language

**Doc says:** "You may also configure a 'fallback language', which will be used when the default language does not contain a given translation string. Like the default language, the fallback language is also configured in the `config/app.php` configuration file, and its value is typically set using the `APP_FALLBACK_LOCALE` environment variable."

- **Given** `app.setLocale: fr` and `app.setFallbackLocale: en`, with a translation key present in `en` but absent from `fr`.
- **When** that translation string is retrieved.
- **Then** the `en` string is returned (the fallback language is used).

Sources: [localization.md — Configuring the Locale](repos/laravel/docs/localization.md#configuring-the-locale). *Gap note:* the `setFallbackLocale` runtime method itself is not documented in the vendored docs — only the fallback-language concept and its `config/app.php` option are (§7 gap G-6).

---

## 5. Paths ([helpers.md](repos/laravel/docs/helpers.md), [structure.md](repos/laravel/docs/structure.md))

Common shape for AT-18 … AT-22: the declared `use*Path` moves the directory the documented helper points at. The helpers' contract is what the docs document; the `use*Path` setters themselves are not documented in the vendored docs (§7 gap G-5).

### AT-18 — `useAppPath` moves the `app` directory

**Doc says:** "The `app_path` function returns the fully qualified path to your application's `app` directory. You may also use the `app_path` function to generate a fully qualified path to a file relative to the application directory."

- **Given** `app.useAppPath: src`.
- **When** `app_path()` and `app_path('Models/User.php')` are called.
- **Then** the former returns the fully qualified path to the declared `src` directory and the latter resolves `Models/User.php` relative to it.

Sources: [helpers.md — `app_path()`](repos/laravel/docs/helpers.md#method-app-path); [structure.md — The App Directory](repos/laravel/docs/structure.md#the-root-app-directory).

### AT-19 — `useDatabasePath` moves the `database` directory

**Doc says:** "The `database_path` function returns the fully qualified path to your application's `database` directory. You may also use the `database_path` function to generate a fully qualified path to a given file within the database directory."

- **Given** `app.useDatabasePath: database`.
- **When** `database_path()` and `database_path('factories/UserFactory.php')` are called.
- **Then** both resolve under the declared directory.

Sources: [helpers.md — `database_path()`](repos/laravel/docs/helpers.md#method-database-path); [structure.md — The Database Directory](repos/laravel/docs/structure.md#the-database-directory).

### AT-20 — `useLangPath` moves the `lang` directory

**Doc says:** "The `lang_path` function returns the fully qualified path to your application's `lang` directory. You may also use the `lang_path` function to generate a fully qualified path to a given file within the directory."

- **Given** `app.useLangPath: resources/lang`.
- **When** `lang_path()` and `lang_path('en/messages.php')` are called.
- **Then** both resolve under the declared directory.

Sources: [helpers.md — `lang_path()`](repos/laravel/docs/helpers.md#method-lang-path); [localization.md — Publishing the Language Files](repos/laravel/docs/localization.md#publishing-the-language-files).

### AT-21 — `usePublicPath` moves the `public` directory

**Doc says:** "The `public_path` function returns the fully qualified path to your application's `public` directory. You may also use the `public_path` function to generate a fully qualified path to a given file within the public directory."

- **Given** `app.usePublicPath: public`.
- **When** `public_path()` and `public_path('css/app.css')` are called.
- **Then** both resolve under the declared directory.

Sources: [helpers.md — `public_path()`](repos/laravel/docs/helpers.md#method-public-path); [structure.md — The Public Directory](repos/laravel/docs/structure.md#the-public-directory).

### AT-22 — `useStoragePath` moves the `storage` directory

**Doc says:** "The `storage_path` function returns the fully qualified path to your application's `storage` directory. You may also use the `storage_path` function to generate a fully qualified path to a given file within the [storage] directory."

- **Given** `app.useStoragePath: storage`.
- **When** `storage_path()` and `storage_path('app/file.txt')` are called.
- **Then** both resolve under the declared directory.

Sources: [helpers.md — `storage_path()`](repos/laravel/docs/helpers.md#method-storage-path); [structure.md — The Storage Directory](repos/laravel/docs/structure.md#the-storage-directory).

---

## 6. Lifecycle hooks ([lifecycle.md](repos/laravel/docs/lifecycle.md), [providers.md](repos/laravel/docs/providers.md), [cache.md](repos/laravel/docs/cache.md), [http-client.md](repos/laravel/docs/http-client.md))

### AT-23 — `registered` applies the application's registered behavior

**Doc says:** "To customize or disable this behavior you may utilize the `truncateAt` and `dontTruncate` methods when configuring your application's registered behavior in your `bootstrap/app.php` file: `->registered(function (): void { RequestException::truncateAt(240); … })`."

- **Given** `app.registered: [<reference>]` whose callback configures framework behavior (`RequestException::truncateAt(240)`).
- **When** the application's provider registration phase completes.
- **Then** the callback has run and the configured behavior (the 240-character truncation) is in effect for the rest of the lifecycle.

Sources: [http-client.md — Error Handling / Throwing Exceptions](repos/laravel/docs/http-client.md#throwing-exceptions). *Gap note:* the vendored docs do not pin the callback's exact position within registration; the source-derived position (after every eager provider's `register()`, before `boot()`) is [declarative-application.md](declarative-application.md) §1.1 — verify, don't assert, any stricter ordering against the docs alone.

### AT-24 — `booting` runs after all `register()` calls and just before `boot()`

**Doc says:** "we will register our custom driver within a `booting` callback. By using the `booting` callback, we can ensure that the custom driver is registered just before the `boot` method is called on our application's service providers but after the `register` method is called on all of the service providers."

- **Given** `app.booting: [<reference>]` whose callback registers a custom driver (`Cache::extend('mongo', …)`), and a service provider's `boot()` that uses the custom driver.
- **When** the application boots.
- **Then** the custom driver registered by the `booting` callback is available when the provider's `boot()` runs.

Sources: [cache.md — Registering the Driver](repos/laravel/docs/cache.md#registering-the-driver).

### AT-25 — declared bindings are available to every provider's `boot()`

**Doc says:** "After instantiating the providers, the `register` method will be called on all of the providers. Then, once all of the providers have been registered, the `boot` method will be called on each provider. This is so service providers may depend on every container binding being registered and available by the time their `boot` method is executed." / "This method [`boot`] is called after all other service providers have been registered, meaning you have access to all other services that have been registered by the framework."

- **Given** the manifest declares a binding via `app.bind`, and a service provider's `boot()` resolves the abstract.
- **When** the application boots.
- **Then** the provider's `boot()` resolves the declared implementation (all bindings are registered before any `boot()` runs).

Sources: [lifecycle.md — Service Providers](repos/laravel/docs/lifecycle.md#service-providers); [providers.md — The Boot Method](repos/laravel/docs/providers.md#the-boot-method).

---

## 7. Documentation gaps — declared surface with no backing docs

No acceptance test can be written from `docs/repos/laravel/docs/` for the following; each lists the nearest non-backing documentation and, where one exists, the source-derived basis. These need either an upstream doc reference or a source-derived test (out of scope for this plan).

| # | Declared surface | Why no doc-backed test |
|---|---|---|
| G-1 | `alias` | The container's `alias()` is absent from [container.md](repos/laravel/docs/container.md); the only `alias` matches ([middleware.md — Registering Middleware](repos/laravel/docs/middleware.md#registering-middleware), [sanctum.md — Configuration](repos/laravel/docs/sanctum.md#configuration)) are `$middleware->alias()` — a different API. Source-derived basis: [declarative-application.md](declarative-application.md) §1.3 (`alias($abstract, $alias)`, abstract first, `LogicException` when identical). |
| G-2 | `booted` | The application's `booted()` hook is undocumented; the `booted` matches ([eloquent.md](repos/laravel/docs/eloquent.md#global-scopes), [billing.md](repos/laravel/docs/billing.md#quickstart)) are Eloquent model lifecycle methods. Boot-phase ordering is documented (§6 AT-25) but the hook is not. |
| G-3 | `terminating` | Not documented anywhere in the vendored docs; [lifecycle.md — Finishing Up](repos/laravel/docs/lifecycle.md#finishing-up) describes the response being sent without mentioning termination callbacks. |
| G-4 | `afterResolving` | Not documented; only `resolving` is ([container.md — Container Events](repos/laravel/docs/container.md#container-events)). |
| G-5 | `useBootstrapPath` | `bootstrap_path()` is absent from [helpers.md](repos/laravel/docs/helpers.md); [structure.md — The Bootstrap Directory](repos/laravel/docs/structure.md#the-bootstrap-directory) documents the directory's role, not the setter. Also consumed during bootstrap, before providers register ([declarative-application.md](declarative-application.md) §2.6). |
| G-6 | `useEnvironmentPath`, `setFallbackLocale` (method) | Environment files are documented at the project root ([configuration.md — Environment Configuration](repos/laravel/docs/configuration.md#environment-configuration)); the location setter is not. The fallback-language *concept* is documented (AT-17); the `setFallbackLocale` method is not. |
| G-7 | self-binding (`~` concrete, list-item abstract) | `bind($abstract)` / `singleton($abstract)` with an omitted concrete is not documented in [container.md](repos/laravel/docs/container.md) (only closure return-type inference is, AT-03). Source-derived basis: [declarative-application.md](declarative-application.md) §1.3. |
| G-8 | `bindIf`/`singletonIf`/`scopedIf` vs deferred services | The docs do not state that the conditional methods treat deferred services as already bound. Source-derived basis: [declarative-application.md](declarative-application.md) §1.1. |
| G-9 | `when([A, B])->needs()->give()`, `giveTagged`, `giveConfig`, global `resolving` | Behaviors documented in [container.md](repos/laravel/docs/container.md) (Contextual Binding, Binding Primitives, Container Events) but not expressible through `App::$when`'s `array{needs, give}` / `App::$resolving`'s abstract-keyed shape — a declaration-shape gap, not a doc gap. |
| G-10 | container/contextual attributes (`#[Bind]`, `#[Singleton]`, `#[Scoped]`, `#[BindWhen]`, `#[Give]`, `#[Tag]`, …) | Documented in [container.md](repos/laravel/docs/container.md) but they are class/parameter attributes, not manifest-declarable — outside `App.php`'s surface. |

---

## 8. Sources

Vendored docs (system of record, relative to `docs/repos/laravel/docs/`) with upstream equivalents:

1. [container.md](repos/laravel/docs/container.md) — https://laravel.com/docs/container — bindings (§2), extend/tag/contextual/events (§3).
2. [providers.md](repos/laravel/docs/providers.md) — https://laravel.com/docs/providers — register/boot phases (AT-01, AT-25).
3. [lifecycle.md](repos/laravel/docs/lifecycle.md) — https://laravel.com/docs/lifecycle — register-then-boot ordering (AT-25).
4. [cache.md](repos/laravel/docs/cache.md) — https://laravel.com/docs/cache — `booting` callback timing (AT-24).
5. [http-client.md](repos/laravel/docs/http-client.md) — https://laravel.com/docs/http-client — `registered` behavior (AT-23).
6. [localization.md](repos/laravel/docs/localization.md) — https://laravel.com/docs/localization — locale, fallback, `lang` directory (AT-16, AT-17, AT-20).
7. [helpers.md](repos/laravel/docs/helpers.md) — https://laravel.com/docs/helpers — path helper contracts (AT-18 … AT-22).
8. [structure.md](repos/laravel/docs/structure.md) — https://laravel.com/docs/structure — directory roles (AT-18 … AT-22).
9. [configuration.md](repos/laravel/docs/configuration.md) — https://laravel.com/docs/configuration — environment-file location (gap G-6).
10. [declarative-application.md](declarative-application.md) — in-repo design doc; source-derived supplements only, flagged per test (AT-07, AT-09, §7).