# Declarative Providers — Acceptance Test Plan (`src/Provider.php`)

**Subject under test:** [src/Provider.php](../src/Provider.php) (`ZeroToProd\LaravelDeclaration\Provider`) — the `DataModel` hydrated per entry of the manifest's `providers:` list ([Manifest.php](../src/Manifest.php) `$providers`, keyed by `class`). Its two properties are `class` (required — a `ServiceProvider` class-string) and `force` (default `false`). They are applied by [ProvidersDeclarationServiceProvider.php](../src/Providers/ProvidersDeclarationServiceProvider.php) in `boot()`: one `$this->app->register($Provider->class, $Provider->force)` call per entry, in declaration order.

**Source documentation:** [docs/repos/laravel/docs/providers.md](repos/laravel/docs/providers.md) — the vendored Laravel docs are the **system of record** for every behavior below (upstream equivalents in §6). Two corroborating docs are cited only where providers.md alone is insufficient: [lifecycle.md](repos/laravel/docs/lifecycle.md) § Service Providers documents the register-then-boot ordering that providers.md asserts from the outside, and [container.md](repos/laravel/docs/container.md) documents the binding semantics that the `$bindings`/`$singletons` properties register. This plan contains no behavior sourced from the framework code alone; where a gap's mechanics need a source-derived supplement, it is flagged.

**Rule:** one **Given / When / Then** test per unique documented behavior. A test exists only where the vendored docs document the behavior; declared surface the docs do not cover is inventoried in §5 (G-1), and documented behavior the declared surface cannot express is likewise inventoried in §5 (G-2, G-3). Documented guidance that is not observable behavior is noted in the coverage map, not tested. Tests are not implemented here.

---

## 1. Coverage map

