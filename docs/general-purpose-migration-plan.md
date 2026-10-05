# General-Purpose Manifest Migration Plan

This package becomes a **general-purpose manifest engine**: deterministic tooling projects an
**arbitrary list of classes** into `manifest.schema.json`, the schema validates the manifest, and
one runtime maps the manifest back onto the very methods the schema was projected from. Because
generation and mapping are the two directions of the same projection, the whole project reduces
to **one ordered decision list** (§1). The **manifest is the single source of truth**: its root
receiver is the `Illuminate\Foundation\Application` instance, every root key is one of its methods,
and timing, lookup and composition are written as the lifecycle calls Laravel already has
(`make`, `afterResolving`, `registered`, `booting`, `booted`). There is **no block map and no
dispatch configuration**; the package config keeps only the manifest path and the MCP switch.
Every existing per-component code path, attribute, typed mirror and hand-written shape is retired
by that list; the breaks it causes are enumerated (§4).

This revision **supersedes the previous revisions of this file end to end**, the per-component
scaffolding flow in [declarative-manifest-schema-generator.md](declarative-manifest-schema-generator.md),
and the run order / re-merge gate of [generate-manifest-schema-plan.md](generate-manifest-schema-plan.md)
(its §4.1 rule — definition key = FQCN verbatim — is **restored**, see §0 row 7). Only this document
is changed by this revision; no code is implemented here.

## 0. Validation of the previous revisions (line by line)

Every claim below was checked against the working tree at `417f32c` and `vendor/laravel/framework`.
Rows marked **core** changed the design; the rest are inventory corrections. Rows 1–21 validate
the first revision; row 22 validates the second.

