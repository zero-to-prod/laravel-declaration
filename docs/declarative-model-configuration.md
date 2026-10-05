# Declarative Model Configuration — the Completed `models:` Property/Method Surface & the Non-Goal Reclassification (Gap Inventory §2.6)

> Manifest forms in this document are the pre-engine block shapes; see docs/general-purpose-migration-plan.md §2.1 and README for the current forms.

Source of truth: `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php` (`laravel/framework` v13.33.0), with `Eloquent/Concerns/HasAttributes.php`, `Concerns/GuardsAttributes.php`, `Concerns/HidesAttributes.php`, `Concerns/HasTimestamps.php`, `Concerns/HasEvents.php`, `Concerns/HasGlobalScopes.php`, `Concerns/HasRelationships.php`, `Eloquent/Casts/Attribute.php`, `Eloquent/Scope.php`, `Eloquent/Prunable.php`, `Eloquent/MassPrunable.php` and `Eloquent/SoftDeletes.php`.

Grounding documentation: [declarative-model.md](declarative-model.md) (the completed `models:` specification — §1 lifecycle, §1.2 properties, §2.5 registration, §2.6 non-goals), [declarative-request-to-view-roadmap.md](declarative-request-to-view-roadmap.md) §2 Design Rules (Stage 18, Phase 2 scope), and [declarative-tier1-gap-inventory.md](declarative-tier1-gap-inventory.md) §2.6 — the Tier 1 gap row this document resolves.

Goal: close gap inventory §1 row 9 / §2.6 ("Eloquent Model Configuration — `models:` — `[/]` — Property/method surface complete; relations/casts-method/scopes/hooks reclassified as decided non-goals") by (1) re-verifying, key by key, that the Tier 1 `models:` surface maps onto the native `Illuminate\Database\Eloquent\Model` property/method surface, (2) recording the reclassification of the six withdrawn keys (`models.relations.<name>`, `models.casts` method dispatch, `models.attributes.<name>.Attribute`, `models.scopes.<name>`, `models.booted`, `models.prunable`) as **decided non-goals** with v13.33.0 grounding, (3) shipping the complete implementation as it exists — two files, zero per-key code, **dynamic dispatch** by native member name, no provider — and (4) correcting the stale validation claims left in [declarative-model.md](declarative-model.md) by commit `78e8637` ("Remove validation methods and allow unknown keys in declaration blocks"). **No `src/` change is required**: the surface is complete. After the documentation corrections, inventory §1 row 9 and §3.8 reclassify `[/]` → `[x]` (§6).

---

## 1. Re-verification summary (claims vs source of truth, v13.33.0)

Every claim in inventory §2.6 and [declarative-model.md](declarative-model.md) §1–§2.6 re-verified by direct source inspection of `src/` and `vendor/` on the date of this document:

