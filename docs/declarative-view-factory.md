# Declarative View Factory Render Methods — Dynamic `Illuminate\View\Factory` Dispatch in `DeclaredView` & the `view:` Epilogue

> Manifest forms in this document are the pre-engine block shapes; see docs/general-purpose-migration-plan.md §2.1 and README for the current forms.

Source of truth: `vendor/laravel/framework/src/Illuminate/View/Factory.php` (`laravel/framework` v13.33.0), with:
- `Illuminate/View/View.php` (`View.php:157`, `182`)
- `Illuminate/View/FileViewFinder.php` (`FileViewFinder.php:29`, `264`)
- `Illuminate/View/Concerns/ManagesEvents.php` (`ManagesEvents.php:35`)
- `Illuminate/Routing/ViewController.php` (`ViewController.php:32`)
- `Illuminate/Routing/ResponseFactory.php` (`ResponseFactory.php:59`, `85`)
- `Illuminate/Foundation/Application.php` (`Application.php:1685`)

Laravel documentation references:
- `docs/repos/laravel/docs/views.md` (Creating & Rendering Views:51, Creating the First Available View:92, Determining if a View Exists:103, Passing Data to Views:116, View Composers:167)
- `docs/repos/laravel/docs/responses.md` (Attaching Headers to Responses:77, View Responses:326)

Grounding documentation:
- [declarative-tier1-gap-inventory.md](declarative-tier1-gap-inventory.md) §1 row 4, §2.3, §4 item 6
- [declarative-view.md](declarative-view.md) (the Tier 1 `view:` block)
- [declarative-view-data.md](declarative-view-data.md) (the `setDefaults` seam, references, precedence)
- [declarative-inline-template.md](declarative-inline-template.md) (the `template` source, `deleteCachedView`)

**Resolves**: [declarative-tier1-gap-inventory.md](declarative-tier1-gap-inventory.md) row 4 — *"View Factory & Namespaces — `view:` — `[/]` — Signature inversion fixed; render-time factory methods remain — `[/]` (narrower)"* — and remediation item 6 (*"`view:` render-time factory methods (§2.3) via `DeclaredView` `setDefaults`"*). After this specification lands, the row reclassifies to `[x]`: the boot-time surface was complete, and the render-time `Factory` surface is reachable through **one dynamic dispatch** with zero per-method code.

Goal: a `setDefaults.factory` key on `DeclaredView` routes whose **key is an `Illuminate\View\Factory` method name** and whose **value is that method's argument list**, dispatched as `$Factory->{$method}(...$arguments)` — the same dynamic-dispatch shape `RoutesDeclarationServiceProvider::applyBuilder()` applies to `Route` and `src/Query.php::dispatchMethod()` applies to `Builder`/`Relation`. Plus two no-argument epilogue keys on the Tier 1 `view:` block (`flushFinderCache`, `flushState`) dispatched by the same pattern in `ViewDeclarationServiceProvider`. No per-method branches exist anywhere: adding a future `Factory` render method is a manifest edit, not a code change.

---

## 1. Public API of `Factory::class` (the render-time surface)

### 1.1 Lifecycle position (when each call runs)

```
// boot time — the Tier 1 `view:` block (declarative-view.md §1.1, shipped)
$app->boot();
    LaravelDeclarationProvider::boot()
        callAfterResolving('view', registerView)     <- nine pass-through registrations (Factory.php:352-505; ManagesEvents.php:18, 53)
            epilogue: flushFinderCache() / flushState()   <- NEW: after the block, before any render of this boot

// first make('view') of the application
    ViewServiceProvider's `view` singleton (Application.php:1685 alias -> Factory::class, shared)
    Container::fireAfterResolvingCallbacks('view') -> registerView() + epilogue

// request time — the Tier 2 `DeclaredView` seam (declarative-view-data.md §1.4)
Route dispatch -> ControllerDispatcher -> DeclaredView::__invoke(...$args)
    $routeParameters = array_filter($args, not in [template, view, data, status, headers, deleteCachedView, factory])
    $parameters      = route parameters by name + `request` (DeclaredRequest when metadata.request declares one)
    $mergedData      = resolve(data references) then array_merge($resolvedData, $routeParameters)   <- route parameters win
    template? -> Blade::render()                          (inline; no view identity; composing: {routeName} bridge)
    view?     -> ViewController::__invoke()               -> ResponseFactory::view() -> Factory::make() | Factory::first()
    factory?  -> $Factory->{$method}(...$arguments)       <- NEW: dynamic dispatch, one call per render
    else      -> LogicException

// inside every dispatch that returns a View
    Factory::make/file/first -> viewInstance() -> creators fire at make (callCreator, Factory.php:148-180)
    View::render() -> renderContents() -> callComposer($this)   (View.php:157, 182) -> composers fire, named views included
```

Consequences, each verified against v13.33.0 with Testbench:

1. **Render-time methods are not boot-time registrations.** `make`, `file`, `first`, `exists`, `renderWhen`, `renderUnless`, `renderEach` all create or probe a *view instance for this request*. A `view:` block key would call them once at boot against no route parameters and no request — the inventory's "render seam" verdict (§2.3 context) is correct, so they live on `DeclaredView`, which already owns the route-parameter merge and the reference pipeline.
2. **The epilogue runs inside the first `view` resolution** (§1.1 of [declarative-view.md](declarative-view.md)). When `view` was resolved *before* the provider booted, `callAfterResolving()` runs the callback immediately — and anything that resolution already cached in `FileViewFinder::$views` (`FileViewFinder.php:29`) is now stale for the freshly declared locations/namespaces/extensions. `flushFinderCache()` empties that cache (`FileViewFinder.php:264`), so the block's paths win. `flushState()` resets `renderCount`, `renderedOnce`, sections, stacks, components and fragments (`Factory.php:507-515`) — the isolation switch for long-running workers and the test harness.
3. **`first` and `make` are already dispatched by the `view:` key.** `ResponseFactory::view()` (`ResponseFactory.php:85-92`) branches: `is_array($view)` → `Factory::first($view, $data)`, else → `Factory::make($view, $data)`, then wraps in `make($result, $status, $headers)`. `ViewController::__invoke()` (`ViewController.php:32`) forwards `setDefaults.view` there. So the inventory's `first` and `make` rows narrow to "already reachable"; a `factory.first` / `factory.make` dispatch remains available for the open surface (explicit `$mergeData`, mixed forms), but the idiomatic form is `view:`.
4. **`exists` is reachable at query time without a seam key.** The constructor shares the factory as `__env` (`Factory.php:120`: `share('__env', $this)`), so every template and every `data`/`factory` reference can call `$__env->exists('view.name')` or `app('view')->exists(...)` natively. A declarative key would have to give a `bool` render semantics the `Factory` does not define — Rule 1 ("no invented verbs") and Rule 7 ("fail at the same place Laravel fails") reject that; the declarative existence guard is `first`, which throws Laravel's own `InvalidArgumentException: None of the views in the given array exist.` (`Factory.php:174-186`) when the chain is empty.
5. **Composers and creators fire natively for every `View`-returning dispatch.** `make()` calls `callCreator()`; `render()` calls `callComposer()` — so `factory: {file: ...}` and `factory: {first: [...]}` honor `composer:`/`creator:` declarations for the resolved view name, unlike inline `template` renders (which have no view identity and use the `composing: {routeName}` bridge instead, [declarative-inline-template.md](declarative-inline-template.md)). String-returning dispatches (`renderEach`, `renderWhen`, `renderUnless`) build real `View` instances internally — `renderEach` fires the partial's composers per item (`Factory.php:228-252`); the `false`/empty branches produce plain strings with no view identity.
6. **`route:cache` safety (Rule 6).** `factory:` values are strings, lists and maps — YAML-decodable, serializable route defaults, applied through `RoutesDeclarationServiceProvider::applyBuilder()`'s generic tail → `$Route->setDefaults($arguments)` (`Route::setDefaults()`, `Route.php:651`) — `setDefaults` is an unknown manifest key captured into `$Route->builders` (Route.php:31, `extractBuilders()`), so it needs no special branch and `factory:` rides along — exactly like `view`/`data`/`status`/`headers` today.

