# Declarative View — Acceptance Test Plan (`src/View.php`)

**Subject under test:** `src/View.php` (`ZeroToProd\LaravelDeclaration\View`) — the `DataModel` hydrated from the manifest's `view:` key ([Manifest.php](../src/Manifest.php) `?View $view`). It is applied by [ViewDeclarationServiceProvider.php](../src/Providers/ViewDeclarationServiceProvider.php) as identically-named `Illuminate\View\Factory` calls: a list property is one call per item, a map property is one call per entry — `addNamespace`/`addExtension` pass key and value as arguments, `composer`/`creator` pass the key as the first argument (a comma-separated key becomes the doc's array of views) and the value as the callback — `share` is one bulk array call, and the boolean `flushFinderCache`/`flushState` keys are no-argument calls made when `true`.

**Source documentation:** [docs/repos/laravel/docs/views.md](repos/laravel/docs/views.md) and [docs/repos/laravel/docs/packages.md](repos/laravel/docs/packages.md) — the vendored Laravel docs are the **system of record** for every behavior below (upstream equivalents in §7). This plan contains no behavior sourced from the framework code alone.

**Rule:** one **Given / When / Then** test per unique documented behavior. A test exists only where the vendored docs document the behavior; declared surface the docs do not cover is inventoried in §6 (gaps, G-1–G-7), and documented behavior the declared surface cannot express is likewise inventoried in §6 (G-8). Tests are not implemented here.

---

## 1. Coverage map

| `View` property | Documented behavior | Test |
|---|---|---|
| `share` | shared key/value data is available to all views rendered by the application | AT-01 |
| `composer` | class-based composer: `compose` executes each time the named view is rendered and binds data | AT-02 |
| `composer` | closure-based composer runs when the named view is rendered | AT-03 (blocked — G-8) |
| `composer` | composers are resolved via the service container (constructor type-hints injected) | AT-04 |
| `composer` | one composer attached to multiple views runs for each attached view | AT-05 |
| `composer` | `*` wildcard attaches the composer to all views | AT-06 |
| `creator` | creator executes immediately after the view is instantiated, not at render time | AT-07 |
| `addNamespace` | namespace-registered views load via `package::view` syntax from the registered directory | AT-08 |
| `addNamespace` | two locations registered: vendor override directory checked first, package directory second | AT-09 |
| `addLocation` | not documented in the vendored docs | §6 gaps |
| `prependLocation` | not documented in the vendored docs | §6 gaps |
| `prependNamespace` | not documented in the vendored docs | §6 gaps |
| `replaceNamespace` | not documented in the vendored docs | §6 gaps |
| `addExtension` | not documented in the vendored docs | §6 gaps |
| `flushFinderCache` | not documented in the vendored docs | §6 gaps |
| `flushState` | not documented in the vendored docs | §6 gaps |

---

## 2. Sharing data with all views ([views.md — Sharing Data With All Views](repos/laravel/docs/views.md#sharing-data-with-all-views))

### AT-01 — `share` makes the declared key/value available to every rendered view

**Doc says:** "Occasionally, you may need to share data with all views that are rendered by your application. You may do so using the `View` facade's `share` method." — `View::share('key', 'value');`

- **Given** the manifest declares `view.share: {key: value}` — the doc's example — and two otherwise unrelated views that each echo `$key` without receiving it as passed data.
- **When** each view is rendered.
- **Then** both outputs contain `value` — the shared data reached every view rendered by the application.