| # | Claim | Verdict | Evidence |
|---|---|---|---|
| 1.1 | 25 manifest keys (reserved `class` + 21 `Model` property keys incl. `observables` + 3 out-of-instance methods), including `refreshes` | **Verified** | `src/Model.php`; `refreshes` is native: `protected array $refreshes = []` (`Model.php:115`), read by `refreshSavedAttributes()` (`Model.php:1154,1334`) |
| 1.2 | Every key name is the native `Model`/trait property name; defaults match (`guarded: ['*']`, `timestamps: true`, `perPage: 15`, `keyType: 'int'`, `incrementing: true`, rest `[]`/`null`) | **Verified** | `Model.php:63-129`; `HasAttributes.php:60,88,144,151`; `GuardsAttributes.php:18,25`; `HidesAttributes.php:16,23`; `HasTimestamps.php:18` (`public $timestamps = true`); `HasEvents.php:23,32`; `HasRelationships.php:42` |
| 1.3 | Property injection runs **before** `bootIfNotBooted()`, so every Laravel initializer starts from the declared value | **Verified** | `DeclaredModel::__construct()` loop, then `parent::__construct()`; `Model::__construct()` order is `bootIfNotBooted()` → `initializeTraits()` → `initializeModelAttributes()` → `syncOriginal()` → `fill()` (`Model.php:325-332`) |
| 1.4 | A class-body `casts()` method merges **over** the declared `casts` — one key suffices | **Verified** | `initializeHasAttributes()`: `$this->casts = $this->ensureCastsAreStringValues(array_merge($this->casts, $this->casts()));` (`HasAttributes.php:207-211`); pinned by `DeclaredModelTest` (`['delayed' => 'boolean', 'options' => 'array', 'departed_at' => 'datetime']`) |
| 1.5 | `observe` / `addGlobalScope` ride Laravel's own boot seams (`resolveObserveAttributes()` / `resolveGlobalScopeAttributes()`), with the grandchild merge intact | **Verified** | `bootHasEvents()`: `static::whenBooted(fn () => static::observe(static::resolveObserveAttributes()));` (`HasEvents.php:39-49`); `bootHasGlobalScopes()`: `static::addGlobalScopes(static::resolveGlobalScopeAttributes());` (`HasGlobalScopes.php:22-32`) |
| 1.6 | `timestamps` is the only key Laravel reads from the **class default** outside the instance (`get_class_vars`), so `isIgnoringTouch()` must be overridden | **Verified** | `isIgnoringTouch($class = null)` (`Model.php:551-568`) resolves `#[Table(timestamps)]` / `#[WithoutTimestamps]` first and falls back to `get_class_vars($class)['timestamps']` (`Model.php:559-561`); called as `$model::isIgnoringTouch()` by `Relation::touch()` |
| 1.7 | `getRouteKeyName` feeds `router.model` through `resolveRouteBindingQuery()` | **Verified** | `getRouteKeyName()` returns `static::resolveClassAttribute(RouteKey::class, 'key') ?? $this->getKeyName();` (`Model.php:2516-2519`) |
| 1.8 | A Closure-defined `belongsTo` misnames itself (`{closure:…}` relation name and foreign key) — relations cannot be manifest keys | **Verified** | `guessBelongsToRelation()` returns `$caller['function']` from `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)` (`HasRelationships.php:507-512`) |
| 1.9 | Accessors/mutators are class methods (`Attribute::make(get, set)`) | **Verified** | `public static function make(?callable $get = null, ?callable $set = null): static` (`Casts/Attribute.php:53-56`) |
| 1.10 | Local scopes are class methods; a class-string scope registers through native `addGlobalScope()` and everything else throws `InvalidArgumentException` | **Verified** | `addGlobalScope($scope, $implementation = null)` — non-`Scope` strings throw `'Global scope must be an instance of Closure or Scope or be a class name of a class extending Illuminate\Database\Eloquent\Scope'` (`HasGlobalScopes.php:63-76`, throw at 75) |
| 1.11 | Bad observer references fail with Laravel's own exception at first construction | **Verified** | `resolveObserverClassName()` throws `'Unable to find observer: '.$class` (`HasEvents.php:123`); raised inside `observe()` during boot |
| 1.12 | `booted()`/`booting()` are static per-class Closure hooks; `observe` already covers event registration declaratively | **Verified** | `bootIfNotBooted()` calls `static::booting(); static::boot(); … static::booted();` (`Model.php:341-372`) |
| 1.13 | `prunable()` requires the `Prunable`/`MassPrunable` trait in the class | **Verified** | `Prunable::prunable(): Builder` + `pruneAll(int $chunkSize = 1000)` (`Prunable.php:20,57`); `MassPrunable.php:16,48` |
| 1.14 | Unknown keys and non-`DeclaredModel` classes are **ignored at runtime** (commit `78e8637` removed the runtime `validate()`); the schema remains the authoring-time guard | **Verified** | `src/Model.php` has no `validate()`; `class` is `#[Key, Describe([Describe::required => true])]` only; `tests/Feature/DeclaredModelTest.php` pins both behaviors (`ignores unknown model keys`, `ignores a class that is not a DeclaredModel`); `manifest.schema.json` `definitions.model` still carries `additionalProperties: false`, and `ValidateCommand` enforces it (`src/Internal/Commands/ValidateCommand.php:43-44`) |
| 1.15 | A missing `class` still fails at manifest read | **Verified** | `Describe::required => true` → `PropertyRequiredException: Property `$class` is required.` during `Manifest::from()` in `LaravelDeclarationProvider::register()` (`zero-to-prod/data-model` `src/DataModel.php:258-279`) |
| 1.16 | No provider boots the `models:` block — the read happens at first construction | **Verified** | No `ModelsDeclarationServiceProvider` exists; `LaravelDeclarationProvider::register()` binds `Manifest` and registers the declaration sub-providers, none of which reads the `models:` block; `DatabaseServiceProvider::register()` calls `Model::clearBootedModels()` (`Database/DatabaseServiceProvider.php:43`) so each application re-boots |