### 1.2 Properties (state the seam touches)

| Property | Type | Read by |
|---|---|---|
| `Factory::$shared` | `array<string, mixed>` | `View::gatherData()` — `share` beats nothing, loses to view data |
| `FileViewFinder::$views` | `array<string, string>` resolved-name → path | `find()` short-circuit; emptied by `flushFinderCache()` |
| `Factory::$renderCount`, `$renderedOnce`, sections, stacks, components, fragments | render bookkeeping | reset by `flushState()` |

### 1.3 Public methods

**The render-time dispatch surface** (every method a `factory:` entry may target; signatures verified at v13.33.0):

| Method | Signature (vendor) | Returns | When it fires |
|---|---|---|---|
| `make` | `make($view, $data = [], $mergeData = [])` — `Factory.php:148` | `View` | creators at make, composers at render |
| `file` | `file($path, $data = [], $mergeData = [])` — `Factory.php:131` | `View` | `viewInstance($path, $path, $data)` — the finder is bypassed; engine still resolves by extension |
| `first` | `first(array $views, $data = [], $mergeData = [])` — `Factory.php:174` | `View` | `exists()` per candidate until one hits; `InvalidArgumentException` when none exist |
| `renderWhen` | `renderWhen($condition, $view, $data = [], $mergeData = [])` — `Factory.php:196` | `string` | `! $condition` → `''`; else `make(...)->render()` |
| `renderUnless` | `renderUnless($condition, $view, $data = [], $mergeData = [])` — `Factory.php:214` | `string` | `renderWhen(! $condition, ...)` |
| `renderEach` | `renderEach($view, $data, $iterator, $empty = 'raw|')` — `Factory.php:228` | `string` | per item: `make($view, ['key' => $key, $iterator => $value])->render()`; empty `$data` → `$empty`, where a `raw|` prefix renders the literal remainder — a non-`raw|` `$empty` is a view name (`make($empty)->render()`, `Factory.php:247`) |
| `exists` | `exists($view)` — `Factory.php:296` | `bool` | `true` unless the finder throws `InvalidArgumentException` |

**The epilogue surface** (boot-time, after the `view:` block applies):

| Method | Signature | Effect |
|---|---|---|
| `flushFinderCache` | `flushFinderCache(): void` — `Factory.php:576` | `getFinder()->flush()` → `$views = []` (`FileViewFinder.php:264`) |
| `flushState` | `flushState(): void` — `Factory.php:507` | `renderCount = 0`, `renderedOnce = []`, `flushSections()`, `flushStacks()`, `flushComponents()`, `flushFragments()` |
| `flushStateIfDoneRendering` | `flushStateIfDoneRendering(): void` — `Factory.php:523` | `flushState()` only when `doneRendering()`; the boot-safe sibling a host renders mid-boot can call in PHP |

**Not dispatch targets** (declarative-view.md §1.3 stands): `composers()` (the `composer` map *is* its loop, `ManagesEvents.php:35`), `shared`/`getShared`/`getExtensions`/`getFinder`/getters (read-back), `setFinder`/`setDispatcher`/`setContainer` (objects), `incrementRender`/`decrementRender`/`hasRenderedOnce`/`markAsRenderedOnce`/`doneRendering` (render bookkeeping called by compiled Blade), sections/stacks/loops/components (`Manages*` internals), `macro`/`mixin` (take Closures).

### 1.4 What each inventory row resolves to

| Inventory §2.3 row | Outcome |
|---|---|
| `Factory::first()` → "`DeclaredView` `setDefaults.first`" | **Already dispatched**: `setDefaults.view` as a list → `ResponseFactory::view()` → `Factory::first()` (`ResponseFactory.php:85-90`), documented in [declarative-view-data.md](declarative-view-data.md) §2.2. `factory: {first: [...]}` stays available on the open surface. |
| `Factory::make()` → "Tier 2 `DeclaredView`" | **Already dispatched**: `setDefaults.view` string form → `Factory::make()` through the same branch. `factory: {make: {view, data, mergeData}}` expresses `$mergeData` directly — `ResponseFactory::view()` forwards no `$mergeData`, so the plain `view:` key cannot. |
| `Factory::file()` → "`DeclaredView` `setDefaults.file`" | **New**: `factory: {file: ...}` — renders a path the finder never searches; relative paths follow the manifest's `absolute()` rule (§2.2). |
| `Factory::renderEach()` → "`DeclaredView` `setDefaults.renderEach`" | **New**: `factory: {renderEach: ...}` — items resolve through the reference pipeline (§2.2). |
| (`renderWhen` / `renderUnless` — same family) | **Free**: covered by the same dispatcher, zero extra code. |
| `Factory::exists()` → "`queries` clause or Tier 2 render seam" | **Resolved as reachable, not a key**: `bool` is not renderable (§2.1 rejection 3); query-time callers use `$__env->exists()` / `app('view')->exists()`; the declarative guard is `first`. A `factory: {exists: ...}` declaration fails loudly at render (`LogicException` naming the return type, §2.2) — Rule 7, never silent. |
| `Factory::flushFinderCache()` / `Factory::flushState()` → "provider epilogue" | **New**: `view.flushFinderCache` / `view.flushState` boolean keys, dispatched by one dynamic loop after the block applies (§2.5). |

### 1.5 The PHP this replaces

```php
// app/Http/Controllers/LegalController.php — a bespoke controller per static page
public function terms(): Response
{
    $content = view()->file(resource_path('legal/terms.html'), ['title' => 'Terms'])->render();

    return response($content, 200, ['Cache-Control' => 'private']);
}

// app/Http/Controllers/PostCardsController.php — the per-item loop, hand-rolled
public function cards(): Response
{
    $content = view()->renderEach('users.partials.post', Post::all(), 'post', 'raw|No posts');

    return response($content);
}
```