| # | Previous revision | Claim | Finding (evidence) | Consequence |
| --- | --- | --- | --- | --- |
| 1 | §0, §2, §3 V10 | 36 attribute files; the dispatch set is `Key, Binding, Setter, Append, AppendTo, PrependTo` | 34 files. `Prepend` exists and has 3 consumers ([src/Kernel.php](../src/Kernel.php) `prependMiddleware`, `prependToMiddlewarePriority`; [KernelDeclarationServiceProvider.php](../src/Providers/KernelDeclarationServiceProvider.php) line 54) and is absent from every shape table of the previous revision — the 1-param reversed fan-out was uncovered | `order: reverse` is a per-key curation flag that applies to **every** fan-out form (§1 row 5 and 6), not a shape of its own |
| 2 | §0, Phase 5 | 23 declaration classes, listed for deletion | 24: `src/TableRename.php` is missing from the list; `src/Internal/Builder.php` (an attribute with zero consumers) is missing from the inventory | both added to the delete list (§5 Phase 2) |
| 3 | §2 | 10 attributes have zero consumers "verified by grep" | zero consumers in `src/`; five are still imported by [tests/Feature/SchemaRegistrationTest.php](../tests/Feature/SchemaRegistrationTest.php) lines 10–14 | the test file is retargeted in Phase 3, the imports go with it |
| 4 **core** | §4.1, V5, Phase 4 `continuation()`, Open item 4, Phase 6 `BlueprintPolicy` | continuation is derivable because "the native method's **declared** return type is an object"; `BlueprintPolicy` takes "the return kind from the parser backend's `Signature`" | **no method in scope declares a return type**: `Router::addRoute/group/resource`, `Route::name/where/can/block`, `Container::when`, `Builder::create/table`, `Blueprint::string/foreignId/index/foreign/timestamps`, `Pending*::*` — all docblock-only (`vendor/.../Routing/Router.php` 472, 554; `Routing/Route.php` 1107, 1362; `Container/Container.php` 211; `Database/Schema/Blueprint.php` 686, 752, 861, 1048). The previous revision's `Signature` carried no return information at all | continuation is discovered **at runtime** from the returned object (`get_class`), never at generation time (§1 rule 4). No `@return` parsing anywhere |
| 5 **core** | Phase 1 `ShapeClassifier`, Phase 4 retirement table | the classifier replaces the six `RoutesDeclarationServiceProvider` special cases | `Route::can($ability, $models = [])` and `Route::block($lockSeconds, $waitSeconds)` are 2-param, untyped-first → the classifier emits **`binding`** → `can('ability', 'view')`. The revision never addressed the entry-vs-row ambiguity that created those special cases | the value's shape discriminates deterministically (§1 rows 6 vs 9: a map whose first key is a parameter name is a **row**); the schema encodes the same discriminator |
| 6 **core** | §4.1 `setter`, Phase 4 `dispatch()` | `null` value = no call | `tests/Fixtures/manifest/schema.yml`: `id: ~`, `timestamps: ~`, `rememberToken: ~` must **call**. `Blueprint::timestamps($precision = null)` is 1-param → `setter` → silently dropped by the previous mapper | a present key always calls; `null`/`true` = call with no arguments (§1 row 1). Absence is the only opt-out (STYLE 6.2 is about **missing** keys) |
| 7 **core** | §4.4 | definitions must be keyed by block name because `router` and `routes` both project `Router` | the split existed only because the old attribute set fixed one shape per declaration class. With shapes derived **per method**, one `Illuminate\Routing\Router` definition carries `pattern` (entries) and `addRoute` (rows) side by side; the `routes` block is the `router` block | FQCN keying restored (the only keying the runtime continuation lookup can use — the previous revision already keyed continuations by FQCN, i.e. two keying rules) |
| 8 **core** | §4.1 `chain`, `construct`, `recurse`; §4.3 `shapes` | three extra shapes + a block-map `shapes` override table | `when($c)->needs()->give()`, `addRoute(...)->name()`, `foreignId()->constrained()->cascadeOnDelete()` are **one** PHP form (a fluent chain on the return); `create($t, Closure)` and `group($attrs, $routes)` are **one** form (a map under a Closure parameter is a body on the closure's argument) | two composition forms, `body` and `chain` (§1 rule 4); no `shapes` table; one curated flag `closure: [param]` for the untyped `$routes` |
| 9 | Phase 4 `namedCall()` | "`method(name: value, …)`" | the code `ksort`s and `array_values` the arguments — positional holes when an optional middle parameter is omitted (`view: {uri, view, headers}` misaligns `headers` into `$data`) | rows call with **named arguments** (PHP 8 string-keyed spread) |
| 10 | Phase 4 `ManifestServiceProvider` | "`Manifest.php` still exists — a pure swap" | the provider imports `Internal\ManifestStore`, which Phase 5 creates | runtime, store and typed-mirror deletion are one cut (§5 Phase 2) |
| 11 | Phase 5 `ManifestStore::item()` | `item(string $block, string $key)` | calls `items($block, $key)[$key]` — the lookup value is used as the field name | fixed signature `item(string $block, string $field, string $value)` |
| 12 | Phase 2 `--check` | "fail on drift" | the drift report's `native-only` section is permanent (vendor methods nobody curated) → `--check` could never pass | generation is **total** (every declarable method is projected); `--check` = regenerate and compare bytes |
| 13 | Phase 6 `BlueprintPolicy::for()` | guards derived from the signature | it only ever emits `column-*`; index and foreign-key guards (`dropIndex`, `dropForeign`, `index`, `foreign` in `schema-alter.yml`) vanish; `Registry` lists kinds the policy never emits | guards are a **data table keyed by method-name family** evaluated by the migrate command (§2.4) — not engine behavior |
| 14 | §4.3 block map | `static: true` per block | `ClassMethod::isStatic()` is in the signature (`AbstractPaginator`: 15 public statics; `Macroable::macro` is static) | `static` is per-key signature metadata; the block only says how the receiver is obtained (`make`) |
| 15 | §4.3 `providers` items mode, `config` mode | two extra modes with a fixed `call` and a key-prefixing transform | `Application::register($provider, $force = false)` and `Repository::set($key, $value = null)` are ordinary methods: `app.register` (entries or list) and `config.set` (entries with dotted keys, Laravel's own `set` semantics) | modes collapse to **projected** vs **data** blocks; `Arr::prependKeysWith` is retired |
| 16 | §2 Resolver `path.base` | "verified at implementation" | `Application::bindPathsInContainer()` binds `path.base` (`Foundation/Application.php` line 425) | verified |
| 17 | §4.3 timings | per block | all five `after-resolving` ids and the `app` `registered` timing match today's providers | kept |
| 18 | V6 line citations | App 118–155, Blade 19–40 | App's copies are at 126–191, Blade's at 21–46 | cosmetic |
| 19 | §7 Phase 7 | composer alignment | `composer.json` `extra.laravel.providers` lists `"ManifestServiceProvider2"` — not a class that exists | fixed in Phase 4 (`LaravelDeclarationProvider`) |
| 20 | §2 | "MCP `Api.php` is already general" | `src/Internal/Mcp/Tools/Api.php` exists; its generality is not load-bearing for this plan | out of scope, unverified |
| 21 **core** | §2 (SchemaGenerator row), Phase 2 | the parser engine "is general in principle" and can regenerate the five FQCN definitions | `flatten()` inlines own methods and traits only — no `extends` handling ([SchemaGenerator.php](../src/Internal/SchemaGenerator.php) lines 220–300; test "excludes parent methods from the projection"). The shipped `Illuminate\Foundation\Application` definition carries `bind`, `singleton`, `when`, … which are declared on `Illuminate\Container\Container` (`vendor/.../Container/Container.php` 211, 359, 502); `Paginator::useBootstrap` is declared on `AbstractPaginator`. The current engine cannot reproduce the file it is supposed to maintain | Σ is hierarchy-inclusive (own → traits → parents, own wins); the parent-exclusion test is retired |
| 22 **core** | second revision §2.1 | a `config('laravel-declaration.blocks')` block map supplies `class`, `make`, `timing` per block | every field is an `Application` method: `make($abstract)` is the lookup, `afterResolving($abstract, Closure)` / `registered` / `booting` / `booted` are the timings, and the FQCN key is the class. `afterResolving` matches **by object type** (`Container::fireAfterResolvingCallbacks` → `getCallbacksForType`), so a concrete FQCN key fires for a contract-bound service such as the HTTP kernel. The block map re-encoded, outside the manifest, calls the manifest can make itself | the block map is deleted; the root receiver is `$this->app`; §2.1 |

## 1. The core

```php
<?php
/**
 * THE CORE — one ordered decision list. Read top to bottom. Nothing in the package may branch on a
 * component, a method name, an attribute, or a typed class. Generation and runtime are two callers
 * of the same table: schema() EMITS every form a key accepts; calls() PARSES the one form a value takes.
 * Both are derived from Σ (the signature) and nothing else.
 */
final class Engine
{
    // ═══ 0. ROOT  — the manifest is a body on the Application; nothing else is configured ═══════════════════════
    //   provider register():   $engine->body($app, $manifest)                       the ONLY call site at boot
    //   root keys            = Application methods (Σ(Illuminate\Foundation\Application), parents included: Container)
    //   timing               = the manifest's own lifecycle calls:  top level → register();  registered/booting/booted: {…} → that hook;
    //                          afterResolving: {FQCN: {…}} → when FQCN resolves (by type);  make: {FQCN: {…}} → now, on the singleton
    //   two exceptions, both schema data (x-manifest), never config:
    //     static receiver    a root key that is a projected FQCN whose Σ is all-static (Paginator) → body(FQCN, value) statically
    //     data key           a curated root key flagged x-manifest.data (requests, models, queries, schema, extra) → stored, never dispatched

    // ═══ 1. SIGNATURE  Σ(class) — PHP-Parser at generation time; Reflection is the test oracle ═══════════════
    //   Σ(class) : ordered map  method → { static: bool, params: list<{ name, type, variadic }> }
    //   type     ∈ { array, closure (Closure|callable), string, int, float, bool, null (untyped / class / mixed / union) }
    //   order    : own ClassMethod nodes in source order, then traits in TraitUse order (today's flatten()), then the
    //              parent chain the same way, recursively — own wins (= ReflectionClass::getMethods() order, parents included)
    //   gate     : public ∧ not __* ∧ not @internal ∧ no by-ref param     — zero-param methods ARE declarable (row 1)
    //   unknown  : a key with no Σ entry (a __call surface such as Fluent) is dispatched as  (...$arguments)  — PHP decides
    //   no docblock is ever read: not @return, not @method, not @param

    // ═══ 2. FORMS   Σ[m] × value → list<(args, rest)> — total order, FIRST MATCH WINS ═══════════════════════════
    //   m = method, P = Σ[m].params, n = |P|, p0/p1 = first/second param, names(P) = parameter names,
    //   k ∈ keys(value), v = value[k], ρ = resolve (§5), λ = closure body (§4), rest = keys that ride the RETURN (§4 chain)
    //
    //   #  value                               condition                                  calls
    //   1  null | true                         —                                          m()
    //   2  scalar (string|int|float|false)     —                                          m(ρ(value, p0))
    //   3  list                                p0.type = array  ∨  x-manifest.list = argument   m(value)                 the list IS the argument
    //   4  list                                p0.variadic                                m(...ρ(item, p0) for item)
    //   5  list                                otherwise                                  one call per item (x-manifest.order):
    //                                                                                        item is a map  → row(item)      (row 9)
    //                                                                                        item otherwise → m(ρ(item, p0))
    //   6  map                                 first k ∉ names(P) ∧ x-manifest.form = entries   m(k) with rest = v        Container::when, Container::make
    //   7  map                                 first k ∉ names(P) ∧ n ≥ 2 ∧ p0.type ≠ array   ENTRIES — per (k, v) (x-manifest.order on lists):
    //                                                                                        v list ∧ p1.type ≠ array → m(k, ρ(i, p1)) per i ∈ v
    //                                                                                        v map  ∧ p1 is closure   → m(k, λ(v))     Container::afterResolving
    //                                                                                        otherwise                → m(k, ρ(v, p1))
    //   8  map                                 first k ∉ names(P) ∧ p0 is closure          m(λ(value))                Application::registered/booting/booted
    //   9  map                                 first k ∉ names(P), otherwise               m(value)                   the map IS the argument
    //  10  map                                 first k ∈ names(P)                          ROW — m(...named) where
    //                                                                                        named[k] = p is closure ∧ v is map ? λ(v) : ρ(v, p)   for k ∈ names(P)
    //                                                                                        rest     = { k: v | k ∉ names(P) }                     in manifest order
    //
    //   "p is closure" = p.type = closure ∨ p.name ∈ x-manifest.closure.
    //   The only per-key curation the forms admit, all data, all preserved by re-merge:
    //     form: entries · list: argument · order: reverse · closure: [param…] · resolve: <vocabulary> · data: true (root keys only)

    // ═══ 3. SCHEMA  (emit) — definitions.<FQCN>.properties[m] = anyOf of the rows m admits ═════════════════════
    //   row 1 → {type: "null"} , {const: true}            row 2 → τ(p0)                     row 3/4 → {type: array, items: τ(p0)}
    //   row 5 → {type: array, items: anyOf[τ(p0), ROW]}   row 9 → {type: object}            row 6 → {type: object, additionalProperties: {type: object}}
    //   row 7 → {type: object, propertyNames: {not: {enum: names(P)}}, additionalProperties: anyOf[τ(p1), {type: array, items: τ(p1)}]}
    //           p1 is closure → properties: {<every projected FQCN>: {$ref: #/definitions/<FQCN>}}, additionalProperties: {type: object}
    //   row 8 → {$ref: #/definitions/<the receiver's own FQCN>}   (a lifecycle hook's body runs on the same Application)
    //   row 10 → ROW = {type: object, properties: {p.name: τ(p)}, required: [P[0].name], additionalProperties: true}   (rest validates at runtime, §4)
    //   τ(p)  = p.type → JSON type; null → true (+ TODO(m: $p) in the stub description); resolve set → {"$ref": "#/definitions/<vocabulary>"}
    //   root  = {$ref: #/definitions/Illuminate\Foundation\Application} ∪ curated data keys ∪ static-receiver FQCN keys;  x-manifest.classes = the projected list
    //   Every key carries  "x-manifest": { static, params, …curation }  — the runtime recomputes forms from it and NEVER re-reads vendor.
    //   Curated description prose, pattern, x-manifest keys survive regeneration byte-for-byte; the structural shape is always regenerated.

    // ═══ 4. APPLY  (runtime) — the two composition forms PHP has ══════════════════════════════════════════════════
    public function body(object|string $t, array $data): void                    // $t->a(); $t->b();  — the root, or a closure body
    {
        foreach ($data as $key => $value) {                                      // manifest order = call order (YAML maps are ordered)
            $meta = $this->def($t)[$key] ?? null;                                // the key schema: x-manifest = { static, params, …curation }

            if ($meta['x-manifest']['data'] ?? false) {
                continue;                                                        // a data key: stored by ManifestStore, consumed by a seam or a command
            }

            if ($meta === null && $this->isStaticReceiver($key)) {
                $this->body($key, $value);                                       // a projected all-static FQCN as a key (Paginator::useBootstrap)

                continue;
            }

            foreach ($this->calls($meta ?? self::VARIADIC, $value) as [$args, $rest]) {
                if (! ($this->guard)($t, $key, $args)) {
                    continue;                                                    // optional command hook (§2.4); identity for the provider
                }

                $static = is_string($t) || ($meta['x-manifest']['static'] ?? false);
                $r = $static ? $t::{$key}(...$args) : $t->{$key}(...$args);     // native failure propagates (STYLE 3.3)

                if ($rest !== []) {
                    $this->chain($r, $rest);                                     // extra keys ride the return value
                }
            }
        }
    }

    private function chain(mixed $t, array $rest): void                          // $t->a()->b()  — the return threads to the next key
    {
        foreach ($rest as $key => $value) {
            foreach ($this->calls($this->def($t)[$key] ?? self::VARIADIC, $value) as [$args, $deeper]) {
                $r = $t->{$key}(...$args);                                       // foreignId()->constrained()->cascadeOnDelete(): the return is the next receiver

                if ($deeper !== []) {
                    $this->chain($r, $deeper);
                }

                $t = is_object($r) ? $r : $t;                                    // void/scalar returns keep the receiver (Route::name() returns $this anyway)
            }
        }
    }

    private function λ(array $data): Closure                                     // a map under a closure parameter = a body on the closure's argument
    {
        return function (object $o) use ($data): void {
            $this->body($o, $data);                                              // afterResolving(FQCN, λ) → the resolved service;  create($t, λ) → Blueprint;  booted(λ) → the app
        };
    }

    private function def(object|string $t): array                                // FQCN-keyed definitions; parent walk covers unprojected subclasses; [] = unknown receiver
    {
        for ($c = is_object($t) ? $t::class : $t; $c !== false; $c = get_parent_class($c)) {
            if (isset($this->schema['definitions'][$c]['properties'])) {
                return $this->schema['definitions'][$c]['properties'];
            }
        }

        return [];
    }

    private function isStaticReceiver(string $key): bool                         // a projected definition whose every key is static
    {
        $keys = $this->schema['definitions'][$key]['properties'] ?? null;

        return is_array($keys) && $keys !== [] && array_all($keys, static fn (array $k): bool => $k['x-manifest']['static'] ?? false);
    }

    // ═══ 5. RESOLVE  ρ(value, p) — a manifest value becomes an argument; vocabulary names = the shared definitions ═══
    //   (none)    value untouched                                                     default when p.type ≠ closure
    //   closure   "Class@method" | "Class::method" | "function" → fn (...$a) => $container->call($ref, $a);  "*.php" → require once, must return Closure
    //   phpFile   "*.php" → require once, its return value (any type)
    //   concrete  "~" → null (self-binding);  "*.php" → phpFile;  else untouched
    //   path      relative → base_path($value);  absolute untouched
    //   default   p.type = closure → closure;  otherwise none;  curated per key with x-manifest.resolve
    //   VARIADIC  = { x-manifest: { static: false, params: [{ name: 'arguments', type: null, variadic: true }] } }   — the unknown-key signature (row 4 / 9)
}
```

Why this is the whole project: the manifest is PHP written as data against the one object a
Laravel boot hands you, the application. Rows 1–10 are the grammar of a PHP call site (`m()`,
`m($x)`, `m(...$xs)`, `m($k, $v)` per entry, `m(a: …, b: …)`); `body` and `chain` are PHP's two
ways to compose calls (statements on one receiver, fluent on the return); `λ` is PHP's closure;
the lifecycle is the application's own hook methods. No rule names a Laravel class except the root.

## 2. Data the core consumes (closed sets)

### 2.1 The manifest's root — no block map, no dispatch config

The root receiver is `$this->app`, handed to `body()` once in `ManifestServiceProvider::register()`.
What the previous revision kept in `config('laravel-declaration.blocks')` is written in the manifest
as the `Application` call it always was:

| Previous block-map field | Manifest form | Why it is not configuration |
| --- | --- | --- |
| `class` | the key under `make:` / `afterResolving:` is the FQCN | `Container::make($abstract)` and `afterResolving($abstract, …)` take it natively; the schema `$ref`s `definitions.<FQCN>` for the body |
| `make: <binding id>` | `make: {Illuminate\Config\Repository: {set: {…}}}` | `Repository::class`, `Router::class`, `Factory::class`, `BladeCompiler::class`, `Connection::class`, `Schema\Builder::class` are core container aliases (`Application::registerCoreContainerAliases`); `make` returns the singleton and the body rides the return (`form: entries`) |
| `timing: register` | top-level keys | the root body runs in `register()` |
| `timing: registered` / `booting` / `booted` | `registered: {…}` / `booting: {…}` / `booted: {…}` | row 8: a map under the hook's callback parameter is a body on the application (`closure: [callback]` curation, the parameter is untyped) |
| `timing: after-resolving:<id>` | `afterResolving: {Illuminate\Routing\Router: {…}}` | row 7 with a closure-typed `$callback`; Laravel matches the callback **by type**, so `Illuminate\Foundation\Http\Kernel` fires when `Illuminate\Contracts\Http\Kernel` resolves |
| `make: static` | root key `Illuminate\Pagination\Paginator: {useBootstrap: true}` | §1.0 static receiver: a projected FQCN whose `Σ` is all-static |
| `data` | curated root keys `requests`, `models`, `queries`, `schema`, `extra` flagged `x-manifest.data: true` | stored raw by `ManifestStore`, read by the seams and `declaration:migrate` |
| `classes` (the generator's scope) | `x-manifest.classes` at the schema root | the generator's input is its CLI arguments on bootstrap and the prior schema's list on regeneration |

The shipped manifest shape (today's fixtures, rewritten):

```yaml
# manifest/app.yml — root receiver: Illuminate\Foundation\Application
make:
  Illuminate\Config\Repository:                      # was: config block (timing register)
    set: {app.name: Tenant Console, database.connections.redis.host: cache}
registered:                                          # was: app block (timing registered)
  bind: {App\Contracts\Pdf: App\Services\DomPdf, App\Contracts\Slugger: app/binders/slugger.php}
  singleton: [App\Services\TenantContext]
  when: {App\Http\Controllers\PhotoController: {needs: App\Contracts\Filesystem, give: App\Services\LocalFs}}
  register: [App\Providers\AppServiceProvider]       # was: providers block
  useAppPath: src
afterResolving:                                      # was: router, routes, kernel, view, blade, validator, gate, responses, db blocks
  Illuminate\Routing\Router:
    pattern: {id: '[0-9]+'}
    aliasMiddleware: {subscribed: App\Http\Middleware\EnsureUserIsSubscribed}
    addRoute:
      - {methods: GET, uri: /, action: App\Http\HomeController, name: home}
      - {methods: GET, uri: 'users/{user}', action: [App\Http\UserController, show], where: {user: '[0-9]+'}, missing: App\Http\UserMissing}
    group:
      - attributes: {prefix: admin, as: admin., middleware: [web]}
        routes: {addRoute: [{methods: GET, uri: dashboard, action: App\Http\Admin\DashboardController, name: dashboard}]}
    resource:
      - {name: photos, controller: App\Http\PhotoController, only: [index, show], scoped: true, missing: App\Http\PhotoMissing}
  Illuminate\Foundation\Http\Kernel:
    prependMiddleware: [App\Http\Middleware\GlobalFirst]
    appendMiddlewareToGroup: {web: [App\Http\Middleware\TrackWebActivity]}
    whenRequestLifecycleIsLongerThan: {250: App\Http\SlowRequestReporter}
  Illuminate\View\Factory:
    addLocation: [resources/declared-views]
    composer: {users.*: App\View\UserMenu}
  Illuminate\View\Compilers\BladeCompiler:
    directive: {datetime: App\View\Directives\DateTime}
  Illuminate\Validation\Factory:
    extend: {uppercase: App\Rules\Uppercase@validate}
  Illuminate\Contracts\Auth\Access\Gate:
    define: {edit-post: App\Policies\PostPolicy@edit}
  Illuminate\Routing\ResponseFactory:
    macro: {caps: App\Responses\Caps}
  Illuminate\Database\Connection:
    listen: [App\Listeners\LogQuery]
Illuminate\Pagination\Paginator:                     # static receiver
  useBootstrapFive: true
schema:                                              # data key, consumed by declaration:migrate
  create: {users: {id: ~, string: [name, {column: email, unique: true}], timestamps: ~}}
requests: [...]                                      # data keys, consumed by the seams
models: [...]
queries: [...]
```

`config/laravel-declaration.php` keeps `manifest` (the file path) and `mcp`; `providers` and the
block map are gone. One behavioral consequence is accepted: `Container::afterResolving` does not
fire for a singleton that is already resolved when the callback registers (today's providers use
`callAfterResolving`, which also fires immediately). An already-resolved service is addressed with
`make:`; that is the correct form for `config` and the only form the container offers.

### 2.2 Per-key curation (inside the schema, preserved by re-merge)

| Keyword | Values | Shipped uses (from the retargeted oracle suites; the list is data, not a promise) |
| --- | --- | --- |
| `x-manifest.form` | `entries` | `Container::when`, `Container::make` (the return carries the body) |
| `x-manifest.list` | `argument` | `PendingResourceRegistration::only/except`, `Factory::replaceNamespace` (untyped params whose list is one argument) |
| `x-manifest.order` | `reverse` | `Kernel::prependMiddleware/prependToMiddlewarePriority/prependMiddlewareToGroup/addToMiddlewarePriorityAfter`, `Router::prependMiddlewareToGroup` |
| `x-manifest.closure` | `[param…]` | `Router::group` → `['routes']`; `Application::registered/booting/booted/terminating` → `['callback']` |
| `x-manifest.resolve` | `closure` · `phpFile` · `concrete` · `path` | today's `$ref`s on the App/Kernel/Router keys (`closure`, `closureList`, `concrete`, `binding`), plus `path` on `Application::use*Path`, `Factory::addLocation/prependLocation/addNamespace/prependNamespace/replaceNamespace`, `BladeCompiler::anonymousComponentPath/anonymousComponentNamespace` |
| `x-manifest.data` | `true` | root keys `requests`, `models`, `queries`, `schema`, `extra` |
| `description`, `pattern`, `enum`, `minItems`… | any | curated prose and value constraints, byte-for-byte |

### 2.3 Shared definitions (vocabulary)

`closure`, `phpFile`, `concrete`, `path` (new: `{"type": "string"}`), `classString`, `reference`
stay as `definitions`; `τ(p)` references the one named by `resolve`. `binding`, `bindingIf`,
`closureList`, `stringOrList`, `extension`, `columns`, `config` are retired: every shape they
hand-encoded is a row of §1.2. `request`, `model`, `query`, `schema` stay as the data keys'
definitions (`schema` = `{$ref: #/definitions/Illuminate\Database\Schema\Builder}`).

### 2.4 The migrate command's guard table (command data — never in the engine)

`declaration:migrate {--connection=}` reads the `schema` data key and drives it through
`body($app->make('db.schema'), …)` (or the named connection's builder) with a `guard` hook. The hook
is a table over the receiver and the method-name family; the first matching row decides; the target
is read from the call's arguments by parameter name (`column`/`name`/`from`/`to`/`index`/`columns`),
falling back to the canonical column table moved verbatim from today's `Guards::CANONICAL_COLUMNS`.

| Receiver | Method family | Predicate (`Builder` query) |
| --- | --- | --- |
| `Builder` | `create` | `! hasTable($table)` |
| `Builder` | `table` | `hasTable($table)` |
| `Builder` | `rename` | `hasTable($from) && ! hasTable($to)` |
| `Builder` | `drop`, `dropIfExists` | always |
| `Blueprint` | `rename*` | first string arg exists ∧ second missing (column / index by family suffix) |
| `Blueprint` | `drop*` | target exists (`dropColumn` → column; `dropIndex/dropUnique/dropPrimary/dropFullText/dropSpatialIndex` → index; `dropForeign` → foreign key; canonical columns for `dropTimestamps*/dropSoftDeletes*/dropRememberToken`) |
| `Blueprint` | `index`, `unique`, `primary`, `fullText`, `spatialIndex` | index missing (`$name` or Laravel's derived name) |
| `Blueprint` | `foreign` | foreign key missing |
| `Blueprint` | any other method with a string target | column missing; `change` in the row's rest → column exists; `morphs`-family → `{name}_id` |
| `Blueprint` | `build`, `toSql`, `create`, `drop`, `dropIfExists`, `rename`, `after`, `addFluentCommands`, `addAlterCommands`, `macro`, `mixin`, `flushMacros` | excluded from the projected `Blueprint` definition (schema-invalid in a body) |

The fixed lifecycle order (`dropIfExists → drop → rename → create → table`) stays: it is the
command's orchestration, expressed as the order the command feeds keys to `body()`.

## 3. What the core retires

| Retired | Replaced by (core rule) |
| --- | --- |
| 13 `*DeclarationServiceProvider` files, `DefaultProviders`, the `providers` config key, the block-map config of the previous revision | one `ManifestServiceProvider` with one call: `body($this->app, $manifest)` in `register()` |
| `Manifest.php`, 24 declaration classes, `Internal\DataModel`, `zero-to-prod/data-model*` | raw YAML in `ManifestStore`; the schema is the only validation layer |
| all 34 `Attributes\Attributes\*` + `Internal\Builder` | §1.2 forms derived from Σ; `order: reverse` curation |
| `RoutesDeclarationServiceProvider` special cases, `TWO_ARGUMENT_PENDING_SETTERS`, reflection probing | rows 5/7/10 + `chain` |
| five `reference()/fileValue()/absolute()/wrap*()` copies | §1.5 `ρ` |
| per-provider `callAfterResolving` / `registered()` timing code | the manifest's own `afterResolving:` / `registered:` / `make:` keys |
| `BlueprintMethodKind`, `Guards`, `GuardKind`, `Guard`, `ActionGuard`, `BlueprintAction`, `TableDefinition`, `TableRename`, 13 guard attributes | `chain` (modifiers), `λ` (table bodies), §2.4 guard table |
| `Arr::prependKeysWith` config transform, `CarbonInterval` parsing, comma-split view lists, eager `instance()` make, null-item throw, `*If` `.php` skip, `metadata` → `defaults` duplication | pass-through (rows 2/7/10); see §4 |
| curated class-surface definitions `blade`, `responses`, `pagination`, `db`, `kernel`, `routes`, `route*`, `tableDefinition`, `provider`, and the hand-written root `properties` | generated `definitions.<FQCN>`; root = `$ref Application` + data keys |
| append-only `merge()`, exists-check, `undecided` shape | total generation; `TODO` only marks unknown **element types** |
| `bc-check` in `composer check` | removed: this release is breaking by design |

## 4. Breaking manifest changes

Each is the honest form the signature dictates. The retargeted oracle suites (`tests/Feature/*RegistrationTest.php`, `tests/Fixtures/manifest/*.yml`) are rewritten to these forms (§2.1 shows the composite).

| Before | After | Rule |
| --- | --- | --- |
| component blocks (`router`, `kernel`, `view`, `blade`, `validator`, `gate`, `responses`, `db`) | `afterResolving: {<FQCN>: {…}}` | row 7, closure-typed `$callback`, matched by type |
| `app` block | `registered: {…}` (or top level for register-time) | row 8 + `closure: [callback]` |
| `config: {app: {name: X}}` | `make: {Illuminate\Config\Repository: {set: {app.name: X}}}` (`Repository::set` semantics: a map value replaces that node) | row 6 (`form: entries` on `make`) + row 7 |
| `providers: [{class, force}]` | `register: [Class]` or `register: {Class: true}` under the root or `registered:` | row 5 / row 7 |
| `routes` block | keys under `afterResolving.Illuminate\Routing\Router` (`addRoute`, `group`, `resource`, …) | one `Router` definition (§0 row 7) |
| `group` flattened attributes + `routes` | `[{attributes: {prefix: admin, …}, routes: {addRoute: […]}}]` | row 10, `closure: [routes]` |
| `resource.options` with fluent names mixed in | `options:` is the native third argument; fluent calls are top-level item keys (`only`, `scoped: true`, `missing`) | row 10 + `chain`; `scoped: true` → `scoped()` (row 1) |
| `addRoute.metadata` also wrote `Route::$defaults` | `metadata(array)` only; `DeclaredRequest`/`DeclaredView` read `getMetadata('request')` only | row 10 pass-through |
| `pagination` block | root key `Illuminate\Pagination\Paginator: {useBootstrapFive: true}` | §1.0 static receiver |
| `db: {connection, listen}` | `afterResolving: {Illuminate\Database\Connection: {listen: [Ref]}}` (fires for the connection that resolves) | row 7 |
| `schema.connection` | `declaration:migrate --connection=` | §2.4 |
| `schema.rename: [{from, to}]` | `{old_users: users}` or `[{from, to}]` | row 7 / row 10 |
| `schema` modifier typos rejected by `BlueprintAction` | Fluent accepts them (PHP's own behavior: `->nullabel()` is silent) | §1.1 unknown keys |
| `kernel.whenRequestLifecycleIsLongerThan` `CarbonInterval` strings | numeric only (Laravel's own `$threshold`) | row 7, `resolve: closure` |
| `view.composer` `"a, b": X` comma DSL | `{a: X}` per view, or `[{views: [a, b], callback: X}]` | row 7 / row 10 |
| `validator.extend: {rule: {extension, message}}` | `{rule: Ref}` or `[{rule, extension, message}]` | row 7 / row 10 |
| `app.instance` class-string `make()`d eagerly | bound verbatim (`singleton` is the native way) | row 7 pass-through |
| `app.bind` list `null` item throws; `*If` `.php` items skipped | `[Class]` → `bind('Class')`; Laravel's `bindIf` governs | row 5 |
| any 1-param key: `~` = no call | `~` = call with no arguments; omit the key to opt out | row 1 |
| a block whose service is already resolved at `register()` | use `make:`, not `afterResolving:` | §2.1 note |

## 5. Phases

Each phase leaves `composer check` green (pint, rector-lint, phpstan level 9, 100% coverage,
require-check; `bc-check` leaves in Phase 1 because every phase is breaking).

### Phase 1 — Σ, forms, total generation (schema is the source of truth)

- **Create** `src/Internal/Engine/Signature.php` (`Σ`: `name`, `static`, `params[{name,type,variadic}]`), `src/Internal/Engine/Forms.php` (`rows(Signature, value)`, `schema(Signature, curation, classes)`, `calls(Signature, curation, value)` — one table, two projections; unit-tested as inverses), `src/Internal/Engine/Resolve.php` (§1.5; container-free except `base_path` and `call`).
- **Modify** `src/Internal/SchemaGenerator.php`: `flatten()`/`declarable()` produce `list<Signature>` hierarchy-inclusive (zero-param methods pass the gate; by-ref still skipped and reported); `key()` → `Forms::schema()` + `x-manifest` (`static`, `params`, curation); `render()` keeps the envelope; `merge()` is deleted; **create** `generate(list<class-string> $classes, ?array $prior, Closure $source)`: per class the fragment **replaces** `definitions.<FQCN>`, then per key the prior's `description`/`pattern`/`enum`/`x-manifest` curation keys re-merge on top; prior keys with no signature are kept byte-for-byte and reported (`curation-only`); root = `{$ref: Application}` merged with the prior root's curated `data` keys and every all-static projected FQCN; `x-manifest.classes` records the scope; definitions not in scope (data definitions, vocabulary) are kept; byte-identical when nothing changed.
- **Rewrite** `GenerateManifestSchemaCommand`: `{classes?*} {--from=} {--out=} {--report} {--check}`; `classes` defaults to the prior schema's `x-manifest.classes`; no exists-check; pre-flight every class before any write; `--check` = regenerate ≠ file bytes → failure; `--report` prints `curation-only` and `TODO` (unknown element types) and writes nothing. The shipped class list (`Application`, `Router`, `Route`, `PendingResourceRegistration`, `PendingSingletonResourceRegistration`, `ContextualBindingBuilder`, `Repository`, `Http\Kernel`, `View\Factory`, `BladeCompiler`, `Validation\Factory`, `Gate`, `ResponseFactory`, `Paginator`, `Connection`, `Schema\Builder`, `Blueprint`, `ForeignIdColumnDefinition`, `ForeignKeyDefinition`) lives in the schema it generates, nowhere else.
- **Tests**: `tests/Fixtures/Engine/Arbitrary.php` (a non-Laravel class covering every row precondition: 0/1/2/3 params, variadic, `array`-typed, `Closure`-typed, untyped, static, by-ref, `@internal`, trait, alias, parent); `FormsTest` — for every fixture method × every admissible value the emitted schema validates the value **and** `calls()` returns the expected argument lists, and inadmissible values fail validation; `ReflectionSignature` parity harness (parser Σ ≡ reflection Σ for the fixture and for every shipped class); `GenerateManifestSchemaCommandTest` rewritten (bootstrap from CLI classes, regeneration from `x-manifest.classes`, idempotence by mtime, curation preservation, `--check`, pre-flight).
- **Do not** regenerate the shipped `manifest.schema.json` yet: the generated file validates the §4 forms, and the runtime and fixtures still speak the old ones. Phase 1 tests generate into temp files (as today). The shipped file, the fixture manifests and the runtime switch together in Phase 2, so `ValidateCommandTest` stays green through Phase 1.

### Phase 2 — Runtime (one provider, one store, the typed mirror gone)

- **Regenerate** `manifest.schema.json` (`--from` the old file so curated prose and `$ref`-derived `resolve` keys carry over; the five data keys are curated onto the root once, with `x-manifest.data: true`); `ValidateCommandTest` fixtures move to the §4 forms in the same commit.
- **Create** `src/Internal/Engine/Engine.php` (§1.4 verbatim: `body`, `chain`, `λ`, `def`, `isStaticReceiver`, constructed with the decoded schema, the container, `Resolve`, and a `guard` Closure defaulting to `true`), `src/Internal/ManifestStore.php` (`all(): array`, `block(string): mixed`, `items(string $block, string $field): array<string, array>`, `item(string $block, string $field, string $value): ?array`), `src/Providers/ManifestServiceProvider.php` (`register()`: bind `ManifestStore` from the YAML, bind `Engine`, then `$engine->body($this->app, $store->all())` — the only dispatch call in the package; no `boot()`).
- **Modify** `LaravelDeclarationProvider` (register the one provider; drop the `providers` list), `config/laravel-declaration.php` (keep `manifest` and `mcp` only), the four seams to `ManifestStore` (`DeclaredRequest`/`DeclaredView` read route metadata only), `composer.json` (remove `zero-to-prod/data-model`, `data-model-helper`).
- **Delete** the 13 providers, `DefaultProviders.php`, `Manifest.php`, the 24 declaration classes **except** `TableDefinition.php`/`TableRename.php`/`BlueprintAction.php` (Phase 3), `Internal/DataModel.php`, `Internal/Builder.php`, every file under `src/Attributes/Attributes/` that is not a guard attribute.
- **Tests**: every `*RegistrationTest` suite and fixture manifest retargeted to §4 forms with the same assertions (the oracle), including the timing assertions (`registered:` bindings win over app providers; `afterResolving:` fires before routes load; `make:` config is visible to providers' `register()`); `EngineTest` — rows 1–10 end to end against the fixture class, `chain` threading (`ForeignId → constrained → cascadeOnDelete` on a stub), `λ` bodies, unknown receiver → `VARIADIC`, static receiver, `order: reverse`, `form: entries`, `list: argument`, `data` skip; `ResolveTest`; `ManifestStoreTest`; `ValidateCommandTest` on the moved fixtures.

### Phase 3 — The schema key through the engine

- **Modify** `MigrateCommand`: `{--connection=}`; `handle(ManifestStore $store, Engine $engine)`; builds the §2.4 guard table (`src/Internal/Engine/Guards.php`: the table + `CANONICAL_COLUMNS` + the six `Builder` predicates as plain functions) and feeds the `schema` data key's `dropIfExists`, `drop`, `rename`, `create`, `table` keys in that order to `$engine->withGuard($table)->body($builder, …)`; the two-column console output stays.
- **Delete** `BlueprintMethodKind.php`, `Guards.php`, `GuardKind.php`, `Guard.php`, `ActionGuard.php`, `BlueprintAction.php`, `TableDefinition.php`, `TableRename.php`, the 13 guard attributes, `Schema.php`.
- **Tests**: `SchemaRegistrationTest` retargeted (same sqlite assertions; the modifier-typo expectation becomes "Fluent stores it"); guard table coverage per family on the fixture tables; lifecycle order; `--connection`.

### Phase 4 — Alignment

- `composer.json`: `bc-check` script and `bin/bc-check.sh` removed; `extra.laravel.providers` → `ZeroToProd\LaravelDeclaration\LaravelDeclarationProvider`; description for the general engine.
- `STYLE.md`: Rules 7, 8, 10 and the Canonical Skeleton are replaced by §1 (forms) and §2 (data); Rule 6.2 gains "a present key with `null` calls with no arguments"; Rule 5 gains "timing is written in the manifest with the application's lifecycle methods".
- README and `docs/declarative-*.md` runtime sections point at §1 and the §2.1 manifest; `declarative-manifest-schema-generator.md` and `generate-manifest-schema-plan.md` are marked superseded by this file.
- `InstallCommand`/`Installer` template writes the §2.1 manifest skeleton and the two-key config; MCP `Install`/`Readme` tools pick up the new shape.

## 6. Failure surface

| # | Failure | Raised when |
| --- | --- | --- |
| 1 | command failure `No source file for $class` | generator pre-flight, before any write |
| 2 | `RuntimeException` `$class is not declared in its resolved source` / `PhpParser\Error` | generator pre-flight |
| 3 | `--check` non-zero | regenerated bytes ≠ `manifest.schema.json` |
| 4 | `LogicException` unknown `resolve` vocabulary | the engine meeting a curation value outside §2.2 |
| 5 | `LogicException` `.php` reference did not return a Closure | `ρ(closure)` |
| 6 | schema validation errors | `declaration:validate` — the only validation layer; a root key that is neither an `Application` method, a static receiver, nor a data key is schema-invalid |
| 7 | native PHP/Laravel failures (`Error: Call to undefined method`, `ArgumentCountError`, `TypeError`, `BadMethodCallException`, Fluent silence) | wherever the call site would fail in hand-written PHP — never pre-empted |

## 7. Out of scope

- Additional components (`csrf`, `url`, `rate_limiter`, …): each is a class added to the generator's scope plus curation, after this migration; the manifest addresses it with `afterResolving:` or `make:` like every other service.
- The Tier-2 seams' orchestration (`DeclaredRequest`, `DeclaredModel`, `DeclaredQuery`, `DeclaredView`): only their data source changes.
- The MCP server.
- Non-Laravel roots: `body()` takes any receiver; only the provider's one call site names the `Application`.

## 8. Open items

| # | Item | Resolution path |
| --- | --- | --- |
| 1 | The entries/row discriminator forbids a map key equal to any parameter name of the method (e.g. a middleware alias literally named `name`); the row form is the escape hatch | documented in the generated description stub; revisit only if an oracle manifest hits it |
| 2 | `afterResolving` registered in `register()` fires for `router`, `view`, `blade.compiler`, `validator`, the gate and the kernel because none is resolved before our provider registers; a host app that resolves one earlier must use `make:` | the retargeted timing tests pin the order under testbench; the README states the rule |
| 3 | `route:cache`/`config:cache` — the engine registers closures at boot exactly as today's providers do | the existing cache-safety tests move with the suites in Phase 2 |
| 4 | Which curated `description` prose survives when a key moves (e.g. `routes.addRoute` → `Router::addRoute`) | re-merge keys by `(FQCN, method)`; prose on retired definitions is dropped with a `--report` line |

## 9. Implementation notes

What the implementation settled where the plan left room, and where it deviates from §1–§5, each with the reason.

| # | Plan | Implemented | Why |
| --- | --- | --- | --- |
| 1 | §1.5 `closure`: `fn (...$a) => $container->call($ref, $a)` | the wrapper pairs its positional arguments with the target's parameter names (by reflection on the reference) and then calls `$container->call($ref, $named + $rest)` | `Container::call` does not map positional arguments onto untyped or builtin-typed parameters (`BoundMethod::addDependencyForCallParameter` throws "Unable to resolve dependency"); `compile(string $expression)`, `__invoke($startedAt, $request, $response)` and `handle(Request $request, Throwable $e)` only work with named pairing. The first-class callable is bound inside `Resolve` because `Macroable` rebinds the wrapper's scope to the macro host |
| 2 | §2.2 `resolve: <vocabulary>` per key | `resolve: {param: vocabulary}` per key | a key has several parameters (`addNamespace($namespace, $hints)` resolves `hints` as a path, never `namespace`); the vocabulary belongs to a parameter |
| 3 | §1.0 static receiver = a projected FQCN whose Σ is all-static | a projected FQCN with at least one static key; its root key admits only its static keys | `Illuminate\Pagination\Paginator` carries 50 instance methods beside its presets, so the plan's own example would not have qualified; `Router::macro` and every other Macroable static are addressable the same way |
| 4 | §1.4 `def()` walks `get_parent_class` | walks parents, then interfaces (`class_implements`) | the concrete `Illuminate\Auth\Access\Gate` is projected; a definition keyed by a contract still resolves for the bound concrete |
| 5 | §1.3 root = `$ref Application ∪ data keys ∪ static FQCNs` with `additionalProperties: false` | every root key is a JSON-pointer `$ref` into `definitions.<Application>.properties.<m>`; the ROW of row 5 is likewise a pointer into the key's own `anyOf`; a shared `definitions.bodies` carries the projected-FQCN `$ref`s rows 6/7 use | a `$ref` to a whole definition cannot be unioned with extra keys under `additionalProperties: false`; pointers keep the file at ~650 KB instead of 1.6 MB |
| 6 | §1.3 rows 1 and 2 as separate schema rows | merged into `{"type": ["null", "boolean", "string", "integer", "number"]}` when the first parameter is untyped; the ROW omits `properties` for unknown types and `additionalProperties: true` | size; identical validation |
| 7 | §1.2 row 3 (`p0.type = array` → the list is the argument) | unless every item is a row (a map whose first key is a parameter name), which is row 5 | `Router::group(array $attributes, $routes)` takes a list of rows (`group: [{attributes: …, routes: …}]`) |
| 8 | §1.1 `ReflectionClass::getMethods()` order: aliases appended after their `use` statement | an `as` alias is inserted immediately before the method it names, in trait order | that is PHP's binding order (`zend_traits_copy_functions`); the parity harness over the 19 shipped classes and the fixture proved the previous rule only matched `Router` by coincidence |
| 9 | §1.3 `τ(p)` = `{$ref: <vocabulary>}` for a curated `resolve` | the `phpFile` vocabulary emits `true` | the resolver passes every non-`.php` value through, so the file is one option, not the type (`instance: {app.signature: "1.0"}`) |
| 10 | §2.1 `afterResolving: {Illuminate\Database\Connection: {listen: …}}` "fires for the connection that resolves" | `booted: {make: {Illuminate\Database\Connection: {listen: […]}}}` | `DB::` obtains connections through `DatabaseManager`, not the container, so `afterResolving` never fires for them; `make` at boot end addresses the default connection |
| 11 | §2.4 lifecycle Blueprint verbs "excluded from the projected definition" | projected (generation is total); the migrate command's guard returns `false` for them | the guard table is command data; the definition stays a faithful projection |
| 12 | §2.4 `body($builder, …)` per verb | the `table` phase feeds one key at a time (`body($builder, ['table' => [$table => [$method => $value]]])`) and the action tally restarts before it | each guard must see committed state (`renameIndex` then `dropIndex` on the renamed index) |
| 13 | `x-manifest.params` as `list<{name, type, variadic}>` | a `{name: type}` map in declaration order, a variadic spelled `...name`, `static` only when true | size and readability of the shipped file |
| 14 | §1.1 Σ type for unions | a union with exactly one non-null member is that member; any other union is untyped | `?Closure` and `?int` are common and precise; `string|array` has no single JSON form |
| 15 | §2.1 bootstrap curation | `bind`/`bindIf`/`singleton`/`singletonIf`/`scoped`/`scopedIf` resolve both `abstract` and `concrete` as `concrete`; `ContextualBindingBuilder::give` is `list: argument` + `concrete`; `ResponseFactory::macro` resolves `closure`; `Route::missing` and `PendingResourceRegistration::missing` resolve `closure` | a `.php` list item under `bind` binds its own name through the Closure's return type; a typed variadic contextual binding takes the list whole; a `Class@method` macro must be callable |

Known limits the forms admit:

- A map whose first key is `0` is a PHP list (`array_is_list`): `whenRequestLifecycleIsLongerThan: {0: …}` fans out as a list; write `1:` or the row form.
- The entries/row discriminator (`propertyNames`) is not enforced by `justinrainbow/json-schema`; editors using ajv (yaml-language-server) do enforce it. A map value whose key is literally a parameter name (`share: {key: …}`) is a row; use the row form for such keys (open item 1).