---

## 2. Manifest surface (`models:`) — context complete

### 2.1 Design rule

The `models:` block is a **list of class bodies**. The reserved key `class` names the `DeclaredModel` subclass; **every other key is an `Illuminate\Database\Eloquent\Model` property name whose value is that property's default**, except three keys that are `Model` **method** names, because Laravel keeps those settings outside the instance: `observe`, `addGlobalScope` and `getRouteKeyName`. Rule 1 (key = Laravel method/property name) holds for all 25 keys — there is no manifest-key → native-name translation table anywhere in the package.

### 2.2 Complete example (the `models:` block of `tests/Fixtures/manifest/models.yml`)

The fixture file also carries the `routes:` block that §4's route-binding test drives; it is omitted here.

```yaml
# yaml-language-server: $schema=./../../../manifest.schema.json

router:
  model:
    flight: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Flight

models:
  - class: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Flight
    table: my_flights
    primaryKey: flight_id
    keyType: string
    incrementing: false
    dateFormat: U
    attributes:
      delayed: false
      options: '[]'
    casts:
      delayed: boolean
      options: array
    fillable: [flight_id, airline_id, name, code, status]
    hidden: [secret]
    appends: [label]
    with: [airline]
    touches: [airline]
    refreshes: [status]
    perPage: 5
    dispatchesEvents:
      created: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\FlightCreated
    observables: [boarding]
    observe:
      - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\FlightObserver
    addGlobalScope:
      - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\NotCancelled
    getRouteKeyName: code

  - class: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Airline
    connection: testing
    timestamps: false
    guarded: []
    visible: [id, name, flights_count]
    withCount: [flights]
```

### 2.3 Key → native member → signature map (all 25 keys, v13.33.0)