---

## 2. Manifest schema proposal

### 2.1 Design rule

> **`setDefaults.factory` is one dynamic dispatch onto `Illuminate\View\Factory`.** Its map holds **exactly one entry**; the **key is the `Factory` method name** (Rule 1: `factory.file` → `Factory::file()`), the **value is that method's argument list** (Rule 2: one call, arguments passed through). `DeclaredView` resolves the method from the key and calls `$Factory->{$method}(...$arguments)` — there is no per-method code in the seam.

> **`view.flushFinderCache` / `view.flushState` are no-argument `Factory` calls** the provider's epilogue dispatches after the block applies: `true` → `$Factory->{$method}()`, the `routes.builders` boolean convention. Absent or `false` → no call.

**Why one nested `factory:` key instead of the inventory's flat `setDefaults.first` / `setDefaults.file` / `setDefaults.renderEach`:**

1. **Flat keys collide with route parameters.** Every extra `setDefaults` key doubles as a route-parameter default that reaches `data` and references by name ([declarative-view-data.md](declarative-view-data.md) §2.2 — "any other key | literal | a route parameter default"). Dispatching flat method keys would require a hardcoded allowlist to separate `Factory` method names from parameter names — the "hardcoded static whitelists" pattern the audit eliminated for `schema:` column types (gap inventory §2.5). The nested key makes the surface unambiguous by construction.
2. **The nested key is an open surface.** `first`, `file`, `make`, `renderEach`, `renderWhen`, `renderUnless` — and every future render-time `Factory` method — dispatch through one `$Factory->{$method}(...$arguments)` line. Flat keys would add a branch (or a whitelist entry) per method: the "ad-hoc procedural loops" the audit's §1.12 defect list rejects.
3. **One entry per render.** A route renders exactly once. A multi-entry `factory:` map has no native chaining to lean on (the `Factory` does not compose calls), so two entries would be two render sources — a `LogicException` (Rule 7), not "last write wins".

**Why `exists` gets no guard semantics.** `Factory::exists(): bool` is a *predicate*. Mapping it would force the seam to invent what a `false` means (404? empty 200? skip?) — none of which Laravel defines. The native composition is `first`: `Factory::first([$view])` *is* `exists($view) ? make($view) : throw InvalidArgumentException` (`Factory.php:174-186`), and `__env` puts the predicate inside every template and reference already (§1.1.4). The dispatcher's result contract turns a `bool` result into a `LogicException` naming the return type — a loud, same-place-as-Laravel failure, not a silent no-op.

### 2.2 Values

**Dispatch contract (every entry, all methods, no per-method code):**

| Declared value | Dispatch | Native call |
|---|---|---|
| `true` \| `null` | no-argument call | `$Factory->{$method}()` |
| scalar (string path/view name) | first argument | `$Factory->{$method}($value)` |
| list | positional arguments | `$Factory->{$method}(...$value)` |
| map (string keys) | named arguments | `$Factory->{$method}(...$value)` — keys are the native parameter names (`path`, `view`, `views`, `data`, `iterator`, `empty`, `condition`, `mergeData`) |

| Rule | Effect |
|---|---|
| **Reference resolution** | Each *argument position* is tested exactly as a top-level `data` value: a string whose class/function part (the text before the first `@`) contains `\` resolves through `app()->call($value, $parameters)`; a string naming a declared query resolves through `DeclaredQuery::run($value, $parameters)`. Structures nested below an argument position pass untouched — the same one-level rule `data:` applies. |
| **Data injection** | When the dispatched method declares an **optional** `$data` parameter and the call omits it, the seam passes the route's resolved data pool (`array_merge($resolvedData, $routeParameters)` — route parameters win, [declarative-view-data.md](declarative-view-data.md) §2.2) as the named `data:` argument: a positional list injects when it is shorter than the `$data` position, a map injects whenever it carries no `data` key. A **required** `$data` (`renderEach`) must be declared; omitting it fails with PHP's own `ArgumentCountError` (Rule 7). Detection is one cached `ReflectionMethod` per native method — generic machinery, not per-method branches; a registered macro has no `ReflectionMethod`, so it takes the declared arguments verbatim (its `Closure` fills parameters natively, named or positional). |
| **Path rule (`file` only)** | The `$path` argument follows the manifest's path rule ([declarative-view.md](declarative-view.md) §2.2): a leading `/` or `\` is absolute, anything else resolves under `basePath()` — the same `absolute()` the provider applies to `addLocation`, needed because `Factory::file()` resolves relative paths against the process CWD (§1.4.7 there; under `artisan serve`/FPM the CWD is `public/`). |
| **Result contract** | `Illuminate\Contracts\View\View` → `->render()` (creators/composers fire natively, §1.1.5). `string` (`renderEach`, `renderWhen`, `renderUnless`, and the `raw|`/empty branches) → content as-is. Anything else — `bool`, `null`, `void` — → `LogicException` naming the return type. The content wraps exactly as the inline-template path wraps: `ResponseFactory::make($content, $status, $headers)` (`ResponseFactory.php:59`), honoring `setDefaults.status` / `setDefaults.headers`. |
| **Method resolution** | `method_exists($Factory, $method)` or a registered macro (`Factory` is `Macroable`, `Factory.php:16`; `hasMacro()` covers PHP-registered render macros). Anything else → `BadMethodCallException` — the same failure class Laravel's own `Macroable::__call` throws. Signature-driven pool injection applies to native methods only; macros dispatch verbatim. |
| **Exclusivity** | `factory:` must be the only render source: declaring it with `template` or `view` throws `LogicException`. `template` > `view` precedence between those two is unchanged ([declarative-inline-template.md](declarative-inline-template.md)); `deleteCachedView` stays `Blade::render()`-only. |

**Method resolution is guarded, dispatch is not.** `method_exists` is the *gate* (Rule 7: an unknown name fails like Laravel's `__call`), not a whitelist — the gate checks the name the manifest declared, it does not restrict which names may be declared.

### 2.3 Full example

```yaml
view:                                              # ——— Tier 1 epilogue keys (§2.5) ———
  addLocation:                                     # -> addLocation($location), unchanged
    - resources/declared-views
  flushFinderCache: true                           # -> flushFinderCache(): finder cache emptied after the block
  flushState: true                                 # -> flushState(): sections/stacks/fragments reset (workers, tests)

