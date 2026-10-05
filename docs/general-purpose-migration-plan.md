# General-Purpose Manifest Migration Plan

This package becomes a **general-purpose manifest engine**: deterministic tooling projects an
**arbitrary list of classes** into `manifest.schema.json`, the schema validates the manifest, and
one runtime maps the manifest back onto the very methods the schema was projected from. Because
generation and mapping are the two directions of the same projection, the whole project reduces
to **one ordered decision list** (§1). Everything else is either data that list consumes (§2) or
I/O that calls it (§5). Every existing per-component code path, attribute, typed mirror and
hand-written shape is retired by that list; the breaks it causes are enumerated (§4).

This revision **supersedes the previous revision of this file end to end**, the per-component
scaffolding flow in [declarative-manifest-schema-generator.md](declarative-manifest-schema-generator.md),
and the run order / re-merge gate of [generate-manifest-schema-plan.md](generate-manifest-schema-plan.md)
(its §4.1 rule — definition key = FQCN verbatim — is **restored**, see §0 row 11). Only this document
is changed by this revision; no code is implemented here.

## 0. Validation of the previous revision (line by line)

Every claim below was checked against the working tree at `417f32c` and `vendor/laravel/framework`.
Rows marked **core** changed the design; the rest are inventory corrections.

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
    //   6  map                                 first k ∉ names(P) ∧ n ≥ 2 ∧ p0.type ≠ array   ENTRIES — per (k, v) (x-manifest.order on lists):
    //                                                                                        v list ∧ p1.type ≠ array → m(k, ρ(i, p1)) per i ∈ v
    //                                                                                        v map  ∧ p1 is closure   → m(k, λ(v))
    //                                                                                        otherwise                → m(k, ρ(v, p1))
    //   7  map                                 first k ∉ names(P) ∧ x-manifest.form = entries ∧ n = 1   m(k) with rest = v     Container::when
    //   8  map                                 first k ∉ names(P), otherwise               m(value)                 the map IS the argument
    //   9  map                                 first k ∈ names(P)                          ROW — m(...named) where
    //                                                                                        named[k] = p is closure ∧ v is map ? λ(v) : ρ(v, p)   for k ∈ names(P)
    //                                                                                        rest     = { k: v | k ∉ names(P) }                     in manifest order
    //
    //   "p is closure" = p.type = closure ∨ p.name ∈ x-manifest.closure.
    //   The only per-key curation the forms admit, all data, all preserved by re-merge:
    //     form: entries · list: argument · order: reverse · closure: [param…] · resolve: <vocabulary>

    // ═══ 3. SCHEMA  (emit) — definitions.<FQCN>.properties[m] = anyOf of the rows m admits ═════════════════════
    //   row 1 → {type: "null"} , {const: true}            row 2 → τ(p0)                     row 3/4 → {type: array, items: τ(p0)}
    //   row 5 → {type: array, items: anyOf[τ(p0), ROW]}   row 8 → {type: object}            row 7 → {type: object, additionalProperties: {type: object}}
    //   row 6 → {type: object, propertyNames: {not: {enum: names(P)}}, additionalProperties: anyOf[τ(p1), {type: array, items: τ(p1)}]}
    //   row 9 → ROW = {type: object, properties: {p.name: τ(p)}, required: [P[0].name], additionalProperties: true}   (rest validates at runtime, §4)
    //   τ(p)  = p.type → JSON type; null → true (+ TODO(m: $p) in the stub description); resolve set → {"$ref": "#/definitions/<vocabulary>"}
    //   Every key carries  "x-manifest": { static, params, …curation }  — the runtime recomputes forms from it and NEVER re-reads vendor.
    //   Curated description prose, pattern, x-manifest keys survive regeneration byte-for-byte; the structural shape is always regenerated.

    // ═══ 4. APPLY  (runtime) — the two composition forms PHP has ══════════════════════════════════════════════════
    public function body(object|string $t, array $data): void                    // $t->a(); $t->b();  — a block, or a closure body
    {
        foreach ($data as $key => $value) {                                      // manifest order = call order (YAML maps are ordered)
            $meta = $this->def($t)[$key] ?? self::VARIADIC;                     // the key schema: x-manifest = { static, params, …curation }

            foreach ($this->calls($meta, $value) as [$args, $rest]) {
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
            $this->body($o, $data);                                              // Builder::create($t, Closure) → Blueprint;  Router::group($a, $routes) → Router
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

    // ═══ 5. RESOLVE  ρ(value, p) — a manifest value becomes an argument; vocabulary names = the shared definitions ═══
    //   (none)    value untouched                                                     default when p.type ≠ closure
    //   closure   "Class@method" | "Class::method" | "function" → fn (...$a) => $container->call($ref, $a);  "*.php" → require once, must return Closure
    //   phpFile   "*.php" → require once, its return value (any type)
    //   concrete  "~" → null (self-binding);  "*.php" → phpFile;  else untouched
    //   path      relative → base_path($value);  absolute untouched
    //   default   p.type = closure → closure;  otherwise none;  curated per key with x-manifest.resolve
    //   VARIADIC  = { static: false, params: [{ name: 'arguments', type: null, variadic: true }] }   — the unknown-key signature (row 4 / 8)
}
```

Why this is the whole project: the manifest is PHP written as data. Row 1–9 is the grammar of a
PHP call site (`m()`, `m($x)`, `m(...$xs)`, `m($k, $v)` per entry, `m(a: …, b: …)`); `body` and
`chain` are PHP's two ways to compose calls (statements on one receiver, fluent on the return);
`λ` is PHP's closure. The schema is the serialized `Σ` plus the forms; the runtime is the
interpreter of the same forms. No rule names a Laravel class.

## 2. Data the core consumes (closed sets)

### 2.1 The block map — `config('laravel-declaration.blocks')`

Ordered; order = dispatch order; the generator's input; embedded resolved into the schema root as
`x-manifest.blocks` (the runtime reads the schema, never config — G2).

```php
[
    'app'        => ['class' => Illuminate\Foundation\Application::class,        'timing' => 'registered'],
    'config'     => ['class' => Illuminate\Config\Repository::class,             'timing' => 'register', 'make' => 'config'],
    'kernel'     => ['class' => Illuminate\Foundation\Http\Kernel::class,        'timing' => 'after-resolving:'.Illuminate\Contracts\Http\Kernel::class],
    'router'     => ['class' => Illuminate\Routing\Router::class],
    'view'       => ['class' => Illuminate\View\Factory::class,                  'timing' => 'after-resolving:view'],
    'blade'      => ['class' => Illuminate\View\Compilers\BladeCompiler::class,  'timing' => 'after-resolving:blade.compiler'],
    'responses'  => ['class' => Illuminate\Routing\ResponseFactory::class],
    'pagination' => ['class' => Illuminate\Pagination\Paginator::class,          'make' => 'static'],
    'db'         => ['class' => Illuminate\Database\Connection::class,           'make' => 'db.connection'],
    'validator'  => ['class' => Illuminate\Validation\Factory::class,            'timing' => 'after-resolving:validator'],
    'gate'       => ['class' => Illuminate\Contracts\Auth\Access\Gate::class,    'timing' => 'after-resolving:'.Illuminate\Contracts\Auth\Access\Gate::class],
    'schema'     => ['class' => Illuminate\Database\Schema\Builder::class,       'timing' => 'command', 'make' => 'db.schema'],
    'requests'   => ['data' => 'request'],   // data blocks: validated by the named curated definition, stored raw, consumed by a seam
    'models'     => ['data' => 'model'],
    'queries'    => ['data' => 'query'],
    'extra'      => ['data' => true],        // free-form
    // continuation classes: projected (definitions only, no root key) so chains and closure bodies have signatures
    'classes'    => [
        Illuminate\Routing\Route::class, Illuminate\Routing\PendingResourceRegistration::class,
        Illuminate\Routing\PendingSingletonResourceRegistration::class, Illuminate\Container\ContextualBindingBuilder::class,
        Illuminate\Database\Schema\Blueprint::class, Illuminate\Database\Schema\ForeignIdColumnDefinition::class,
        Illuminate\Database\Schema\ForeignKeyDefinition::class,
    ],
]
```

| Field | Values | Meaning |
| --- | --- | --- |
| `class` | FQCN | projected under `definitions.<FQCN>`; root `properties.<block>` = `{"$ref": "#/definitions/<FQCN>"}` |
| `make` | *(default)* `Container::make(class)` · `<binding id>` · `static` (receiver = the class name; every key must be static in Σ) | how the receiver is obtained |
| `timing` | `boot` (default) · `register` · `registered` · `booting` · `booted` · `after-resolving:<id>` · `command` (never at boot; a command drives it) | when `body()` runs |
| `data` | definition name · `true` | not dispatched; root wiring = `{type: array, items: $ref}` for a named definition, `{type: object}` for `true` |
| `classes` | list of FQCN | definition-only projections for continuation receivers (an unprojected receiver still works: its keys dispatch as `VARIADIC`) |

Two definitions, one class, is no longer possible by construction: block names are root wiring,
classes are definitions.

### 2.2 Per-key curation (inside the schema, preserved by re-merge)

| Keyword | Values | Shipped uses (from the retargeted oracle suites; the list is data, not a promise) |
| --- | --- | --- |
| `x-manifest.form` | `entries` | `Container::when` |
| `x-manifest.list` | `argument` | `PendingResourceRegistration::only/except`, `Factory::replaceNamespace` (untyped params whose list is one argument) |
| `x-manifest.order` | `reverse` | `Kernel::prependMiddleware/prependToMiddlewarePriority/prependMiddlewareToGroup/addToMiddlewarePriorityAfter`, `Router::prependMiddlewareToGroup` |
| `x-manifest.closure` | `[param…]` | `Router::group` → `['routes']` |
| `x-manifest.resolve` | `closure` · `phpFile` · `concrete` · `path` | today's `$ref`s on the App/Kernel/Router keys (`closure`, `closureList`, `concrete`, `binding`), plus `path` on `Application::use*Path`, `Factory::addLocation/prependLocation/addNamespace/prependNamespace/replaceNamespace`, `BladeCompiler::anonymousComponentPath/anonymousComponentNamespace` |
| `description`, `pattern`, `enum`, `minItems`… | any | curated prose and value constraints, byte-for-byte |

### 2.3 Shared definitions (vocabulary)

`closure`, `phpFile`, `concrete`, `path` (new: `{"type": "string"}`), `classString`, `reference`
stay as `definitions`; `τ(p)` references the one named by `resolve`. `binding`, `bindingIf`,
`closureList`, `stringOrList`, `extension`, `columns`, `config` are retired: every shape they
hand-encoded is a row of §1.2. `request`, `model`, `query` stay (data-block definitions).

### 2.4 The migrate command's guard table (command data — never in the engine)

`declaration:migrate` drives the `schema` block through `body()` with a `guard` hook. The hook is
a table over the receiver and the method-name family; the first matching row decides; the target
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
| 13 `*DeclarationServiceProvider` files, `DefaultProviders`, `providers` config key | one `ManifestServiceProvider`: `body()` per block in block-map order, per timing |
| `Manifest.php`, 24 declaration classes, `Internal\DataModel`, `zero-to-prod/data-model*` | raw YAML in `ManifestStore`; the schema is the only validation layer |
| all 34 `Attributes\Attributes\*` + `Internal\Builder` | §1.2 forms derived from Σ; `order: reverse` curation |
| `RoutesDeclarationServiceProvider` special cases, `TWO_ARGUMENT_PENDING_SETTERS`, reflection probing | rows 5/6/9 + `chain` |
| five `reference()/fileValue()/absolute()/wrap*()` copies | §1.5 `ρ` |
| `BlueprintMethodKind`, `Guards`, `GuardKind`, `Guard`, `ActionGuard`, `BlueprintAction`, `TableDefinition`, `TableRename`, 13 guard attributes | `chain` (modifiers), `λ` (table bodies), §2.4 guard table |
| `Arr::prependKeysWith` config transform, `CarbonInterval` parsing, comma-split view lists, eager `instance()` make, null-item throw, `*If` `.php` skip, `metadata` → `defaults` duplication | pass-through (rows 2/6/9); see §4 |
| curated class-surface definitions `blade`, `responses`, `pagination`, `db`, `kernel`, `schema`, `routes`, `route*`, `tableDefinition`, `provider` | generated `definitions.<FQCN>` |
| append-only `merge()`, exists-check, `undecided` shape | total generation; `TODO` only marks unknown **element types** |
| `bc-check` in `composer check` | removed: this release is breaking by design |

## 4. Breaking manifest changes

Each is the honest form the signature dictates. The retargeted oracle suites (`tests/Feature/*RegistrationTest.php`, `tests/Fixtures/manifest/*.yml`) are rewritten to these forms.

| Block | Before | After | Rule |
| --- | --- | --- | --- |
| `routes` | top-level block | keys live under `router` (`router.addRoute`, `router.group`, …) | one `Router` definition (§0 row 7) |
| `router.group` | flattened attributes + `routes` | `[{attributes: {prefix: admin, …}, routes: {addRoute: […]}}]` | row 9, `closure: [routes]` |
| `router.resource` | `options: {…}` with fluent names mixed in | `options:` is the native third argument; fluent calls are top-level item keys (`[{name, controller, only: […], scoped: true, missing: X}]`) | row 9 + `chain`; `scoped: true` → `scoped()` (row 1) |
| `router.addRoute` | `metadata:` also wrote `Route::$defaults` | `metadata(array)` only; `DeclaredRequest`/`DeclaredView` read `getMetadata('request')` only | row 9 pass-through |
| `providers` | `[{class, force}]` | `app.register: [Class]` or `app.register: {Class: true}` | row 5 / row 6 |
| `config` | `{app: {name: X}}` prefixed | `config.set: {app.name: X}` (`Repository::set` semantics: a map value replaces that node) | row 6 |
| `schema.connection` | manifest key | block map `make` (`db.schema`); a second connection = a second block entry | §2.1 |
| `schema.rename` | `[{from, to}]` | `{old_users: users}` or `[{from, to}]` | row 6 / row 9 |
| `schema.create.<t>.<col>` | modifier typos rejected by `BlueprintAction` | Fluent accepts them (PHP's own behavior: `->nullabel()` is silent) | §1.1 unknown keys |
| `kernel.whenRequestLifecycleIsLongerThan` | numeric or `CarbonInterval` string | numeric only (Laravel's own `$threshold`) | row 6, `resolve: closure` |
| `view.composer`/`creator` | `"a, b": X` comma DSL | `{a: X}` per view, or `[{views: [a, b], callback: X}]` | row 6 / row 9 |
| `validator.extend` | `{rule: {extension, message}}` | `{rule: Ref}` or `[{rule, extension, message}]` | row 6 / row 9 |
| `app.instance` | class-string `make()`d eagerly | bound verbatim (`singleton` is the native way) | row 6 pass-through |
| `app.bind` list | `null` item throws | `[Class]` → `bind('Class')` | row 5 |
| `app.*If` list | `.php` items skipped | dispatched; Laravel's `bindIf` governs | row 5 |
| `db` | `{connection, listen}` | receiver is the default `Connection`; `listen: [Ref]` | §2.1 `make: db.connection` |
| any 1-param key | `~` = no call | `~` = call with no arguments; omit the key to opt out | row 1 |

## 5. Phases

Each phase leaves `composer check` green (pint, rector-lint, phpstan level 9, 100% coverage,
require-check; `bc-check` leaves in Phase 1 because every phase is breaking).

### Phase 1 — Σ, forms, total generation (schema is the source of truth)

- **Create** `src/Internal/Engine/Signature.php` (`Σ`: `name`, `static`, `params[{name,type,variadic}]`), `src/Internal/Engine/Forms.php` (`rows(Signature, value)`, `schema(Signature, curation)`, `calls(Signature, curation, value)` — one table, two projections; unit-tested as inverses), `src/Internal/Engine/Resolve.php` (§1.5; container-free except `base_path` and `call`).
- **Modify** `src/Internal/SchemaGenerator.php`: `flatten()`/`declarable()` produce `list<Signature>` (zero-param methods pass the gate; by-ref still skipped and reported); `key()` → `Forms::schema()` + `x-manifest` (`static`, `params`, curation); `render()` keeps the envelope; `merge()` is deleted; **create** `generate(BlockMap, ?prior, Closure $source)`: per class in `blocks` ∪ `classes`, the fragment **replaces** `definitions.<FQCN>`, then per key the prior's `description`/`pattern`/`enum`/`x-manifest` curation keys re-merge on top; prior keys with no signature are kept byte-for-byte and reported (`curation-only`); root `properties` and `x-manifest.blocks` are written from the block map; definitions not in scope (data definitions, vocabulary) are kept; byte-identical when nothing changed.
- **Rewrite** `GenerateManifestSchemaCommand`: `{classes?*} {--map=} {--from=} {--out=} {--report} {--check}`; no exists-check; pre-flight every class before any write; `--check` = regenerate ≠ file bytes → failure; `--report` prints `curation-only` and `TODO` (unknown element types) and writes nothing.
- **Modify** `config/laravel-declaration.php`: add §2.1 `blocks`.
- **Tests**: `tests/Fixtures/Engine/Arbitrary.php` (a non-Laravel class covering every row precondition: 0/1/2/3 params, variadic, `array`-typed, `Closure`-typed, untyped, static, by-ref, `@internal`, trait, alias); `FormsTest` — for every fixture method × every admissible value the emitted schema validates the value **and** `calls()` returns the expected argument lists, and inadmissible values fail validation; `ReflectionSignature` parity harness (parser Σ ≡ reflection Σ for the fixture and for every class in the shipped block map); `GenerateManifestSchemaCommandTest` rewritten (bootstrap from nothing, idempotence by mtime, curation preservation, `--check`, pre-flight).
- **Do not** regenerate the shipped `manifest.schema.json` yet: the generated file validates the §4 forms, and the runtime and fixtures still speak the old ones. Phase 1 tests generate into temp files (as today). The shipped file, the fixture manifests and the runtime switch together in Phase 2, so `ValidateCommandTest` stays green through Phase 1.

### Phase 2 — Runtime (one provider, one store, the typed mirror gone)

- **Regenerate** `manifest.schema.json` from the §2.1 block map (first `--from` the old file so curated prose and `$ref`-derived `resolve` keys carry over); `ValidateCommandTest` fixtures move to the §4 forms in the same commit.
- **Create** `src/Internal/Engine/Engine.php` (§1.4 verbatim: `body`, `chain`, `λ`, `def`, constructed with the decoded schema, the container, `Resolve`, and a `guard` Closure defaulting to `true`), `src/Internal/ManifestStore.php` (`block(string): mixed`, `items(string $block, string $field): array<string, array>`, `item(string $block, string $field, string $value): ?array`), `src/Providers/ManifestServiceProvider.php` (`register()`: bind `ManifestStore` from the YAML, bind `Engine`, run `timing: register` blocks; `boot()`: every other block in order; `registered/booting/booted` go through the matching `Application` hook, `after-resolving:<id>` through `afterResolving`, `command` is skipped).
- **Modify** `LaravelDeclarationProvider` (register the one provider; drop the `providers` list), the four seams to `ManifestStore` (`DeclaredRequest`/`DeclaredView` read route metadata only), `composer.json` (remove `zero-to-prod/data-model`, `data-model-helper`).
- **Delete** the 13 providers, `DefaultProviders.php`, `Manifest.php`, the 24 declaration classes **except** `TableDefinition.php`/`TableRename.php`/`BlueprintAction.php` (Phase 3), `Internal/DataModel.php`, `Internal/Builder.php`, every file under `src/Attributes/Attributes/` that is not a guard attribute.
- **Tests**: every `*RegistrationTest` suite and fixture manifest retargeted to §4 forms with the same assertions (the oracle); `EngineTest` — rows 1–9 end to end against the fixture class, `chain` threading (`ForeignId → constrained → cascadeOnDelete` on a stub), `λ` bodies, unknown receiver → `VARIADIC`, `static`, every timing, `order: reverse`, `form: entries`, `list: argument`; `ResolveTest`; `ManifestStoreTest`; `ValidateCommandTest` on the moved fixtures.

### Phase 3 — The schema block through the engine

- **Modify** `MigrateCommand`: `handle(ManifestStore $store, Engine $engine)`; builds the §2.4 guard table (`src/Internal/Engine/Guards.php`: the table + `CANONICAL_COLUMNS` + the six `Builder` predicates as plain functions) and feeds `dropIfExists`, `drop`, `rename`, `create`, `table` keys in that order to `$engine->withGuard($table)->body($builder, …)`; the two-column console output stays.
- **Delete** `BlueprintMethodKind.php`, `Guards.php`, `GuardKind.php`, `Guard.php`, `ActionGuard.php`, `BlueprintAction.php`, `TableDefinition.php`, `TableRename.php`, the 13 guard attributes, `Schema.php`.
- **Tests**: `SchemaRegistrationTest` retargeted (same sqlite assertions; the modifier-typo expectation becomes "Fluent stores it"); guard table coverage per family on the fixture tables; lifecycle order.

### Phase 4 — Alignment

- `composer.json`: `bc-check` script and `bin/bc-check.sh` removed; `extra.laravel.providers` → `ZeroToProd\LaravelDeclaration\LaravelDeclarationProvider`; description for the general engine.
- `STYLE.md`: Rules 7, 8, 10 and the Canonical Skeleton are replaced by §1 (forms) and §2 (data); Rule 6.2 gains "a present key with `null` calls with no arguments".
- README and `docs/declarative-*.md` runtime sections point at §1; `declarative-manifest-schema-generator.md` and `generate-manifest-schema-plan.md` are marked superseded by this file.
- `InstallCommand`/`Installer` template writes the `blocks` config; MCP `Install`/`Readme` tools pick up the new keys.

## 6. Failure surface

| # | Failure | Raised when |
| --- | --- | --- |
| 1 | command failure `No source file for $class` | generator pre-flight, before any write |
| 2 | `RuntimeException` `$class is not declared in its resolved source` / `PhpParser\Error` | generator pre-flight |
| 3 | `--check` non-zero | regenerated bytes ≠ `manifest.schema.json` |
| 4 | `LogicException` unknown `resolve` vocabulary / `make` id / `timing` | the engine meeting a block map or curation value outside §2 |
| 5 | `LogicException` `.php` reference did not return a Closure | `ρ(closure)` |
| 6 | schema validation errors | `declaration:validate` — the only validation layer |
| 7 | native PHP/Laravel failures (`ArgumentCountError`, `TypeError`, `BadMethodCallException`, Fluent silence) | wherever the call site would fail in hand-written PHP — never pre-empted |

## 7. Out of scope

- Additional components (`csrf`, `url`, `rate_limiter`, …): each is a block-map entry plus curation, after this migration.
- The Tier-2 seams' orchestration (`DeclaredRequest`, `DeclaredModel`, `DeclaredQuery`, `DeclaredView`): only their data source changes.
- The MCP server.
- Multi-connection `schema`/`db` beyond "one block entry per connection".

## 8. Open items

| # | Item | Resolution path |
| --- | --- | --- |
| 1 | The entries/row discriminator forbids a map key equal to any parameter name of the method (e.g. a middleware alias literally named `name`); the row form is the escape hatch | documented in the generated description stub; revisit only if an oracle manifest hits it |
| 2 | `after-resolving` blocks and `route:cache`/`config:cache` — the engine registers closures at boot exactly as today's providers do | the existing cache-safety tests move with the suites in Phase 2 |
| 3 | Which curated `description` prose survives when a key's form changes (e.g. `routes` → `router`) | re-merge keys by `(FQCN, method)`; prose on retired definitions is dropped with a `--report` line |
