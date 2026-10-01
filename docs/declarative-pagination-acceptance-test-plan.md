# Declarative Pagination — Acceptance Test Plan (`pagination:`)

**Subject under test:** the manifest's `pagination:` key — **implemented** (`[x] Mapped` in [declarative-framework-api-mapping.md](declarative-framework-api-mapping.md) Domain 4, built per [declarative-pagination.md](declarative-pagination.md)). Each key is a native `Illuminate\Pagination\Paginator` static preset; the declared surface ([README.md — Pagination](../README.md#pagination)) is:

```yaml
pagination:
  useTailwind: true                   # -> Paginator::useTailwind()
  useBootstrap: true                  # -> Paginator::useBootstrap() — alias for useBootstrapFour()
  useBootstrapThree: true             # -> Paginator::useBootstrapThree()
  useBootstrapFour: true              # -> Paginator::useBootstrapFour()
  useBootstrapFive: true              # -> Paginator::useBootstrapFive()
  defaultView: pagination::custom     # -> Paginator::defaultView($view)
  defaultSimpleView: pagination::simple-custom   # -> Paginator::defaultSimpleView($view)
```

**Source documentation:** [docs/repos/laravel/docs/pagination.md](repos/laravel/docs/pagination.md) — the vendored Laravel docs are the **system of record** for every documented behavior below; the declaration semantics (order, override, omission) are specified in [README.md — Pagination](../README.md#pagination) and verified in [declarative-pagination.md §1.4](declarative-pagination.md#14-verified-semantics-the-implementation-must-preserve). Two preset keys (`useBootstrap`, `useBootstrapThree`) are documented by the README but **absent from the vendored docs** — for those, the framework source (`vendor/laravel/framework/src/Illuminate/Pagination/AbstractPaginator.php`, v13.33.0, verified in [declarative-pagination.md §1.3](declarative-pagination.md#13-native-preset-surface-v13330-verified-signatures)) is the only source, and each such test says so explicitly.

**Rule:** one **Given / When / Then** test per unique documented behavior. A test exists only where the behavior is documented (vendored docs, README spec, or framework-verified native surface); documented-but-non-declarable behavior is inventoried in §6. Tests are not implemented here.

**Harness prerequisite:** the preset state lives in `public static` properties on the `AbstractPaginator` lineage and is therefore **process-global** — `Paginator`, `LengthAwarePaginator`, and (indirectly) `CursorPaginator` all read the same statics ([declarative-pagination.md §1.4(1)/(2)](declarative-pagination.md#14-verified-semantics-the-implementation-must-preserve)). Every test must reset both statics to the native defaults before its **Given** (`Paginator::defaultView('pagination::tailwind')` / `Paginator::defaultSimpleView('pagination::simple-tailwind')`, the `beforeEach` of [PaginationRegistrationTest.php](../tests/Feature/PaginationRegistrationTest.php)), so assertions are order-independent. Assert via `ReflectionProperty` on `Paginator::class` (the statics are inherited, not redeclared).

---

## 1. Coverage map

| Documented behavior | Test |
|---|---|
| default pagination views are the Tailwind views | AT-01 |
| per-instance `links('view.name')` overrides the default view for that instance | AT-02 |
| `links('view.name', [data])` passes additional data to the view | AT-03 |
| length-aware paginators consume `defaultView`; simple & cursor paginators consume `defaultSimpleView` | AT-04 |
| `useTailwind: true` → `Paginator::useTailwind()` sets both default views | AT-05 |
| `useBootstrap: true` → alias for `useBootstrapFour()` (framework-only) | AT-06 |
| `useBootstrapThree: true` → `Paginator::useBootstrapThree()` (framework-only) | AT-07 |
| `useBootstrapFour: true` → `Paginator::useBootstrapFour()` | AT-08 |
| `useBootstrapFive: true` → `Paginator::useBootstrapFive()` | AT-09 |
| presets apply in declaration order; the **last truthy preset wins** | AT-10 |
| last truthy preset wins within the Bootstrap family | AT-11 |
| `false` presets are never applied | AT-12 |
| a manifest without a `pagination:` block never touches the statics | AT-13 |
| `defaultView:` alone designates the default pagination view | AT-14 |
| `defaultSimpleView:` alone designates the default simple pagination view | AT-15 |
| explicit views apply **after** the presets and override them | AT-16 |
| explicit keys are independent — `defaultSimpleView:` does not disturb a preset's `defaultView` | AT-17 |
| `vendor:publish --tag=laravel-pagination` publishes views for editing | §6 (no test) |
| `onEachSide` link-window adjustment, `toJson` conversion, instance-method table | §6 (no test) |
| `pagination::semantic-ui` shipped view (no preset method exists) | §6 (no test) |

---

## 2. Default views & per-instance rendering ([pagination.md — Customizing the Pagination View](repos/laravel/docs/pagination.md#customizing-the-pagination-view))

### AT-01 — the default pagination views are the Tailwind views

**Doc says:** "By default, the views rendered to display the pagination links are compatible with the [Tailwind CSS](https://tailwindcss.com) framework." and, of the published views, "The `tailwind.blade.php` file within this directory corresponds to the default pagination view."

- **Given** a bootstrapped application with no `pagination:` declaration and both statics reset to the native defaults.
- **When** the paginator's default view names are resolved.
- **Then** the default view is `pagination::tailwind` and the default simple view is `pagination::simple-tailwind` — the Tailwind-compatible pair the docs name as default.

Sources: [pagination.md — Customizing the Pagination View](repos/laravel/docs/pagination.md#customizing-the-pagination-view); [AbstractPaginator.php:130,137](../vendor/laravel/framework/src/Illuminate/Pagination/AbstractPaginator.php) (statics initialized to `pagination::tailwind` / `pagination::simple-tailwind`).

### AT-02 — a per-instance `links('view.name')` overrides the default view for that instance only

**Doc says:** "When calling the `links` method on a paginator instance, you may pass the view name as the first argument to the method."

- **Given** a `pagination:` declaration whose `defaultView` is `pagination::custom`, and two paginator instances.
- **When** the first renders via `links('pagination::other')` and the second renders via `links()`.
- **Then** the first renders `pagination::other` and the second renders `pagination::custom` — the per-instance argument wins for that instance, while the declared default remains the fallback.

Sources: [pagination.md — Customizing the Pagination View](repos/laravel/docs/pagination.md#customizing-the-pagination-view); [LengthAwarePaginator.php:102](../vendor/laravel/framework/src/Illuminate/Pagination/LengthAwarePaginator.php) (`$view ?: static::$defaultView`).

### AT-03 — additional data passed to `links()` is forwarded to the view

**Doc says:** the `links` call accepts additional data — `{{ $paginator->links('view.name', ['foo' => 'bar']) }}`.

- **Given** a paginator and a pagination view that echoes a variable.
- **When** the view is rendered via `links($view, ['foo' => 'bar'])`.
- **Then** the view receives `foo` = `bar` in addition to the paginator data.

Sources: [pagination.md — Customizing the Pagination View](repos/laravel/docs/pagination.md#customizing-the-pagination-view).

### AT-04 — length-aware paginators consume `defaultView`; simple and cursor paginators consume `defaultSimpleView`

**Doc says:** "When calling the `paginate` method, you will receive an instance of `Illuminate\Pagination\LengthAwarePaginator`, while calling the `simplePaginate` method returns an instance of `Illuminate\Pagination\Paginator`. And, finally, calling the `cursorPaginate` method returns an instance of `Illuminate\Pagination\CursorPaginator`." The vendored docs name the `defaultView` and `defaultSimpleView` methods but not their consumers; the consumer mapping is framework-verified (each paginator renders `$view ?: <its default static>`).

- **Given** a `pagination:` declaration with `defaultView: pagination::custom` and `defaultSimpleView: pagination::simple-custom`, and one instance each of `LengthAwarePaginator`, `Paginator`, and `CursorPaginator`.
- **When** each renders via `links()` with no per-instance view.
- **Then** the length-aware paginator renders `pagination::custom`, while the simple and cursor paginators both render `pagination::simple-custom`.

Sources: [pagination.md — Displaying Pagination Results](repos/laravel/docs/pagination.md#displaying-pagination-results); [LengthAwarePaginator.php:102](../vendor/laravel/framework/src/Illuminate/Pagination/LengthAwarePaginator.php); [Paginator.php:119](../vendor/laravel/framework/src/Illuminate/Pagination/Paginator.php); [CursorPaginator.php:100](../vendor/laravel/framework/src/Illuminate/Pagination/CursorPaginator.php) (reads `Paginator::$defaultSimpleView`); [declarative-pagination.md §1.4(2)](declarative-pagination.md#14-verified-semantics-the-implementation-must-preserve).

---

## 3. Preset keys — each dispatches the native static and sets **both** default views ([pagination.md — Using Bootstrap](repos/laravel/docs/pagination.md#using-bootstrap); [README.md — Pagination](../README.md#pagination))

Each preset test asserts **both** statics, which is the native semantics "each preset overwrites both default views" ([README.md — Pagination](../README.md#pagination)); no separate meta-test is needed.

### AT-05 — `useTailwind: true` dispatches `Paginator::useTailwind()`

**Doc says:** the default views are the Tailwind views (AT-01); the key is a "native `Illuminate\Pagination\Paginator` static preset" ([README.md — Pagination](../README.md#pagination)).

- **Given** a `pagination:` declaration with only `useTailwind: true` and the statics reset to non-default values (so the dispatch is observable).
- **When** the manifest is applied.
- **Then** the default view is `pagination::tailwind` **and** the default simple view is `pagination::simple-tailwind` — the native `useTailwind()` preset set both.

Sources: [README.md — Pagination](../README.md#pagination); [AbstractPaginator.php:617-621](../vendor/laravel/framework/src/Illuminate/Pagination/AbstractPaginator.php).

### AT-06 — `useBootstrap: true` is the alias for `useBootstrapFour()` *(framework-only behavior)*

**Doc says:** the README marks the key `useBootstrap` as "alias for useBootstrapFour()". The vendored [pagination.md](repos/laravel/docs/pagination.md#using-bootstrap) does not document `useBootstrap`; the framework source is the only source: the method body is the single statement `static::useBootstrapFour();`.

- **Given** a `pagination:` declaration with only `useBootstrap: true`.
- **When** the manifest is applied.
- **Then** the default view is `pagination::bootstrap-4` and the default simple view is `pagination::simple-bootstrap-4` — the unversioned alias landed on the Bootstrap 4 views.

Sources: [README.md — Pagination](../README.md#pagination); [AbstractPaginator.php:628-631](../vendor/laravel/framework/src/Illuminate/Pagination/AbstractPaginator.php); [declarative-pagination.md §1.3](declarative-pagination.md#13-native-preset-surface-v13330-verified-signatures) (verified signature and body).

### AT-07 — `useBootstrapThree: true` dispatches `Paginator::useBootstrapThree()` *(framework-only behavior)*

**Doc says:** the README declares the key as a native preset. The vendored [pagination.md](repos/laravel/docs/pagination.md#using-bootstrap) documents only `useBootstrapFour`/`useBootstrapFive`; the framework source is the only source: the method sets `defaultView('pagination::bootstrap-3')` + `defaultSimpleView('pagination::simple-bootstrap-3')`.

- **Given** a `pagination:` declaration with only `useBootstrapThree: true`.
- **When** the manifest is applied.
- **Then** the default view is `pagination::bootstrap-3` and the default simple view is `pagination::simple-bootstrap-3`.

Sources: [README.md — Pagination](../README.md#pagination); [AbstractPaginator.php:638-642](../vendor/laravel/framework/src/Illuminate/Pagination/AbstractPaginator.php); [declarative-pagination.md §1.3](declarative-pagination.md#13-native-preset-surface-v13330-verified-signatures).

### AT-08 — `useBootstrapFour: true` switches to the built-in Bootstrap views

**Doc says:** "Laravel includes pagination views built using [Bootstrap CSS](https://getbootstrap.com/). To use these views instead of the default Tailwind views, you may call the paginator's `useBootstrapFour` or `useBootstrapFive` methods within the `boot` method of your `App\Providers\AppServiceProvider` class."

- **Given** a `pagination:` declaration with only `useBootstrapFour: true` and the statics reset to the native Tailwind defaults.
- **When** the manifest is applied.
- **Then** the default view is `pagination::bootstrap-4` and the default simple view is `pagination::simple-bootstrap-4` — the built-in Bootstrap views replaced the default Tailwind views.

Sources: [pagination.md — Using Bootstrap](repos/laravel/docs/pagination.md#using-bootstrap); [README.md — Pagination](../README.md#pagination); [AbstractPaginator.php:649-653](../vendor/laravel/framework/src/Illuminate/Pagination/AbstractPaginator.php).

### AT-09 — `useBootstrapFive: true` switches to the built-in Bootstrap 5 views

**Doc says:** same clause as AT-08 ("you may call the paginator's `useBootstrapFour` or `useBootstrapFive` methods").

- **Given** a `pagination:` declaration with only `useBootstrapFive: true` and the statics reset to the native Tailwind defaults.
- **When** the manifest is applied.
- **Then** the default view is `pagination::bootstrap-5` and the default simple view is `pagination::simple-bootstrap-5`.

Sources: [pagination.md — Using Bootstrap](repos/laravel/docs/pagination.md#using-bootstrap); [README.md — Pagination](../README.md#pagination); [AbstractPaginator.php:660-664](../vendor/laravel/framework/src/Illuminate/Pagination/AbstractPaginator.php).

---

## 4. Order & omission semantics ([README.md — Pagination](../README.md#pagination); [declarative-pagination.md §2.2](declarative-pagination.md#22-order-and-override-semantics-rule-3))

### AT-10 — presets apply in declaration order; the **last truthy preset wins**

**Doc says:** "Presets apply in the order above and each overwrites both default views, so the **last truthy preset wins**."

- **Given** a `pagination:` declaration with `useTailwind: true` and `useBootstrapThree: true` (both truthy, in declared order).
- **When** the manifest is applied.
- **Then** the default view is `pagination::bootstrap-3` and the default simple view is `pagination::simple-bootstrap-3` — the later preset overwrote both views set by the earlier one; no conflict error is raised.

Sources: [README.md — Pagination](../README.md#pagination); [declarative-pagination.md §2.2](declarative-pagination.md#22-order-and-override-semantics-rule-3).

### AT-11 — the last truthy preset wins within the Bootstrap family

**Doc says:** same clause — "each overwrites both default views, so the **last truthy preset wins**."

- **Given** a `pagination:` declaration with `useBootstrapFour: true` and `useBootstrapFive: true` (in declared order).
- **When** the manifest is applied.
- **Then** the default view is `pagination::bootstrap-5` and the default simple view is `pagination::simple-bootstrap-5` — the later Bootstrap preset won.

Sources: [README.md — Pagination](../README.md#pagination); [AbstractPaginator.php:649-664](../vendor/laravel/framework/src/Illuminate/Pagination/AbstractPaginator.php) (each preset is a plain overwrite of both statics).

### AT-12 — `false` presets are never applied

**Doc says:** "Omitted or `false` presets are never applied."

- **Given** a `pagination:` declaration where **all five** preset keys are present but set to `false`, and the statics reset to the native Tailwind defaults.
- **When** the manifest is applied.
- **Then** the default view is still `pagination::tailwind` and the default simple view is still `pagination::simple-tailwind` — no native preset call was dispatched.

Sources: [README.md — Pagination](../README.md#pagination); [declarative-pagination.md §1.4(4)](declarative-pagination.md#14-verified-semantics-the-implementation-must-preserve) ("Presets default to `false`, not to 'applied'").

### AT-13 — a manifest without a `pagination:` block never touches the paginator statics

**Doc says:** the presets are declared "in the `pagination` object" ([README.md — Pagination](../README.md#pagination)); a manifest that does not declare the block has nothing to apply.

- **Given** a manifest with no `pagination:` key at all (e.g. `app: {}`).
- **When** the manifest is applied.
- **Then** the default view and default simple view remain the native Tailwind defaults — the block's absence applies nothing.

Sources: [README.md — Pagination](../README.md#pagination); [declarative-pagination.md §1.4(4)](declarative-pagination.md#14-verified-semantics-the-implementation-must-preserve); [PaginationRegistrationTest.php — "returns early when manifest has no pagination block"](../tests/Feature/PaginationRegistrationTest.php).

---

## 5. Explicit default views ([pagination.md — Customizing the Pagination View](repos/laravel/docs/pagination.md#customizing-the-pagination-view); [README.md — Pagination](../README.md#pagination))

### AT-14 — `defaultView:` alone designates the default pagination view

**Doc says:** "If you would like to designate a different file as the default pagination view, you may invoke the paginator's `defaultView` and `defaultSimpleView` methods within the `boot` method of your `App\Providers\AppServiceProvider` class" — `Paginator::defaultView('view-name');`.

- **Given** a `pagination:` declaration with only `defaultView: pagination::custom` (no presets) and the statics reset to the native defaults.
- **When** the manifest is applied and the default view names are resolved.
- **Then** the default view is `pagination::custom`, while the default simple view remains the native `pagination::simple-tailwind` — the native `defaultView()` writes only its own static.

Sources: [pagination.md — Customizing the Pagination View](repos/laravel/docs/pagination.md#customizing-the-pagination-view); [README.md — Pagination](../README.md#pagination); [AbstractPaginator.php:596-599](../vendor/laravel/framework/src/Illuminate/Pagination/AbstractPaginator.php).

### AT-15 — `defaultSimpleView:` alone designates the default simple pagination view

**Doc says:** same clause — `Paginator::defaultSimpleView('view-name');`.

- **Given** a `pagination:` declaration with only `defaultSimpleView: pagination::simple-custom` (no presets) and the statics reset to the native defaults.
- **When** the manifest is applied and the default view names are resolved.
- **Then** the default simple view is `pagination::simple-custom`, while the default view remains the native `pagination::tailwind`.

Sources: [pagination.md — Customizing the Pagination View](repos/laravel/docs/pagination.md#customizing-the-pagination-view); [README.md — Pagination](../README.md#pagination); [AbstractPaginator.php:607-610](../vendor/laravel/framework/src/Illuminate/Pagination/AbstractPaginator.php).

### AT-16 — explicit views apply **after** the presets and override them

**Doc says:** "`defaultView` / `defaultSimpleView` apply after the presets and override them." ([README.md — Pagination](../README.md#pagination))

- **Given** a `pagination:` declaration with `useBootstrapFour: true`, `defaultView: pagination::custom`, and `defaultSimpleView: pagination::simple-custom`.
- **When** the manifest is applied.
- **Then** the default view is `pagination::custom` and the default simple view is `pagination::simple-custom` — the explicit keys overwrote the Bootstrap 4 views the preset had set, not the other way around.

Sources: [README.md — Pagination](../README.md#pagination); [declarative-pagination.md §2.2](declarative-pagination.md#22-order-and-override-semantics-rule-3); [PaginationRegistrationTest.php — "configures paginator styles and views from manifest"](../tests/Feature/PaginationRegistrationTest.php).

### AT-17 — the explicit keys are independent: `defaultSimpleView:` does not disturb a preset's `defaultView`

**Doc says:** each key maps to exactly one native call (`defaultSimpleView:` → `Paginator::defaultSimpleView($view)`), and the native setters each write only their own static ([AbstractPaginator.php:596-610](../vendor/laravel/framework/src/Illuminate/Pagination/AbstractPaginator.php)).

- **Given** a `pagination:` declaration with `useBootstrapFive: true` and `defaultSimpleView: pagination::simple-custom` (no explicit `defaultView`).
- **When** the manifest is applied.
- **Then** the default view remains the preset's `pagination::bootstrap-5` while the default simple view is `pagination::simple-custom` — overriding one default view does not reset the other.

Sources: [README.md — Pagination](../README.md#pagination); [AbstractPaginator.php:596-599,607-610](../vendor/laravel/framework/src/Illuminate/Pagination/AbstractPaginator.php); [declarative-pagination.md §1.4(3)](declarative-pagination.md#14-verified-semantics-the-implementation-must-preserve).

---

## 6. Documented but non-declarable

Behavior the vendored docs document that is deliberately **not** part of the `pagination:` surface:

- **The `vendor:publish` workflow** — "the easiest way to customize the pagination views is by exporting them to your `resources/views/vendor` directory using the `vendor:publish` command" (`php artisan vendor:publish --tag=laravel-pagination`). A one-time publishing workflow, not a manifest key; the declaration surface references views by name.
- **Adjusting the pagination link window** — `onEachSide` is a paginator *instance* method ([pagination.md — Adjusting the Pagination Link Window](repos/laravel/docs/pagination.md#adjusting-the-pagination-link-window)), runtime plumbing rather than a static preset.
- **Converting results to JSON** — `toJson` / returning a paginator from a route ([pagination.md — Converting Results to JSON](repos/laravel/docs/pagination.md#converting-results-to-json)); runtime behavior with no static to declare.
- **`pagination::semantic-ui`** — a shipped pagination view with **no** preset method in v13.33.0, therefore not declarable under key = native method name ([declarative-pagination.md §1.3](declarative-pagination.md#13-native-preset-surface-v13330-verified-signatures)).
- **The remaining `AbstractPaginator` statics** (`resolveCurrentPath`, `currentPageResolver`, `queryStringResolver`, `viewFactoryResolver`, …) — request-scoped runtime resolvers, not declaration surface ([declarative-pagination.md §1.1(6)](declarative-pagination.md#11-claim-comparison-declarative-tier1-remainingmd-11-vs-source-of-truth)).
- **Per-instance additional-data rendering and JSON pagination URLs** (page query-string handling) — documented runtime behaviors of `links()` and the paginator objects, not view resolvers or styling.