routes:
  - uri: legal/terms                               # Factory::file(): render outside the finder
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    name: legal.terms
    setDefaults:
      status: 200
      headers:
        Cache-Control: private
      data:                                        # the data pool -> injected as `data:` (optional $data omitted)
        title: Terms of Service
      factory:
        file: resources/legal/terms.html           # relative -> basePath(); leading / = absolute
        # map form: file: {path: ..., data: {...}, mergeData: {...}}

  - uri: "users/{user}/posts/cards"                # Factory::renderEach(): one partial per item
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    name: users.posts.cards
    middleware: [web]
    metadata:
      request: post-filters                        # validated before any reference resolves
    setDefaults:
      factory:
        renderEach:                                # named form: keys are the native parameter names
          view: users.partials.post
          data: App\Queries\UserPosts              # reference: items resolve with $user + $request
          iterator: post                           # each partial receives {key, post}
          empty: 'raw|No posts'                    # empty items -> the literal string (native `raw|` rule)

  - uri: fallback-page                             # Factory::first(): the existence guard, explicitly
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    setDefaults:
      data:
        word: hi
      factory:
        first: [[pages.new, pages.old]]            # positional list; optional $data omitted -> the pool
        # none exist -> Laravel's own InvalidArgumentException (Rule 7)
```

```php
namespace App\Queries;

final class UserPosts
{
    /** @return list<array{id: int, title: string}> */
    public function __invoke(User $user, DeclaredRequest $request): array
    {
        return $user->posts()->orderBy($request->validated('sort', 'created_at'))->get()->all();
    }
}
```

`users.partials.post` receives, per item: `share` data < nothing (renderEach passes only `{key, post}`) < `{key, post}` — and its own composers fire per item (§1.1.5). `legal/terms.html` receives the data pool (`title`) plus any route parameters, exactly as `setDefaults.view` would.

### 2.4 Key → method → signature map

| `factory:` key | `Factory` method | Declared forms | Dispatch | Absent/failed → |
|---|---|---|---|---|
| `file` | `file($path, $data = [], $mergeData = [])` | `path` scalar; `[path, data, mergeData]`; `{path, data, mergeData}` | `file($path, ...)` — path through `absolute()`; optional `$data` omitted → the pool | finder-independent; engine missing → `InvalidArgumentException: Engine [x] not found.` |
| `renderEach` | `renderEach($view, $data, $iterator, $empty = 'raw|')` | `[view, data, iterator, empty]`; `{view, data, iterator, empty}` | per-item `make()`; items resolve through the reference pipeline | `$data`/`$iterator` omitted → `ArgumentCountError` |
| `first` | `first(array $views, $data = [], $mergeData = [])` | `[[views], data, mergeData]`; `{views, data, mergeData}` | `exists()` chain; optional `$data` omitted → the pool | none exist → `InvalidArgumentException: None of the views in the given array exist.` |
| `make` | `make($view, $data = [], $mergeData = [])` | `view` scalar; `{view, data, mergeData}` | the `view:` key's native call, addressable explicitly | finder miss → `InvalidArgumentException: View [x] not found.` |
| `renderWhen` | `renderWhen($condition, $view, $data = [], $mergeData = [])` | `[condition, view, data]`; `{condition, view, data, mergeData}` | `false` → `''` (200, empty body); optional `$data` omitted → the pool | — |
| `renderUnless` | `renderUnless($condition, $view, $data = [], $mergeData = [])` | `[condition, view, data]`; `{condition, view, data, mergeData}` | `renderWhen(! $condition, ...)`; `true` → `''` (200, empty body) | — |
| any registered macro | — | declared arguments verbatim (positional or named) | same dispatcher; no pool injection — a macro has no `ReflectionMethod` | unresolvable → `BadMethodCallException` |
| `exists` (and any `bool`/`void` returner) | `exists($view)` | — | dispatched, then rejected by the result contract | `LogicException` naming the return type |

### 2.5 Registration algorithm (the Tier 1 epilogue)

Two properties join `src/View.php` — keys are `Factory` method names (Rule 1), values are the arguments (Rule 2), and `true` is the package-wide no-argument-call convention (`routes.builders`):

```php
public const string flushFinderCache = 'flushFinderCache';

/** `Factory::flushFinderCache()`: `true` empties the finder's resolved-view cache after the block applies */
#[Describe([Describe::default => false])]
public bool $flushFinderCache;

public const string flushState = 'flushState';

/** `Factory::flushState()`: `true` resets renderCount, sections, stacks, components and fragments */
#[Describe([Describe::default => false])]
public bool $flushState;
```

`ViewDeclarationServiceProvider::boot()` dispatches them after the nine pass-through loops, dynamically — one loop, no per-method code:

```php
/** `view:` keys that are no-argument `Factory` calls, applied after the block (the epilogue) */
private const epilogue = ['flushFinderCache', 'flushState'];