| Declared surface / doc section | Documented behavior | Test |
|---|---|---|
| `class` | an array of service-provider class names registers those providers with the application | AT-01 |
| `class` | a provider's `register()` binds into the service container via `$app`; the binding resolves | AT-02 |
| `class` | the `$bindings` property is checked and registered automatically when the provider is loaded | AT-03 |
| `class` | the `$singletons` property is checked and registered automatically; one shared instance | AT-04 |
| `class` | `boot()` is called after all other providers are registered — every container binding is available | AT-05 |
| `class` | `boot()` registers application functionality (the doc's view-composer example) | AT-06 |
| `class` | `boot()` receives type-hinted dependencies (dependency injection) | AT-07 |
| `class` | "All service providers extend the `Illuminate\Support\ServiceProvider` class" | precondition — every fixture extends `ServiceProvider`; the declared surface registers a `ServiceProvider` class-string ([README.md](../README.md) § Providers, [manifest.schema.json](../manifest.schema.json) `definitions.provider.class`) |
| register-method convention | "within the `register` method, you should **only** bind things into the service container... never attempt to register any event listeners, routes" | documented guidance, not observable behavior — no test |
| `force` | not documented in the vendored docs | §5 G-1 |
| Deferred Providers | documented — registration deferred until a provided service is resolved | §5 G-2 (declared surface cannot express) |
| `make:provider` | documented — generates a provider and registers it in `bootstrap/providers.php` | §5 G-3 (no declared surface) |
| `bootstrap/providers.php` | the documented registration location; the manifest's `providers:` list is the declared equivalent | mapping context — [README.md](../README.md) § Providers (no separate behavior to test beyond AT-01) |

---

## 2. Registration ([providers.md — Registering Providers](repos/laravel/docs/providers.md#registering-providers))

### AT-01 — the declared class-string provider is registered with the application

**Doc says:** "All service providers are registered in the `bootstrap/providers.php` configuration file. This file returns an array that contains the class names of your application's service providers." The manifest's `providers:` list is the declared equivalent of that array of class names ([README.md](../README.md) § Providers, [manifest.schema.json](../manifest.schema.json) `definitions.provider`). What the docs pin as the observable consequence of a provider being loaded: its `register` method runs ([The Register Method](repos/laravel/docs/providers.md#the-register-method) — "uses that method to define an implementation ... in the service container") and its `boot` method runs ([The Boot Method](repos/laravel/docs/providers.md#the-boot-method)).

- **Given** the manifest declares `providers: [{class: <Provider>}]` — a fixture `ServiceProvider` whose `register()` and `boot()` each record into the test log.
- **When** the application boots.
- **Then** the declared provider was loaded by the framework: both its `register()` and its `boot()` ran during the boot sequence.

Sources: [providers.md — Registering Providers](repos/laravel/docs/providers.md#registering-providers); [providers.md — The Register Method](repos/laravel/docs/providers.md#the-register-method), [providers.md — The Boot Method](repos/laravel/docs/providers.md#the-boot-method) (register/boot are the documented load steps observed). (That the manifest list reaches `Application::register()` one call per entry is the in-repo mapping — [ProvidersDeclarationServiceProvider.php](../src/Providers/ProvidersDeclarationServiceProvider.php) — not a documented behavior.)

---

## 3. The register method ([providers.md — The Register Method](repos/laravel/docs/providers.md#the-register-method))

### AT-02 — the provider's `register()` binds into the service container via `$app` and the binding resolves

**Doc says:** "Within any of your service provider methods, you always have access to the `$app` property which provides access to the service container." The doc's example provider "only defines a `register` method, and uses that method to define an implementation of `App\Services\Riak\Connection` in the service container" via `$this->app->singleton(Connection::class, ...)`. [container.md — Simple Bindings](repos/laravel/docs/container.md#simple-bindings) corroborates: "Almost all of your service container bindings will be registered within service providers ... Within a service provider, you always have access to the container via the `$this->app` property."

- **Given** the manifest declares a provider whose `register()` defines an implementation of `Connection::class` in the container through `$this->app->singleton(...)` (the doc's Riak shape).
- **When** `Connection::class` is resolved through the container.
- **Then** the implementation the provider registered is returned.

Sources: [providers.md — The Register Method](repos/laravel/docs/providers.md#the-register-method); [providers.md — Introduction](repos/laravel/docs/providers.md#introduction) ("we mean **registering** things, including registering service container bindings"); [container.md — Simple Bindings](repos/laravel/docs/container.md#simple-bindings).

---

## 4. The `bindings` and `singletons` properties ([providers.md — The `bindings` and `singletons` Properties](repos/laravel/docs/providers.md#the-bindings-and-singletons-properties))

### AT-03 — the `$bindings` property is checked and registered automatically when the provider is loaded

**Doc says:** "If your service provider registers many simple bindings, you may wish to use the `bindings` and `singletons` properties instead of manually registering each container binding. When the service provider is loaded by the framework, it will automatically check for these properties and register their bindings." The doc's example: `public $bindings = [ServerProvider::class => DigitalOceanServerProvider::class]`.

- **Given** the manifest declares a provider with the doc's `$bindings` property (interface → implementation) and an empty `register()`.
- **When** `ServerProvider::class` is resolved where the interface is type-hinted.
- **Then** the declared implementation is injected — the property was checked and registered by the framework at load, with no explicit registration code.

Sources: [providers.md — The `bindings` and `singletons` Properties](repos/laravel/docs/providers.md#the-bindings-and-singletons-properties); [container.md — Binding Interfaces To Implementations](repos/laravel/docs/container.md#binding-interfaces-to-implementations) (the injection semantics).

### AT-04 — the `$singletons` property is checked and registered automatically, sharing one instance

**Doc says:** the same automatic check covers `singletons` — "it will automatically check for these properties and register their bindings." The doc's example: `public $singletons = [DowntimeNotifier::class => PingdomDowntimeNotifier::class]`.

- **Given** the manifest declares a provider with the doc's `$singletons` property and an empty `register()`.
- **When** `DowntimeNotifier::class` is resolved twice.
- **Then** both resolutions return the same shared instance — the property was registered as a singleton binding.

Sources: [providers.md — The `bindings` and `singletons` Properties](repos/laravel/docs/providers.md#the-bindings-and-singletons-properties); [container.md — Binding A Singleton](repos/laravel/docs/container.md#binding-a-singleton) (the shared-instance semantics of a singleton binding — corroborating source).

---

## 5. The boot method ([providers.md — The Boot Method](repos/laravel/docs/providers.md#the-boot-method))

### AT-05 — `boot()` is called after all other providers are registered, so every binding is available

**Doc says:** "**This method is called after all other service providers have been registered**, meaning you have access to all other services that have been registered by the framework." [lifecycle.md — Service Providers](repos/laravel/docs/lifecycle.md#service-providers) pins the ordering: "After instantiating the providers, the `register` method will be called on all of the providers. Then, once all of the providers have been registered, the `boot` method will be called on each provider. This is so service providers may depend on every container binding being registered and available by the time their `boot` method is executed."

- **Given** the manifest declares two providers in order: the first binds an abstract in `register()`; the second's `boot()` resolves that abstract (its own `register()` empty). Both record their phase into the test log.
- **When** the application boots.
- **Then** the second provider's `boot()` resolves the implementation the first provider registered — and the log shows every `register()` before any `boot()` (register A, register B, boot A, boot B).

Sources: [providers.md — The Boot Method](repos/laravel/docs/providers.md#the-boot-method); [lifecycle.md — Service Providers](repos/laravel/docs/lifecycle.md#service-providers) (the ordering).

*Cross-reference:* [declarative-application-acceptance-test-plan.md](declarative-application-acceptance-test-plan.md) AT-26 tests the same documented behavior with `app:`-declared bindings; this test declares the binding from a provider's `register()`, so the surface under test is `providers:` itself.

### AT-06 — `boot()` registers application functionality (the doc's view-composer example)

**Doc says:** "So, what if we need to register a view composer within our service provider? This should be done within the `boot` method." — the doc's `ComposerServiceProvider` registers `View::composer('view', ...)` in `boot()`. [The Introduction](repos/laravel/docs/providers.md#introduction) frames this as what bootstrapping means: "registering things, including registering service container bindings, event listeners, middleware, and even routes."

- **Given** the manifest declares a provider whose `boot()` registers a composer for a view (the doc's `ComposerServiceProvider` shape).
- **When** that view is rendered.
- **Then** the composer ran — the functionality registered in `boot()` is live in the application.

Sources: [providers.md — The Boot Method](repos/laravel/docs/providers.md#the-boot-method); [providers.md — Introduction](repos/laravel/docs/providers.md#introduction). (The composer mechanism itself is the view surface's domain — [declarative-view-acceptance-test-plan.md](declarative-view-acceptance-test-plan.md) AT-02; here it only evidences that `boot()` registrations take effect.)

### AT-07 — `boot()` receives type-hinted dependencies (dependency injection)

**Doc says:** "You may type-hint dependencies for your service provider's `boot` method. The service container will automatically inject any dependencies you need." The doc's example is `public function boot(ResponseFactory $response): void` registering a `serialized` macro on the injected factory.

- **Given** the manifest declares a provider whose `boot(ResponseFactory $response)` registers the doc's `serialized` macro on the injected factory.
- **When** the application boots and `response()->serialized($value)` is called.
- **Then** the macro runs — the type-hinted dependency was injected into `boot()` by the container.

Sources: [providers.md — Boot Method Dependency Injection](repos/laravel/docs/providers.md#boot-method-dependency-injection).

---

## 6. Documentation gaps — declared surface with no backing docs, and documented behavior with no declarable surface

No acceptance test can be written from `docs/repos/laravel/docs/` for the following; each lists the nearest non-backing documentation and, where one exists, the source-derived basis. These need either an upstream doc reference or a source-derived test (out of scope for this plan).

| # | Declared surface / documented behavior | Why no doc-backed test |
|---|---|---|
| G-1 | `force` | Re-registering an already-registered provider has zero matches in the vendored docs; [providers.md — Registering Providers](repos/laravel/docs/providers.md#registering-providers) documents the registration location only. Source-derived basis: `Application::register($provider, $force = false)` returns the already-registered provider untouched unless `$force` is set (framework source, `Illuminate\Foundation\Application`). In-repo mapping: [manifest.schema.json](../manifest.schema.json) `definitions.provider.force`, [src/Provider.php](../src/Provider.php) `force` (default `false`). |
| G-2 | Deferred Providers — documented behavior the declared surface cannot express | [providers.md — Deferred Providers](repos/laravel/docs/providers.md#deferred-providers) documents: deferred providers "will not be loaded on every request, but only when the services they provide are actually needed"; "Laravel compiles and stores a list of all of the services supplied by deferred service providers ... Then, only when you attempt to resolve one of these services does Laravel load the service provider" (via `\Illuminate\Contracts\Support\DeferrableProvider` + `provides()`). The declared surface registers each entry directly via `Application::register()`, which calls `$provider->register()` unconditionally; deferral only happens when providers are loaded through the compiled services manifest (the framework's `ProviderRepository`, which populates the application's deferred-services map — framework source). A `DeferrableProvider` declared under `providers:` is therefore loaded eagerly, and the doc's defer-until-resolution behavior is unobservable through this surface. |
| G-3 | `make:provider` — documented behavior with no declared surface | [providers.md — Writing Service Providers](repos/laravel/docs/providers.md#writing-service-providers) documents `php artisan make:provider RiakServiceProvider`: the Artisan CLI generates the provider class and "will automatically register your new provider in your application's `bootstrap/providers.php` file". The declared surface registers existing `ServiceProvider` class-strings; it generates no classes and writes no files. |

---

## 7. Sources

Vendored docs (**system of record**, relative to `docs/repos/laravel/docs/`) with upstream equivalents:

1. [providers.md](repos/laravel/docs/providers.md) — https://laravel.com/docs/providers — Introduction, Writing Service Providers, The Register Method (§3), The `bindings` and `singletons` Properties (§4), The Boot Method (§5), Boot Method Dependency Injection (§5), Registering Providers (§2), Deferred Providers (§6 G-2).
2. [lifecycle.md](repos/laravel/docs/lifecycle.md) — https://laravel.com/docs/lifecycle — Service Providers: instantiate → all `register()` → all `boot()` ordering (AT-05 only).
3. [container.md](repos/laravel/docs/container.md) — https://laravel.com/docs/container — Simple Bindings (AT-02 corroboration), Binding A Singleton (AT-04), Binding Interfaces To Implementations (AT-03).

In-repo subject/mapping context (no tested behavior sourced from these):

4. [README.md](../README.md) § Providers — the declared surface.
5. [manifest.schema.json](../manifest.schema.json) — `definitions.provider` (`class` required, `force` default).
6. [src/Provider.php](../src/Provider.php), [src/Providers/ProvidersDeclarationServiceProvider.php](../src/Providers/ProvidersDeclarationServiceProvider.php), [src/Manifest.php](../src/Manifest.php) — subject-under-test context only.
7. [declarative-application-acceptance-test-plan.md](declarative-application-acceptance-test-plan.md) — AT-26 cross-reference (§5 AT-05).
8. [declarative-view-acceptance-test-plan.md](declarative-view-acceptance-test-plan.md) — AT-02 cross-reference (§5 AT-06).