| Manifest key | Native member | Native signature / default | Applied at |
|---|---|---|---|
| `class` | reserved | `class-string<DeclaredModel>`, `required` | `Manifest::from()` — `PropertyRequiredException` if absent |
| `connection` | `$connection` | `protected $connection` (null → default connection) | property injection |
| `table` | `$table` | `protected $table` (null → `Str::snake(Str::pluralStudly(...))`) | property injection |
| `primaryKey` | `$primaryKey` | `protected $primaryKey = 'id'` | property injection |
| `keyType` | `$keyType` | `protected $keyType = 'int'` | property injection |
| `incrementing` | `$incrementing` | `public $incrementing = true` | property injection |
| `timestamps` | `$timestamps` | `public $timestamps = true` (`HasTimestamps.php:18`) | property injection + `isIgnoringTouch()` override |
| `dateFormat` | `$dateFormat` | `protected $dateFormat` (null → grammar's) | property injection |
| `attributes` | `$attributes` | `protected $attributes = []` | property injection |
| `casts` | `$casts` | `protected $casts = []`; class-body `casts()` merges over it (`HasAttributes.php:207-211`) | property injection |
| `fillable` | `$fillable` | `protected $fillable = []` | property injection |
| `guarded` | `$guarded` | `protected $guarded = ['*']`; `[]` is `#[Unguarded]` | property injection |
| `hidden` | `$hidden` | `protected $hidden = []` | property injection |
| `visible` | `$visible` | `protected $visible = []` | property injection |
| `appends` | `$appends` | `protected $appends = []` (names class-body accessor methods) | property injection |
| `with` | `$with` | `protected $with = []` (names class-body relation methods) | property injection |
| `withCount` | `$withCount` | `protected $withCount = []` | property injection |
| `touches` | `$touches` | `protected $touches = []` | property injection |
| `refreshes` | `$refreshes` | `protected array $refreshes = []` (`Model.php:115`) | property injection |
| `perPage` | `$perPage` | `protected $perPage = 15` | property injection |
| `dispatchesEvents` | `$dispatchesEvents` | `protected $dispatchesEvents = []` | property injection |
| `observables` | `$observables` | `protected $observables = []` | property injection |
| `observe` | `observe($classes)` | `@param object\|string[]\|string $classes` — one listener per observer method named after an observable event | boot, via `resolveObserveAttributes()` |
| `addGlobalScope` | `addGlobalScope($scope, $implementation = null)` | class-string must satisfy `is_subclass_of($scope, Scope::class)`, else `InvalidArgumentException` | boot, via `resolveGlobalScopeAttributes()` |
| `getRouteKeyName` | `getRouteKeyName()` | `(): string` — `resolveClassAttribute(RouteKey::class, 'key') ?? getKeyName()` | per call, override |

### 2.4 Reclassified as decided non-goals (the §2.6 decision record)

These six keys were proposed in the original audit and are **withdrawn**: the completed specification keeps each behavior in the model class body, so the surface is not missing — it is gated out by decision. Each row is grounded in v13.33.0 (§1 claims 1.4, 1.8–1.13):

| Withdrawn key | Native API it would shadow | Why the class body wins |
|---|---|---|
| ~~`models.relations.<name>`~~ | `hasOne()` / `hasMany()` / `belongsTo()` / `belongsToMany()` / morphs / through; `resolveRelationUsing($name, Closure)` | A relation is a method: `guessBelongsToRelation()` names the relation after the **caller's function name** (`HasRelationships.php:507-512`), and PHP 8.4 names a Closure after its file and line — a Closure-declared relation gets `{closure:…}` as its name and foreign key. The pivot / `ofMany` / `withDefault` chain syntax is a DSL the roadmap gates, and relation methods carry phpstan generics (`BelongsTo<Airline, $this>`) |
| ~~`models.casts` method dispatch~~ | `protected function casts(): array` | Laravel itself merges `casts()` over `$casts` in `initializeHasAttributes()` (`HasAttributes.php:207-211`), so the `casts` **property** key already composes with a class-body `casts()` method — two keys for one effect |
| ~~`models.attributes.<name>.Attribute`~~ | `Attribute::make(?callable $get = null, ?callable $set = null): static` (`Casts/Attribute.php:53`) | Accessors/mutators are class methods; `appends` and `with` already name them from the declaration |
| ~~`models.scopes.<name>`~~ | `scope{Name}(Builder $query, ...): void` | Local scopes are class methods (`#[Scope]`); their dynamic, request-shaped arguments stay in them per [declarative-query.md](declarative-query.md) |
| ~~`models.booted`~~ | `static::booting()` / `static::booted(): void` (`Model.php:341-372`) | Static per-class hooks with Closure bodies; `observe` already registers event listeners declaratively — two keys for one effect |
| ~~`models.prunable`~~ | `prunable(): Builder` + `Model::pruneAll(int $chunkSize = 1000)` (`Prunable.php:20,57`) | Requires the `Prunable`/`MassPrunable` trait `use`d in the class; without the trait the method is dead |

Custom cast **classes** remain usable as string cast values (`'completed' => App\Casts\Boolean::class` through the `casts` property), so the audit's cast-class sub-gap narrows to nothing. The remaining §2.6 notes carry over unchanged: traits compose after the declared properties (`SoftDeletes` still adds `deleted_at`), `CREATED_AT`/`UPDATED_AT` are PHP constants, `#[CollectedBy]`/`#[UseEloquentBuilder]` set the types `all()`/`query()` return and stay on the class, `Model::shouldBeStrict()`-style statics are global to every model (a future `eloquent:` block, mirroring `router:`), `observe` covers the `Flight::created()` registrars, vendor models (`extends Model`) cannot read an entry, subclasses need their own entry (YAML anchors compose), and phpstan reads the class, not the manifest.

**Phase 2 consequence** (inventory §2.7): dynamic model synthesis inherits only the declared property/method keys. Relations, accessors/mutators, local scopes, `casts()`, `booted()` and `prunable()` require PHP on disk — the roadmap Phase 2 "full feature parity" claim stays scoped down.

---

## 3. Implementation — complete code (as shipped; no changes)

Two files carry the entire subsystem. No `ModelsDeclarationServiceProvider` exists and none is needed: the declaration is read lazily at the first `new static` of each model, and `DatabaseServiceProvider::register()` (`Model::clearBootedModels()`) makes each application boot its own observers and scopes.

### 3.1 `src/Model.php` — the DataModel (25 keys, complete)

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\ClassDefault;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Key;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Model
{
    use DataModel;

    /** @var class-string<DeclaredModel> */
    #[Key, Describe([Describe::required => true])]
    public string $class;

    public const string connection = 'connection';

    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?string $connection;

    public const string table = 'table';

    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?string $table;

    public const string primaryKey = 'primaryKey';

    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?string $primaryKey;

    public const string keyType = 'keyType';

    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?string $keyType;

    public const string incrementing = 'incrementing';

    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?bool $incrementing;

    public const string timestamps = 'timestamps';

    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?bool $timestamps;

    public const string dateFormat = 'dateFormat';

    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?string $dateFormat;

    public const string attributes = 'attributes';

    /** @var array<string, mixed>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $attributes;

    public const string casts = 'casts';

    /** @var array<string, string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $casts;

    public const string fillable = 'fillable';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $fillable;

    public const string guarded = 'guarded';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $guarded;

    public const string hidden = 'hidden';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $hidden;

    public const string visible = 'visible';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $visible;

    public const string appends = 'appends';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $appends;

    public const string with = 'with';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $with;

    public const string withCount = 'withCount';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $withCount;

    public const string touches = 'touches';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $touches;

    public const string refreshes = 'refreshes';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $refreshes;

    public const string perPage = 'perPage';

    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?int $perPage;

    public const string dispatchesEvents = 'dispatchesEvents';

    /** @var array<string, class-string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $dispatchesEvents;

    public const string observables = 'observables';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $observables;

    public const string observe = 'observe';

    /** @var list<class-string> */
    #[Key, Describe([Describe::default => []])]
    public array $observe;

    public const string addGlobalScope = 'addGlobalScope';

    /** @var list<class-string> */
    #[Key, Describe([Describe::default => []])]
    public array $addGlobalScope;

    public const string getRouteKeyName = 'getRouteKeyName';

    #[Key, Describe([Describe::nullable => true])]
    public ?string $getRouteKeyName;

    /** @return array<string, mixed> */
    public function properties(): array
    {
        return array_filter(
            array_intersect_key(get_object_vars($this), array_flip(self::selected(ClassDefault::class))),
            static fn (mixed $value): bool => $value !== null,
        );
    }
}
```

The attribute roles: `#[Key]` marks a hydratable manifest key (and names the `const`); `#[ClassDefault]` marks the 21 **property-injectable** keys — `properties()` selects them by reflection (`DataModel::selected(ClassDefault::class)`), which is why `class`, `observe`, `addGlobalScope` and `getRouteKeyName` never reach the injection loop; `Describe::default => []` gives the two list keys their empty defaults; `Describe::required => true` on `class` throws `PropertyRequiredException` at hydration.

### 3.2 `src/DeclaredModel.php` — the seam (complete)

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Database\Eloquent\Model as Eloquent;

abstract class DeclaredModel extends Eloquent
{
    /** @param  array<string, mixed>  $attributes */
    public function __construct(array $attributes = [])
    {
        foreach (self::declaration()?->properties() ?? [] as $property => $value) {
            $this->{$property} = $value;
        }

        parent::__construct($attributes);
    }

    /** @return array<array-key, mixed> */
    public static function resolveObserveAttributes(): array
    {
        return [...parent::resolveObserveAttributes(), ...self::declaration()->observe ?? []];
    }

    /** @return array<array-key, mixed> */
    public static function resolveGlobalScopeAttributes(): array
    {
        return [...parent::resolveGlobalScopeAttributes(), ...self::declaration()->addGlobalScope ?? []];
    }

    public function getRouteKeyName(): string
    {
        return self::declaration()->getRouteKeyName ?? parent::getRouteKeyName();
    }

    /** @param  string|null  $class */
    public static function isIgnoringTouch($class = null): bool
    {
        return self::declaration($class)?->timestamps === false || parent::isIgnoringTouch($class);
    }

    private static function declaration(?string $class = null): ?Model
    {
        return app(Manifest::class)->models->get($class ?? static::class);
    }
}
```

### 3.3 `src/Manifest.php` — the `$models` collection

```php
public const string models = 'models';

/** @var Collection<string, Model> */
#[Describe([
    Describe::cast => [self::class, 'mapOf'],
    'type' => Model::class,
    'key_by' => 'class',
])]
public Collection $models;
```

The YAML list is hydrated by `DataModelHelper::mapOf()` into a `Collection` keyed by the entry's `class` — `DeclaredModel::declaration()` is then a single `->get(static::class)` hash lookup per construction (no I/O, nothing cached in `config:cache`/`route:cache`).

### 3.4 `manifest.schema.json` — the authoring-time guard

`definitions.model` lists exactly the 25 keys with `additionalProperties: false` and `required: ["class"]` (shipped; verified against `src/Internal/Commands/ValidateCommand.php`, which validates every manifest against this schema). Its `description` was corrected with this document — it still claimed "An unknown key throws LogicException", removed by commit `78e8637`. Since that commit the schema is the **only** unknown-key guard: `laravel-declaration:validate` rejects `fillabel: [name]` with `The property fillabel is not defined`, while the runtime ignores the same entry (§4).

### 3.5 Dynamic dispatch analysis — why there is zero per-key code

Four dispatch shapes cover all 25 keys; adding a future key is one nullable property + `#[Key, ClassDefault]` (or one override line), with no provider and no key list anywhere:

1. **Property keys (21) — native property-name dispatch.** `DeclaredModel::__construct()` is one loop: `$this->{$property} = $value`. The property name **is** the manifest key **is** the `Illuminate\Database\Eloquent\Model` property (§2.3) — Rule 1 with no translation. The key set is derived by reflection from `#[ClassDefault]`, and `properties()` drops `null`s so an absent key keeps Laravel's class default (`guarded: ['*']`, `timestamps: true`, `perPage: 15`, `keyType: 'int'`, `incrementing: true`). Because the loop runs before `parent::__construct()`, `bootIfNotBooted()` → `initializeTraits()` → `initializeModelAttributes()` all start from the declared values exactly as they would from a hand-written `protected $casts = [...]` — traits (`SoftDeletes` adds `deleted_at`) and a class-body `casts()` method compose over the declaration by Laravel's own merge order (`HasAttributes.php:207-211`).
2. **`observe` / `addGlobalScope` — dispatch through Laravel's own boot seams.** `DeclaredModel` appends the declared class-strings to `resolveObserveAttributes()` / `resolveGlobalScopeAttributes()`, the exact seams Laravel calls from `bootHasEvents()` / `bootHasGlobalScopes()` (`HasEvents.php:39-49`, `HasGlobalScopes.php:22-32`). Native `observe()` / `addGlobalScopes()` then do the registration, at the moment `#[ObservedBy]` / `#[ScopedBy]` would, with the grandchild merge intact and Laravel's own exceptions for bad references (`Unable to find observer: …`, `HasEvents.php:123`; `Global scope must be an instance of Closure or Scope…`, `HasGlobalScopes.php:75`). The dispatch mechanism is list concatenation — there is nothing to write per key.
3. **`getRouteKeyName` — one override.** Returns the declared string or falls through to `parent::getRouteKeyName()`, which is the seam `router.model` reads through `resolveRouteBindingQuery()` (`Model.php:2516-2519`, `Model.php:2615`).
4. **`timestamps` (the class-default read) — one override.** `isIgnoringTouch()` is the only place Laravel reads a class default outside the instance (`get_class_vars($class)['timestamps']`, `Model.php:561`, after the `#[Table]`/`#[WithoutTimestamps]` attribute chain at `Model.php:559-561`), so `DeclaredModel` consults the declaration first. Without it, saving a model that `touches` a `timestamps: false` model fails with `QueryException: no such column: updated_at`. Precedence: the override defers to `parent::isIgnoringTouch()` unless the declaration says `false`, so a class-level `#[Table(timestamps: false)]`/`#[WithoutTimestamps]` attribute beats an explicit `timestamps: true` — the declaration can only turn touching **off**.