// inside the `callAfterResolving('view')` callback, after `composer` / `creator`:
foreach (self::epilogue as $method) {
    if ($Manifest->view->{$method} === true) {
        $Factory->{$method}();                 // dynamic: the method comes from the key
    }
}
```

Order within the epilogue is irrelevant (`flushFinderCache` touches the finder, `flushState` touches render bookkeeping); the epilogue always runs **after** every registration so a flushed cache cannot hide a declared path. The epilogue is a no-op in the common fresh-resolution path (the finder cache is empty, `renderCount` is 0) and only bites where §1.1.2's immediate-run path left state behind. When a host legitimately renders mid-boot, the *boot-safe* sibling `flushStateIfDoneRendering()` remains a PHP call — mapping it as a third key would invite mid-render state destruction; the manifest key targets `flushState()` exactly as the inventory names it.

### 2.6 Notes / non-goals

- **`factory:` is one call, not a pipeline.** Multi-entry maps throw (§2.1). Composition happens in the data (references, queries), not in the render call.
- **No allowlist.** The gate is `method_exists` / `hasMacro` on the name the manifest declared. A `Factory` method that stops existing fails at the first request with `BadMethodCallException` — Laravel's own failure class.
- **`exists` renders nothing, loudly.** A `bool` result hits the result contract's `LogicException`. This is the same posture as `routes.builders`' silent-absorb rule being rejected here: a discarded `bool` would be a silent no-op (Rule 7).
- **Windows paths.** The reference test keys on `\` (declarative-view-data.md §2.2), so `file: C:\templates\x.html` would resolve as a reference and fail with `BindingResolutionException`. Write forward slashes — PHP and Laravel accept them on Windows. Backslash-leading (`\`) or slash-leading (`/`) paths stay absolute by the path rule.
- **Arrayable data.** The `$data` of `make`/`file`/`first`/`renderWhen`/`renderUnless` flows through `Factory::parseData()` (`Factory.php:272`), so a reference returning an `Arrayable` dispatches untouched; YAML literals are maps/lists. `renderEach`'s `$data` is consumed raw — `count()` + `foreach` (`Factory.php:235`) — so lists and Eloquent Collections work and an arbitrary `Arrayable` must be countable/iterable.
- **No `composers` key, no `factory.composers`.** Unchanged from declarative-view.md §2.6.
- **Not a validation layer.** Values pass through as YAML decoded them; failures are Laravel's/PHP's own at the first request (`BadMethodCallException`, `ArgumentCountError`, `InvalidArgumentException`, `ViewException`). The `route` schema keeps `additionalProperties: true` (it already cannot express `setDefaults`' if/then, declarative-view-data.md §2.6); the new keys are documented there and here.
- **Inline templates keep their bridge.** `composing: {routeName}` fires only for `template:` renders. `View`-returning `factory:` dispatches have a real view name — composers fire natively; string-returning dispatches (`renderWhen(false)`, `renderEach` empty) produce content without a view identity and fire nothing, matching Laravel.

---

## 3. Implementation plan

1. **`src/DeclaredView.php`** (whole file, final). The dispatcher is three private methods; every `Factory` method flows through `$Factory->{$method}(...$arguments)`:

   ```php
   <?php

   declare(strict_types=1);

   namespace ZeroToProd\LaravelDeclaration;

   use BadMethodCallException;
   use Illuminate\Contracts\Routing\ResponseFactory;
   use Illuminate\Contracts\View\View as LaravelView;
   use Illuminate\Http\Response;
   use Illuminate\Routing\ViewController;
   use Illuminate\Support\Facades\Blade;
   use Illuminate\Support\Str;
   use Illuminate\View\Factory;
   use LogicException;
   use ReflectionMethod;

   class DeclaredView extends ViewController
   {
       /** @var array<string, ReflectionMethod> */
       private static array $signatures = [];

       /**
        * @param  string  $method
        * @param  array<string, mixed>  $parameters
        */
       public function callAction($method, $parameters): Response
       {
           return $this->{$method}(...$parameters);
       }

       public function __invoke(mixed ...$args): Response
       {
           $args += ['data' => [], 'status' => 200, 'headers' => []];

           $route = request()->route();
           /** @var array<string, mixed> $routeParameters */
           $routeParameters = array_filter($args, static fn (string|int $key): bool => ! in_array(
               $key,
               ['template', 'view', 'data', 'status', 'headers', 'deleteCachedView', 'factory'],
               true
           ), ARRAY_FILTER_USE_KEY);

           /** @var array<string, mixed> $parameters */
           $parameters = [
               ...$routeParameters,
               'request' => $route->getMetadata('request') === null && ! isset($route->defaults['request'])
                   ? request()
                   : app(DeclaredRequest::class),
           ];

           /** @var array<string, mixed> $data */
           $data = $args['data'];
           $Manifest = app(Manifest::class);

           $resolvedData = array_map(
               static fn (mixed $value): mixed => self::resolveReference($value, $parameters, $Manifest),
               $data,
           );

           $mergedData = array_merge($resolvedData, $routeParameters);

           if (isset($args['factory'])) {
               if (! is_array($args['factory'])) {
                   throw new LogicException('The `factory` dispatch must map a Factory method name to its arguments.');
               }

               if (isset($args['template']) || isset($args['view'])) {
                   throw new LogicException("DeclaredView accepts one render source: 'template', 'view' or 'factory'.");
               }

               return $this->renderFactory($args['factory'], $parameters, $Manifest, $mergedData, $args);
           }

           if (isset($args['template']) && is_string($args['template'])) {
               if ($routeName = $route->getName()) {
                   event("composing: $routeName", [$mergedData]);
               }

               $deleteCachedView = ! isset($args['deleteCachedView']) || (bool) $args['deleteCachedView'];
               $content = Blade::render($args['template'], $mergedData, deleteCachedView: $deleteCachedView);

               /** @var ResponseFactory $responseFactory */
               $responseFactory = $this->response;
               $status = is_int($args['status']) || is_string($args['status']) ? (int) $args['status'] : 200;

               return $responseFactory->make($content, $status, (array) $args['headers']);
           }

           if (isset($args['view'])) {
               return parent::__invoke(...[
                   ...$args,
                   'data' => $mergedData,
               ]);
           }

           throw new LogicException(
               "DeclaredView requires either 'template', 'view' or 'factory' to be specified in setDefaults."
           );
       }

       /**
        * The dynamic dispatch: one call per render, the method named by the manifest key.
        *
        * @param  array<string, mixed>  $factory
        * @param  array<string, mixed>  $parameters
        * @param  array<string, mixed>  $args
        */
       private function renderFactory(
           array $factory,
           array $parameters,
           Manifest $Manifest,
           array $mergedData,
           array $args,
       ): Response {
           if (count($factory) !== 1) {
               throw new LogicException('The `factory` dispatch accepts exactly one Factory method per render.');
           }

           $method = (string) array_key_first($factory);
           $Factory = app(Factory::class);

           if (! method_exists($Factory, $method) && ! $Factory->hasMacro($method)) {
               throw new BadMethodCallException(sprintf(
                   'Call to undefined method %s::%s(). The `factory` dispatch targets Illuminate\View\Factory methods.',
                   Factory::class,
                   $method,
               ));
           }

           $arguments = $this->arguments($method, $factory[$method], $parameters, $Manifest, $mergedData);

           /** @var LaravelView|string $result */
           $result = $Factory->{$method}(...$arguments);

           $content = $result instanceof LaravelView
               ? $result->render()
               : (is_string($result) ? $result : throw new LogicException(sprintf(
                   'Factory::%s() returned %s; the `factory` dispatch renders a View or a string.',
                   $method,
                   get_debug_type($result),
               )));

           /** @var ResponseFactory $responseFactory */
           $responseFactory = $this->response;
           $status = is_int($args['status']) || is_string($args['status']) ? (int) $args['status'] : 200;

           return $responseFactory->make($content, $status, (array) $args['headers']);
       }

       /**
        * Rule 2: the declared value is the argument list. `true`/`null` = a no-argument
        * call, a list = positional arguments, a map = named arguments, a scalar = the
        * first argument. Each argument position resolves through the reference pipeline.
        *
        * @param  array<string, mixed>  $parameters
        * @return array<int|string, mixed>
        */
       private function arguments(
           string $method,
           mixed $callArgs,
           array $parameters,
           Manifest $Manifest,
           array $mergedData,
       ): array {
           if ($callArgs === true || $callArgs === null) {
               return [];
           }

           if (! is_array($callArgs)) {
               $callArgs = [$callArgs];
           }

           /** @var array<int|string, mixed> $callArgs */
           $callArgs = array_map(
               static fn (mixed $value): mixed => self::resolveReference($value, $parameters, $Manifest),
               $callArgs,
           );

           if ($method === 'file') {
               $path = $callArgs['path'] ?? $callArgs[0] ?? null;

               if (is_string($path)) {
                   $callArgs[array_key_exists('path', $callArgs) ? 'path' : 0] = $this->absolute($path);
               }
           }

           if (! method_exists(Factory::class, $method)) {
               return $callArgs;                             // a macro: declared arguments verbatim, no pool injection
           }

           $Reflection = self::$signatures[$method] ??= new ReflectionMethod(Factory::class, $method);

           foreach ($Reflection->getParameters() as $position => $Parameter) {
               if ($Parameter->getName() !== 'data') {
                   continue;
               }

               if (array_key_exists('data', $callArgs)
                   || (array_is_list($callArgs) && count($callArgs) > $position)) {
                   break;                                        // the call supplies $data
               }

               if ($Parameter->isDefaultValueAvailable()) {
                   $callArgs['data'] = $mergedData;              // optional: the pool is $data
               }

               break;                                            // required and omitted: PHP's ArgumentCountError
           }

           return $callArgs;
       }

       /**
        * A string whose class or function part (the text before the first `@`) contains `\`
        * is a PHP reference resolved through `Container::call()`; a string naming a declared
        * query resolves through `DeclaredQuery::run()`. Everything else passes through.
        *
        * @param  array<string, mixed>  $parameters
        */
       private static function resolveReference(mixed $value, array $parameters, Manifest $Manifest): mixed
       {
           if (is_string($value) && $Manifest->queries->has($value)) {
               return DeclaredQuery::run($value, $parameters);
           }

           return is_string($value) && str_contains(Str::before($value, '@'), '\\')
               ? app()->call($value, $parameters)
               : $value;
       }

       /** The manifest's path rule: a leading `/` or `\` is absolute, anything else resolves under `basePath()`. */
       private function absolute(string $path): string
       {
           return Str::startsWith($path, ['/', '\\']) ? $path : app()->basePath($path);
       }
   }
   ```

   Departures from the shipped file, each deliberate:
   - **`resolveReference()` extracted.** declarative-view-data.md §2.5 kept the test inline because it had one caller; the `factory:` dispatch is the second caller, so the extraction is now justified. Behavior is identical (query handle first, then the `\`-before-`@` test).
   - **`factory` joins the `$routeParameters` exclusion list**, so it never leaks into `data` or references as a stray parameter.
   - **The `factory` branch runs before `template`/`view` only to enforce exclusivity** (Rule 7); with `factory` absent, every existing behavior — `template` > `view` precedence, the `composing: {routeName}` bridge, `deleteCachedView` — is byte-for-byte unchanged.
   - **`array_key_first()`** picks the single entry; `count($factory) !== 1` fails multi-entry maps.
   - **`hasMacro()`** beside `method_exists()` keeps PHP-registered render macros dispatchable (`Factory` is `Macroable`, `Factory.php:16`).
   - **`arguments()` reflects native methods only.** `new ReflectionMethod(Factory::class, $method)` throws for a macro name, so the signature machinery is guarded by `method_exists()` first — a macro dispatches its declared arguments verbatim. The positional `$data` test is guarded by `array_is_list()`, so a named map that names later parameters (`{view, mergeData}`) still receives the pool as `data:`.

2. **`src/View.php`** — the two epilogue properties (§2.5), appended after `creator` in key order so the epilogue reads last:

   ```php
   public const string flushFinderCache = 'flushFinderCache';

   /** `Factory::flushFinderCache()`: `true` empties the finder's resolved-view cache after the block applies */
   #[Describe([Describe::default => false])]
   public bool $flushFinderCache;

   public const string flushState = 'flushState';

   /** `Factory::flushState()`: `true` resets renderCount, sections, stacks, components and fragments */
   #[Describe([Describe::default => false])]
   public bool $flushState;
   ```

3. **`src/Providers/ViewDeclarationServiceProvider.php`** — the epilogue loop (§2.5) inside the `callAfterResolving('view')` callback, after the `creator` loop:

   ```php
   /** `view:` keys that are no-argument `Factory` calls, applied after the block (the epilogue) */
   private const epilogue = ['flushFinderCache', 'flushState'];

   foreach (self::epilogue as $method) {
       if ($Manifest->view->{$method} === true) {
           $Factory->{$method}();
       }
   }
   ```

4. **`manifest.schema.json`** — the `view` definition gains the two booleans (its `additionalProperties: false` would otherwise reject them at `laravel-declaration:validate`):

   ```json
   "flushFinderCache": {
     "description": "-> flushFinderCache(): true empties the finder's resolved-view cache after the block applies, so declared locations, namespaces and extensions win over finds cached before the provider booted.",
     "type": "boolean"
   },
   "flushState": {
     "description": "-> flushState(): true resets renderCount, renderedOnce, sections, stacks, components and fragments after the block applies (worker and test isolation).",
     "type": "boolean"
   }
   ```

   `setDefaults.factory` is documentation-only (the `route` definition stays `additionalProperties: true`, as every existing `setDefaults` key already is).

5. **Fixtures.**

   `tests/Fixtures/App/View/views/factory-standalone.php` (a `.php` view — the finder is irrelevant, `file()` resolves the engine by extension):

   ```php
   <?= $word ?? 'bare' ?>
   ```

   `tests/Fixtures/App/View/views/factory-echo.blade.php`:

   ```blade
   echo:{{ $word ?? '-' }}
   ```

   `tests/Fixtures/App/View/views/factory-row.blade.php`:

   ```blade
   row:{{ $key }}:{{ $post }}
   ```

   `tests/Fixtures/App/View/Data/Posts.php` (the items reference for `renderEach`):

   ```php
   <?php

   declare(strict_types=1);

   namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Data;

   final class Posts
   {
       /** @return list<string> */
       public function __invoke(): array
       {
           return ['alpha', 'beta'];
       }

       /** @return list<string> */
       public function empty(): array
       {
           return [];
       }
   }
   ```

   `tests/Fixtures/App/Application/ViewWarmProvider.php` — warms the finder cache and render bookkeeping *before* the declaration block applies — §1.1.2's host that resolved and rendered `view` during boot — gated so it is inert for every other test:

   ```php
   <?php

   declare(strict_types=1);

   namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application;

   use Illuminate\Support\ServiceProvider;

   final class ViewWarmProvider extends ServiceProvider
   {
       public function boot(): void
       {
           if (! config('laravel-declaration.warm-views', false)) {
               return;
           }

           app('view')->addLocation(base_path('resources/declared-views')); // the skeleton's default path is resources/views
           view('greeting')->render();          // resolves `view` now; the finder caches greeting -> declared-views ("base\n")
           app('view')->startSection('banner'); // render bookkeeping for flushState
           app('view')->stopSection();
       }
   }
   ```

   `tests/TestCase.php::getPackageProviders()` prepends it, before `LaravelDeclarationProvider`, so its boot runs first (comment added):

   ```php
   return [
       McpServiceProvider::class,
       // Warms `view` only when config('laravel-declaration.warm-views') is set, so the
       // declaration block's epilogue flushes genuinely stale state (§1.1.2's immediate run).
       ViewWarmProvider::class,
       LaravelDeclarationProvider::class,
       EventSpyProvider::class,
   ];
   ```

6. **`tests/Feature/DeclaredViewFactoryTest.php`** (new, complete) — the temp-manifest pattern of `DeclaredViewTest.php`:

   ```php
   <?php

   declare(strict_types=1);

   use BadMethodCallException;
   use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Data\Posts;

   it('renders a file outside the finder with the data pool, status and headers', function (): void {
       $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
           routes:
             - uri: legal/terms
               methods: GET
               action: ZeroToProd\LaravelDeclaration\DeclaredView
               setDefaults:
                 status: 200
                 headers:
                   Cache-Control: private
                 data:
                   word: From Pool
                 factory:
                   file: resources/declared-views/factory-standalone.php
           YAML)]);

       $this->get('/legal/terms')
           ->assertOk()
           ->assertHeader('Cache-Control', 'private')
           ->assertSeeText('From Pool');
   });

   it('renders a partial per item through a resolved reference', function (): void {
       $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
           view:
             addLocation: [resources/declared-views]

           routes:
             - uri: posts/cards
               methods: GET
               action: ZeroToProd\LaravelDeclaration\DeclaredView
               setDefaults:
                 factory:
                   renderEach: [factory-row, ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Data\Posts, post]
           YAML)]);

       $this->get('/posts/cards')
           ->assertOk()
           ->assertSee('row:0:alpha')
           ->assertSee('row:1:beta');
   });

   it('renders the raw empty branch when the items resolve to an empty list', function (): void {
       $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
           view:
             addLocation: [resources/declared-views]

           routes:
             - uri: posts/empty
               methods: GET
               action: ZeroToProd\LaravelDeclaration\DeclaredView
               setDefaults:
                 factory:
                   renderEach: [factory-row, ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Data\Posts@empty, post, 'raw|No rows']
           YAML)]);

       $this->get('/posts/empty')->assertOk()->assertSeeText('No rows');
   });

   it('renders conditionally through renderWhen with the data pool', function (): void {
       $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
           view:
             addLocation: [resources/declared-views]

           routes:
             - uri: when
               methods: GET
               action: ZeroToProd\LaravelDeclaration\DeclaredView
               setDefaults:
                 data:
                   word: hi
                 factory:
                   renderWhen: [true, factory-echo]
           YAML)]);

       $this->get('/when')->assertOk()->assertSeeText('echo:hi');
   });

   it('renders an empty body when the renderUnless condition holds', function (): void {
       $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
           view:
             addLocation: [resources/declared-views]

           routes:
             - uri: unless
               methods: GET
               action: ZeroToProd\LaravelDeclaration\DeclaredView
               setDefaults:
                 factory:
                   renderUnless: [true, factory-echo]
           YAML)]);

       $this->get('/unless')->assertOk()->assertContent('');
   });

   it('renders the first existing view of a chain and fails with Laravel's own exception', function (): void {
       $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
           view:
             addLocation: [resources/declared-views]

           routes:
             - uri: first
               methods: GET
               action: ZeroToProd\LaravelDeclaration\DeclaredView
               setDefaults:
                 data:
                   word: chained
                 factory:
                   first: [[pages.missing, factory-echo]]
           YAML)]);

       $this->get('/first')->assertOk()->assertSeeText('echo:chained');
   });

   it('makes a view explicitly, identical to the view key', function (): void {
       $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
           view:
             addLocation: [resources/declared-views]

           routes:
             - uri: made
               methods: GET
               action: ZeroToProd\LaravelDeclaration\DeclaredView
               setDefaults:
                 data:
                   word: made
                 factory:
                   make: factory-echo
           YAML)]);

       $this->get('/made')->assertOk()->assertSeeText('echo:made');
   });

   it('fails an unknown Factory method with BadMethodCallException', function (): void {
       $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
           routes:
             - uri: typo
               methods: GET
               action: ZeroToProd\LaravelDeclaration\DeclaredView
               setDefaults:
                 factory:
                   mak: factory-echo
           YAML)]);

       $this->withoutExceptionHandling();
       $this->get('/typo');
   })->throws(BadMethodCallException::class, 'Call to undefined method Illuminate\View\Factory::mak().');

   it('fails a bool-returning dispatch loudly instead of rendering nothing', function (): void {
       $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
           view:
             addLocation: [resources/declared-views]

           routes:
             - uri: exists
               methods: GET
               action: ZeroToProd\LaravelDeclaration\DeclaredView
               setDefaults:
                 factory:
                   exists: factory-echo
           YAML)]);

       $this->withoutExceptionHandling();
       $this->get('/exists');
   })->throws(LogicException::class, 'Factory::exists() returned bool; the `factory` dispatch renders a View or a string.');

   it('fails a multi-entry factory map', function (): void {
       $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
           routes:
             - uri: twice
               methods: GET
               action: ZeroToProd\LaravelDeclaration\DeclaredView
               setDefaults:
                 factory:
                   exists: factory-echo
                   make: factory-echo
           YAML)]);

       $this->withoutExceptionHandling();
       $this->get('/twice');
   })->throws(LogicException::class, 'The `factory` dispatch accepts exactly one Factory method per render.');

   it('fails a factory declared beside another render source', function (): void {
       $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
           routes:
             - uri: both
               methods: GET
               action: ZeroToProd\LaravelDeclaration\DeclaredView
               setDefaults:
                 view: factory-echo
                 factory:
                   make: factory-echo
           YAML)]);

       $this->withoutExceptionHandling();
       $this->get('/both');
   })->throws(LogicException::class, "DeclaredView accepts one render source: 'template', 'view' or 'factory'.");
   ```

   The `$this->manifest(<<<'YAML' ...)` helper is the temp-file pattern of `DeclaredViewTest.php` extracted once (`file_put_contents(tempnam(...).'.yml', $yaml)`, return the path, `unlink` in `finally`); implement it in the test file's top or on `TestCase` and reuse it in both files — each test wires it with `withConfig(['laravel-declaration.manifest' => ...])`. Every temp manifest that renders a view *name* also declares `view.addLocation: [resources/declared-views]` (the fixtures are copied there; the skeleton's default finder path is `resources/views`).

7. **`tests/Feature/ViewRegistrationTest.php`** — the epilogue pair, behavioral through §1.1.2's immediate-run path (warm first, block second):

   ```php
   it('flushes stale finder entries when the factory resolved before the block applied', function (): void {
       $this->withConfig([
           'laravel-declaration.manifest' => $this->manifest(<<<'YAML'
               view:
                 prependLocation:
                   - resources/declared-views/theme
                 flushFinderCache: true
               YAML),
           'laravel-declaration.warm-views' => true,
       ]);

       expect(view('greeting')->render())->toBe("theme\n");   // re-found: the prepended path wins
   });

   it('keeps stale finder entries without flushFinderCache', function (): void {
       $this->withConfig([
           'laravel-declaration.manifest' => $this->manifest(<<<'YAML'
               view:
                 prependLocation:
                   - resources/declared-views/theme
               YAML),
           'laravel-declaration.warm-views' => true,
       ]);

       expect(view('greeting')->render())->toBe("base\n");    // the cached find short-circuits the finder
   });

   it('resets render bookkeeping when flushState is declared', function (): void {
       $this->withConfig([
           'laravel-declaration.manifest' => $this->manifest(<<<'YAML'
               view:
                 flushState: true
               YAML),
           'laravel-declaration.warm-views' => true,
       ]);

       expect(app('view')->getSections())->toBe([]);
   });

   it('keeps render bookkeeping without flushState', function (): void {
       $this->withConfig([
           'laravel-declaration.manifest' => $this->manifest('view: {}'),
           'laravel-declaration.warm-views' => true,
       ]);

       expect(app('view')->getSections())->toBe(['banner' => '']);
   });
   ```

8. **Documentation updates** (apply after the code lands):
   - [declarative-tier1-gap-inventory.md](declarative-tier1-gap-inventory.md): §1 row 4 → `[x]` ("render-time surface dispatched via `DeclaredView` `factory:`; epilogue flushes mapped"); §2.3 → resolved with a pointer here; §4 item 6 → done.
   - [declarative-framework-api-mapping.md](declarative-framework-api-mapping.md): lines 68, 256, 327 — the "missing `exists()`, `file()`, `make()`, `renderEach()`, `flushFinderCache()`" gap closes; status → `[x]` with the render-time surface attributed to the Tier 2 seam.
   - [declarative-view.md](declarative-view.md) §1.3: move `make`/`file`/`first`/`exists`/`renderWhen`/`renderUnless`/`renderEach` from "Not declaration targets — runtime rendering" to a pointer (Tier 2 seam, §2 here); move `flushFinderCache`/`flushState` from "render bookkeeping" to the §2.5 epilogue keys; §2.4 gains the two boolean rows.
   - [declarative-view-data.md](declarative-view-data.md) §2.4: add the `factory` row to the `setDefaults` key map; §2.6 notes the exclusivity rule.
   - [declarative-inline-template.md](declarative-inline-template.md): note `template` and `factory` are mutually exclusive render sources.
   - `README.md` `## Manifest`: add the two `view` epilogue keys to the key list and one `factory:` line to the routes section.