Sources: [views.md — Sharing Data With All Views](repos/laravel/docs/views.md#sharing-data-with-all-views).

---

## 3. View composers ([views.md — View Composers](repos/laravel/docs/views.md#view-composers))

### AT-02 — a class-based composer's `compose` executes each time the named view is rendered

**Doc says:** "We'll use the `View` facade's `composer` method to register the view composer." and "Now that we have registered the composer, the `compose` method of the `App\View\Composers\ProfileComposer` class will be executed each time the `profile` view is being rendered." The example composer binds `$view->with('count', $this->users->count())`.

- **Given** the manifest declares `view.composer: {profile: <ProfileComposer class>}` whose `compose` binds `count` to the view, and a `profile` view echoing `$count`.
- **When** the `profile` view is rendered twice (the doc's "each time … is being rendered").
- **Then** both renders show the composer-supplied count — `compose` ran on every render, not only the first.

Sources: [views.md — View Composers](repos/laravel/docs/views.md#view-composers).

### AT-03 — a closure-based composer runs when the named view is rendered (blocked — G-8)

**Doc says:** "Using closure-based composers… `Facades\View::composer('welcome', function (View $view) { // ... });`"

- **Given** a composer registered for the `welcome` view as a closure (per the doc example) that binds data to the view.
- **When** the `welcome` view is rendered.
- **Then** the closure's bound data appears in the rendered output.

**Blocked:** the declared `composer` value type is `string`, and no string-reference mechanism exists in `view:` — the provider passes the string straight to `Factory::composer`, which resolves it as a class composer via `Illuminate\View\Concerns\ManagesEvents::buildClassEventCallback` (`$this->container->make($class)->compose()`). A closure literal is therefore not declarable, and this documented behavior has no executable form against the declared surface. Inventoried as G-8 in §6.

Sources: [views.md — View Composers](repos/laravel/docs/views.md#view-composers).

### AT-04 — composers are resolved via the service container

**Doc says:** "all view composers are resolved via the [service container](repos/laravel/docs/container.md), so you may type-hint any dependencies you need within a composer's constructor." The example constructor type-hints `UserRepository` and `compose` derives `$count` from it.

- **Given** the manifest declares `view.composer: {profile: <ProfileComposer class>}` whose constructor type-hints a container-resolvable dependency and binds data derived from it.
- **When** the `profile` view is rendered.
- **Then** the output shows the dependency-derived data — the service container injected the composer's constructor argument.

Sources: [views.md — View Composers](repos/laravel/docs/views.md#view-composers); [container.md](repos/laravel/docs/container.md) (the container the doc cross-references).

### AT-05 — a composer attached to multiple views runs for each attached view

**Doc says:** "You may attach a view composer to multiple views at once by passing an array of views as the first argument to the `composer` method" — `View::composer(['profile', 'dashboard'], MultiComposer::class);`

- **Given** the same composer declared for `profile` and for `dashboard` — the comma-separated key `view.composer: {'profile,dashboard': <MultiComposer class>}` is the declared surface's direct expression of the doc's array first argument (the provider explodes it into `['profile', 'dashboard']`); two entries (`profile` and `dashboard` each mapped to the composer) are equivalent.
- **When** both the `profile` and `dashboard` views are rendered.
- **Then** the composer's bound data appears in both views.

Sources: [views.md — Attaching a Composer to Multiple Views](repos/laravel/docs/views.md#attaching-a-composer-to-multiple-views).

### AT-06 — the `*` wildcard attaches a composer to all views

**Doc says:** "The `composer` method also accepts the `*` character as a wildcard, allowing you to attach a composer to all views" — `Facades\View::composer('*', function (View $view) { // ... });`

- **Given** the manifest declares `view.composer: {'*': <composer>}` (quote the `*` in YAML) and two otherwise unrelated views.
- **When** both views are rendered.
- **Then** the composer's bound data appears in both — the wildcard matched every view.

Sources: [views.md — Attaching a Composer to Multiple Views](repos/laravel/docs/views.md#attaching-a-composer-to-multiple-views).

---

## 4. View creators ([views.md — View Creators](repos/laravel/docs/views.md#view-creators))

### AT-07 — a creator executes immediately after the view is instantiated, before rendering

**Doc says:** "View "creators" are very similar to view composers; however, they are executed immediately after the view is instantiated instead of waiting until the view is about to render. To register a view creator, use the `creator` method" — `View::creator('profile', ProfileCreator::class);`

- **Given** the manifest declares `view.creator: {profile: <ProfileCreator class>}` that binds data, plus a composer on the same view that records whether the creator's data is already present on the view.
- **When** the `profile` view is rendered.
- **Then** the composer observes the creator's data already bound — the creator ran at instantiation, before render-time composing — and the rendered output shows the creator's data.

Sources: [views.md — View Creators](repos/laravel/docs/views.md#view-creators).

---

## 5. Namespace view registration ([packages.md — Views](repos/laravel/docs/packages.md#views))

> The vendored docs document namespace view registration through the service-provider `loadViewsFrom` convenience; the declared `addNamespace` surface expresses the same registration at the factory level — the `package::view` syntax, the two doc-documented locations per namespace, and vendor-first precedence (declared as the first hint; the finder searches a namespace's hints in declaration order, so listing the vendor override directory first reproduces the documented precedence).

### AT-08 — views registered under a namespace load via `package::view` syntax

**Doc says:** "Package views are referenced using the `package::view` syntax convention. So, once your view path is registered in a service provider, you may load the `dashboard` view from the `courier` package like so: `return view('courier::dashboard');`"

- **Given** the manifest declares `view.addNamespace: {courier: <views dir>}` — the doc's package name and registered path shape — and `dashboard.blade.php` exists in that directory.
- **When** `courier::dashboard` is rendered.
- **Then** the view loads from the declared directory and renders.

Sources: [packages.md — Views](repos/laravel/docs/packages.md#views).

### AT-09 — the vendor override directory is checked before the registered package directory

**Doc says:** "When you use the `loadViewsFrom` method, Laravel actually registers two locations for your views: the application's `resources/views/vendor` directory and the directory you specify. So, using the `courier` package as an example, Laravel will first check if a custom version of the view has been placed in the `resources/views/vendor/courier` directory by the developer. Then, if the view has not been customized, Laravel will search the package view directory you specified in your call to `loadViewsFrom`."

- **Given** `view.addNamespace: {courier: [resources/views/vendor/courier, <package views dir>]}` — the doc's two locations declared as hints, vendor override first — a customized `dashboard.blade.php` in `resources/views/vendor/courier`, and the original `dashboard.blade.php` in the package directory.
- **When** `courier::dashboard` is rendered.
- **Then** the customized vendor copy renders, not the package copy.

Sources: [packages.md — Overriding Package Views](repos/laravel/docs/packages.md#overriding-package-views).

---

## 6. Documentation gaps — declared surface with no backing docs, and documented behavior with no declarable surface

G-1–G-7: no acceptance test can be written from `docs/repos/laravel/docs/` for the following; each lists the nearest non-backing documentation. These need either an upstream doc reference or a source-derived test (out of scope for this plan). G-8 is the inverse: documented behavior the declared surface cannot express.

| # | Declared surface | Why no doc-backed test |
|---|---|---|
| G-1 | `addLocation` | `addLocation` has zero matches in the vendored docs. The docs state only that views "are stored in the `resources/views` directory" (the default path) and document path registration solely in a namespace context via `loadViewsFrom`. Nearest non-backing docs: [views.md — Creating and Rendering Views](repos/laravel/docs/views.md#creating-and-rendering-views), [packages.md — Views](repos/laravel/docs/packages.md#views). |
| G-2 | `prependLocation` | `prependLocation` has zero matches; the check-first (prepend) variant of view-path registration is undocumented. Nearest non-backing docs: same as G-1. |
| G-3 | `prependNamespace` | Not documented; [packages.md — Views](repos/laravel/docs/packages.md#views) documents only the add-ordering semantics (vendor-first) of namespace registration. |
| G-4 | `replaceNamespace` | `replaceNamespace` has zero matches in the vendored docs. Nearest non-backing docs: [packages.md — Overriding Package Views](repos/laravel/docs/packages.md#overriding-package-views) documents overriding by directory precedence, not namespace replacement. |
| G-5 | `addExtension` | `addExtension` has zero matches anywhere in the vendored docs. Nearest non-backing docs: [views.md — Creating and Rendering Views](repos/laravel/docs/views.md#creating-and-rendering-views) states the `.blade.php` extension "informs the framework that the file contains a Blade template" — the default registration, not custom extension registration. |
| G-6 | `flushFinderCache` | `flushFinderCache` has zero matches in the vendored docs. Nearest non-backing docs: [views.md — Optimizing Views](repos/laravel/docs/views.md#optimizing-views) documents `view:clear`, which clears the compiled view cache — a different surface from the finder cache. |
| G-7 | `flushState` | `flushState` has zero matches in the vendored docs. Nearest non-backing docs: [views.md — Optimizing Views](repos/laravel/docs/views.md#optimizing-views) (`view:clear` resets compiled views, not factory render state). |
| G-8 | `composer` (closure form) | The docs document closure composers ([views.md — View Composers](repos/laravel/docs/views.md#view-composers)), but the declared `composer` value is a `string` the `Factory` resolves as a class composer (`ManagesEvents::buildClassEventCallback`) and no string-reference mechanism exists in `view:`, so the closure form (AT-03) has no declarable expression. |

---

## 7. Sources

Vendored docs (**system of record**, relative to `docs/repos/laravel/docs/`) with upstream equivalents:

1. [views.md](repos/laravel/docs/views.md) — https://laravel.com/docs/views — Sharing Data With All Views (§2), View Composers incl. Attaching a Composer to Multiple Views (§3), View Creators (§4), Optimizing Views (§6 nearest-non-backing only).
2. [packages.md](repos/laravel/docs/packages.md) — https://laravel.com/docs/packages — Views (§5), Overriding Package Views (§5).
3. [providers.md](repos/laravel/docs/providers.md) — https://laravel.com/docs/providers — corroborates registering view composers within a service provider's `boot` method (§3 placement only; no additional behavior).
4. [container.md](repos/laravel/docs/container.md) — https://laravel.com/docs/container — the container the composer-resolution contract cross-references (§3 AT-04).
5. [Manifest.php](../src/Manifest.php) and [ViewDeclarationServiceProvider.php](../src/Providers/ViewDeclarationServiceProvider.php) — in-repo subject-under-test context only; no behavior sourced from them.