Every failure mode is Laravel's own, at first use — the declaration is not a validation layer: unknown keys are dead configuration ignored at runtime and caught at authoring time by the schema (§3.4); a missing `class` is a `PropertyRequiredException` at manifest read (§1 claim 1.15); bad observer/scope class-strings throw Laravel's `InvalidArgumentException` at first construction (§1 claims 1.10–1.11).

Why the two remaining overrides are not collapsible: v13.33.0 resolves every one of these settings natively through PHP **class attributes** (`#[RouteKey]`, `#[Table]`/`#[WithoutTimestamps]`, …) read by `resolveClassAttribute()` into the `static::$classAttributes` cache (`Model.php:2747-2786`). Priming that cache from the declaration would delete both overrides, but it couples to an internal cache-key format, and `isIgnoringTouch()` is callable statically before any construction — so the priming could not cover the exact path the `timestamps` key exists for. The overrides use `getRouteKeyName()`/`isIgnoringTouch()` themselves, the stable public API; property injection uses the class properties; `observe`/`addGlobalScope` ride Laravel's own resolver seams. That is the clean mapping — there is no smaller one.

### 3.6 Documentation corrections (the only deltas this plan ships)

Commit `78e8637` ("Remove validation methods and allow unknown keys in declaration blocks") removed the runtime `validate()` after [declarative-model.md](declarative-model.md) was written; four claims there are stale relative to `src/` (§1 claim 1.14) and the shipped tests. Apply these corrections — no code change:

1. **§1.1 lifecycle diagram** — replace the line
   `resolveManifest()  Manifest::$models hydrated; Model::validate() per entry  <- unknown key, non-DeclaredModel class: LogicException HERE`
   with
   `resolveManifest()  Manifest::$models hydrated                              <- missing class: PropertyRequiredException HERE; unknown keys: ignored`
2. **§2.5 registration algorithm** — replace "An unknown key throws `LogicException` when the manifest is read in `register()`, and so does a `class` that is not a `DeclaredModel` subclass spelled as `static::class` spells it." with "A missing `class` throws `PropertyRequiredException` when the manifest is read in `register()`. Unknown keys and entries whose `class` is not a `DeclaredModel` subclass spelled as `static::class` spells it are ignored at runtime — dead configuration; `laravel-declaration:validate` catches an unknown key at authoring time through the schema (`additionalProperties: false`)."
3. **§3.1 code block** — replace the snippet (which still shows `Describe::pre => [self::class, 'validate']` and the `validate()` method, plus the "Why the class check compares names" paragraph) with the shipped §3.1 class of this document, and delete the explanation paragraph.
4. **§2.6 "Not a validation layer"** — replace "Only the runtime `validate()` can check that `class` extends `DeclaredModel`." with "Nothing at runtime checks that `class` extends `DeclaredModel` (commit `78e8637`); an entry naming a plain `Model` subclass is ignored — dead configuration the schema cannot see."

The gap inventory §2.6 "Resolution" pointer then reads: resolved by [declarative-model.md](declarative-model.md) §2.6 as corrected by this document — §2.6 closes, §1 row 9 and §3.8 reclassify `[/]` → `[x]`.

---

## 4. Tests (complete examples; shipped)

`tests/Feature/DeclaredModelTest.php` pins the full surface with fixture `tests/Fixtures/manifest/models.yml` (§2.2). The two tests that pin the post-`78e8637` behavior, complete:

```php
it('ignores unknown model keys', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, <<<'YAML'
        models:
          - class: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Flight
            fillabel: [name]
        YAML);

    expect($this->withConfig(['laravel-declaration.manifest' => $file]))->not->toBeNull();
});

it('ignores a class that is not a DeclaredModel spelled as static::class', function (string $class): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, "models:\n  - class: '$class'\n");

    expect($this->withConfig(['laravel-declaration.manifest' => $file]))->not->toBeNull();
})->with([
    'an Eloquent model' => User::class,
    'a leading backslash' => '\\'.Flight::class,
    'another case' => strtolower(Flight::class),
    'a missing class' => 'App\Models\Missing',
]);
```

The remaining pinned behaviors, by test: every declared property reaches its native getter before Laravel initializes (`assigns every declared property…`, including `getCasts()` composed with the class-body `casts()`); Laravel defaults survive without an entry (`keeps Laravel defaults…`); persistence through the declared table/casts/observer/event/`refreshes`; boot-time observers and global scopes with the grandchild merge and the `NotCancelled` scope reaching `router.model` binding (`/flights/AA200` → 404); the `isIgnoringTouch()` override (`does not touch a parent whose timestamps are declared false`); and Laravel's own tooling seeing the declaration (`model:show --json` reports the declared table, events and observers). The non-goals are pinned by absence: relations/accessors/scopes/`booted()`/`prunable()` live in the fixture classes (`tests/Fixtures/App/Models/*`) as ordinary PHP methods.