---

## 4. Verification matrix

| Rule | How this specification satisfies it |
|---|---|
| 1 — key = method name | `factory.file` → `Factory::file()`; `view.flushFinderCache` → `Factory::flushFinderCache()`. No invented verbs; `exists`' rejection is a semantic decision, not a renamed verb. |
| 2 — map = one call per entry; value = the argument(s) | One entry per render; list = positional, map = named arguments, scalar = first argument, `true` = no-argument call. Nothing is looped, flipped or re-keyed. |
| 3 — pass references through when Laravel accepts strings | References resolve only where Laravel cannot (`renderEach`'s `array $data`, `first`'s `array $views`); view names and paths pass through verbatim, modulo the `file` path rule. |
| 4 — wrap only where Laravel needs a Closure | No Closures anywhere: references go through `Container::call()` with named parameters (`$parameters`), exactly as `data:` does. |
| 5 — one seam class per Laravel base class | `DeclaredView extends ViewController` gains no sibling; the epilogue stays in `ViewDeclarationServiceProvider`. |
| 6 — `route:cache` / `config:cache` safe | `factory:` values are strings, lists, maps — route defaults. |
| 7 — fail at the same place Laravel fails | Unknown method → `BadMethodCallException`; missing required argument → `ArgumentCountError`; missing view → the finder's `InvalidArgumentException`; non-renderable result → `LogicException` naming the type; no silent discards anywhere. |
| Dynamic dispatch | `$Factory->{$method}(...$arguments)` is the *only* call site; `$Factory->{$method}()` in the epilogue. Zero per-method branches — `first`, `file`, `make`, `renderEach`, `renderWhen`, `renderUnless` and future `Factory` methods are manifest data. |

Coverage gate: every new line is reachable from the §3 tests (the dispatcher's happy paths, both `method_exists` outcomes, both data-injection outcomes, all three result classes, both epilogue branches), preserving the Definition-of-Done §6 100% gate (see the inventory §5.6 regression note — this work must not ship without the guard-line feature test that restores it).

---

### Sources

- `vendor/laravel/framework/src/Illuminate/View/Factory.php:120, 131, 148, 174, 196, 214, 228, 235, 247, 272, 296, 507, 523, 576` — verified v13.33.0 signatures, `share('__env', $this)`, `renderEach`'s `raw|` default, raw `count()`/`foreach` consumption and per-item `make()`, `exists`' catch of the finder's `InvalidArgumentException`, `flushState`'s reset list, `flushFinderCache` → `getFinder()->flush()`, `parseData()`'s `Arrayable` → array conversion.
- `vendor/laravel/framework/src/Illuminate/View/Concerns/ManagesEvents.php:18, 35, 53` — `creator()`, `composers()` (the batch form), `composer()`.
- `vendor/laravel/framework/src/Illuminate/Routing/Route.php:651` — `setDefaults()`; the package captures `setDefaults` as a builder (`src/Route.php:31`, `extractBuilders()`) and applies it through `RoutesDeclarationServiceProvider::applyBuilder()`'s generic tail.
- `vendor/laravel/framework/src/Illuminate/View/FileViewFinder.php:29, 264` — `$views` cache, `flush()`.
- `vendor/laravel/framework/src/Illuminate/Routing/ResponseFactory.php:59, 85-92` — `make()`; `view()`'s `is_array($view)` → `Factory::first()` branch.
- `vendor/laravel/framework/src/Illuminate/Routing/ViewController.php:32, 55` — `__invoke()`'s filter and `ResponseFactory::view()` delegation.
- `vendor/laravel/framework/src/Illuminate/Foundation/Application.php:1685` — the `view` alias binds `Factory::class`, so `app(Factory::class)` resolves the shared instance.
- `vendor/laravel/framework/src/Illuminate/View/View.php:157, 182` — `render()`, `renderContents()` → `callComposer()`.
- `vendor/laravel/framework/src/Illuminate/Contracts/View/View.php` + `Contracts/Support/Renderable.php:12` — the `View` contract extends `Renderable`.
- `docs/declarative-view.md` §1.1, §1.3, §1.4, §2.2, §2.5, §2.6 — the Tier 1 block, the CWD path pitfall, `absolute()`, non-targets.
- `docs/declarative-view-data.md` §2.1, §2.2, §2.5, §2.6 — the `setDefaults` seam, the reference test, data precedence, the schema's if/then limitation.
- `docs/declarative-inline-template.md` — the `template` source, the `composing: {routeName}` bridge, `deleteCachedView`.
- `docs/declarative-tier1-gap-inventory.md` §1 row 4, §2.3, §4 item 6, §5.3, §5.6 — the resolved issue, the verified signatures, the coverage regression context.