---

## 5. Execution order

1. **No `src/` change.** The surface is complete as shipped: `src/Model.php` (§3.1), `src/DeclaredModel.php` (§3.2), `Manifest::$models` (§3.3), `manifest.schema.json` `definitions.model` (§3.4). Any future key is one attribute-marked nullable property (property dispatch) or one override (method dispatch) — no provider, no key list.
2. **Documentation corrections** — the four stale claims of [declarative-model.md](declarative-model.md) (§3.6), then the one-line "Resolution" pointer in [declarative-tier1-gap-inventory.md](declarative-tier1-gap-inventory.md) §2.6.
3. **`composer check`** — the Definition-of-Done gate. Pre-existing failure mode (inventory §5.6: 99.7% coverage from the `providers:`/`routes:` guard early-returns) is unrelated to `models:` and is remediated by its own plan item; `DeclaredModelTest` covers this subsystem fully.

---

## 6. Sources

1. Constructor order, `bootIfNotBooted()`, `bootTraits()`, `initializeModelAttributes()`, `isIgnoringTouch()` (`get_class_vars`), `$refreshes`, `refreshSavedAttributes()`, `getRouteKeyName()`, `resolveRouteBindingQuery()`, `resolveClassAttribute()` — [Eloquent/Model.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Model.php)
2. `$attributes`, `$casts`, `casts()`, `initializeHasAttributes()` (the `array_merge($this->casts, $this->casts())` composition), `$dateFormat`, `$appends` — [Concerns/HasAttributes.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php); `$fillable`/`$guarded` — [Concerns/GuardsAttributes.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php); `$hidden`/`$visible` — [Concerns/HidesAttributes.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Concerns/HidesAttributes.php); `public $timestamps = true` — [Concerns/HasTimestamps.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Concerns/HasTimestamps.php)
3. `$dispatchesEvents`, `$observables`, `bootHasEvents()`, `resolveObserveAttributes()`, `observe()`, `registerObserver()`, `'Unable to find observer: …'` — [Concerns/HasEvents.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Concerns/HasEvents.php)
4. `bootHasGlobalScopes()`, `resolveGlobalScopeAttributes()`, `addGlobalScope()` and its `InvalidArgumentException` — [Concerns/HasGlobalScopes.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Concerns/HasGlobalScopes.php)
5. `$touches`, `guessBelongsToRelation()` (the `{closure:…}` misnaming), `resolveRelationUsing()` — [Concerns/HasRelationships.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Concerns/HasRelationships.php); `Relation::touch()` calls `$model::isIgnoringTouch()` — [Relations/Relation.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Relations/Relation.php)
6. `Attribute::make(?callable $get = null, ?callable $set = null): static` — [Eloquent/Casts/Attribute.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Casts/Attribute.php); `Scope<TModel>` — [Eloquent/Scope.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Scope.php); the `Eloquent/Attributes/*` class-attribute seams (`#[Table]`, `#[Connection]`, `#[WithoutTimestamps]`, `#[Refreshes]`, `#[RouteKey]`, `#[ObservedBy]`, `#[ScopedBy]`, …) read through `resolveClassAttribute()`; `prunable()` / `pruneAll(int $chunkSize = 1000)` — [Eloquent/Prunable.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Prunable.php), [Eloquent/MassPrunable.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/MassPrunable.php); `initializeSoftDeletes()` — [Eloquent/SoftDeletes.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/SoftDeletes.php)
7. `clearBootedModels()` in `register()` — [Database/DatabaseServiceProvider.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/DatabaseServiceProvider.php); `model:show` constructs the model — [Eloquent/ModelInspector.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/ModelInspector.php)
8. This repository: `src/Model.php`, `src/DeclaredModel.php`, `src/Manifest.php`, `src/LaravelDeclarationProvider.php` (`resolveManifest()`), `src/Internal/Commands/ValidateCommand.php`, `src/Internal/DataModel.php` (`selected()`), `manifest.schema.json`, `tests/Feature/DeclaredModelTest.php`, `tests/Fixtures/manifest/models.yml`; commit `78e8637` (validation removal); `zero-to-prod/data-model` `src/DataModel.php` (`required` → `PropertyRequiredException`); `zero-to-prod/data-model-helper` `src/DataModelHelper.php` (`mapOf`, `key_by`)