# Declarative Model — `Illuminate\Database\Eloquent\Model` Class Body, Boot Registration & Manifest Schema

Source of truth: `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php` (`laravel/framework` v13.33.0), with `Eloquent/Concerns/HasAttributes.php`, `Concerns/GuardsAttributes.php`, `Concerns/HidesAttributes.php`, `Concerns/HasTimestamps.php`, `Concerns/HasEvents.php`, `Concerns/HasGlobalScopes.php`, `Concerns/HasRelationships.php`, `Eloquent/HasCollection.php`, `Eloquent/SoftDeletes.php`, `Eloquent/Attributes/*.php`, `Eloquent/Relations/Relation.php`, `Eloquent/ModelInspector.php`, `Database/DatabaseServiceProvider.php` and `Routing/RouteBinding.php`.

Goal: a `models:` block in `manifest/app.yml` whose **entries are the class bodies of Eloquent models**. The reserved key `class` names the model. **Every other key is a `Model` property name, and its value is that property's default.** Three keys are `Model` method names instead, because Laravel keeps that setting outside the instance: `observe`, `addGlobalScope` and `getRouteKeyName`. The package ships one abstract seam class, `DeclaredModel extends Model` (roadmap rule 5), and one DataModel. The provider gains no code. `DeclaredModel` finds its entry by `static::class` and applies each key at the point where Laravel applies the class attribute that writes the same setting (§1.1). Behavior stays PHP in the model class: relations, accessors and local scopes (§2.6).

---

## 1. Public API of `Model::class`

### 1.1 Lifecycle position (when the declaration is read)

```php
// LaravelDeclarationProvider::register()
//     resolveManifest()                      Manifest::$models hydrated                              <- missing class: PropertyRequiredException HERE; unknown keys: ignored
//     instance(Manifest::class, $Manifest)
// DatabaseServiceProvider::register()        Model::clearBootedModels(): every model boots again in this application
// DatabaseServiceProvider::boot()            Model::setEventDispatcher($app['events'])

// every `new static` of a DeclaredModel: new Flight, Flight::query(), newInstance(), newFromBuilder() per hydrated row
DeclaredModel::__construct($attributes)
    $this->{$property} = $value               <- property keys HERE, before anything else: the class-body defaults
    Model::__construct($attributes)           Model.php:325
        bootIfNotBooted()                     once per class per application (Model.php:341):
            bootTraits()
                bootHasGlobalScopes()         addGlobalScopes(resolveGlobalScopeAttributes())          <- `addGlobalScope`   (#[ScopedBy])
                bootHasEvents()               whenBooted(fn () => observe(resolveObserveAttributes()))  <- `observe`          (#[ObservedBy]), after booted()
        initializeTraits()                    initializeHasAttributes(): $casts = $casts + casts(); #[DateFormat], #[Appends]
                                              initializeGuardsAttributes(), initializeHidesAttributes(), initializeHasTimestamps(),
                                              initializeHasRelationships(), then each trait's initializer (SoftDeletes, HasUuids, ...)
        initializeModelAttributes()           #[Table], #[Connection], #[WithoutIncrementing], #[Refreshes] (Model.php:444)
        syncOriginal(); fill($attributes)

// per call
getRouteKeyName()                             <- `getRouteKeyName` (#[RouteKey]): router.model binding, route() URLs
isIgnoringTouch()                             <- `timestamps`, when another model touches this one (Relation::touch())
```

Consequences, each verified against v13.33.0 with Testbench:

1. **Exactly the class body.** The loop runs before `bootIfNotBooted()`, so every Laravel initializer starts from the declared value, as it would start from a hand-written `protected $casts = [...]`.
   - A `casts()` method in the class is merged over the declared `casts`. The fixture gets `['delayed' => 'boolean', 'options' => 'array', 'departed_at' => 'datetime']`.
   - `SoftDeletes` still adds `deleted_at`: `casts: {payload: array}` with `use SoftDeletes` gives `['id' => 'int', 'payload' => 'array', 'deleted_at' => 'datetime']`.
   - Assigning after `parent::__construct()` instead drops the `casts()` entries. That mutation fails §3.8's first test.
2. **Read on every construction.** Eloquent creates every instance with `new static` (`newInstance()`, and `newFromBuilder()` once per hydrated row). Each one reads its entry with `app(Manifest::class)->models->get(static::class)`: a container instance lookup and a hash lookup, with no I/O. Nothing is written to `config:cache` or `route:cache`.
3. **Booted once per class per application.** `DatabaseServiceProvider::register()` calls `Model::clearBootedModels()` (line 43), so each application registers the declared observers and scopes on its own dispatcher. The §3.8 tests refresh the application for every test and still observe.
4. **Laravel's own tooling sees the declaration.** `php artisan model:show` (`ModelInspector`) reports the declared `table`, `fillable`, `hidden`, `casts`, `dispatchesEvents` and observers, because it constructs the model.

### 1.2 Properties

**Declaration targets.** Every row is a property Laravel documents as overridable in the class body, or writes from a v13 class attribute.

| Property | Declared on | Default | Class attribute that writes it | Read by |
|---|---|---|---|---|
| `$connection` | `Model` | `null` (the default connection) | `#[Connection]` (`??=`) | `getConnectionName()` |
| `$table` | `Model` | `null` → `Str::snake(Str::pluralStudly(class_basename))` | `#[Table(name)]` (replaces an inherited value, §1.4.4) | `getTable()` |
| `$primaryKey` | `Model` | `'id'` | `#[Table(key)]` (only while `'id'`) | `getKeyName()` |
| `$keyType` | `Model` | `'int'` | `#[Table(keyType)]` (only while `'int'`) | `getKeyType()`, `getCasts()` |
| `$incrementing` | `Model` | `true` | `#[WithoutIncrementing]`, `#[Table(incrementing)]` | `getIncrementing()`, `getCasts()`, `performInsert()` |
| `$timestamps` | `HasTimestamps` | `true` | `#[WithoutTimestamps]`, `#[Table(timestamps)]` (only while `true`) | `usesTimestamps()`; the **class default** in `isIgnoringTouch()` (§1.4.5) |
| `$dateFormat` | `HasAttributes` | `null` → the grammar's | `#[DateFormat]`, `#[Table(dateFormat)]` (`??=`) | `getDateFormat()` |
| `$attributes` | `HasAttributes` | `[]` | — | constructor, `syncOriginal()`, `getAttributes()` |
| `$casts` | `HasAttributes` | `[]` | — (`casts()` method merged over it) | `getCasts()` |
| `$fillable` | `GuardsAttributes` | `[]` | `#[Fillable]` (merged) | `isFillable()`, `getFillable()` |
| `$guarded` | `GuardsAttributes` | `['*']` | `#[Guarded]`, `#[Unguarded]` (only while `['*']`) | `getGuarded()` |
| `$hidden` | `HidesAttributes` | `[]` | `#[Hidden]` (merged) | `getHidden()`, `toArray()` |
| `$visible` | `HidesAttributes` | `[]` | `#[Visible]` (merged) | `getVisible()`, `toArray()` |
| `$appends` | `HasAttributes` | `[]` | `#[Appends]` (merged) | `getAppends()`, `toArray()` |
| `$with` | `Model` | `[]` | — | `newQueryWithoutScopes()` (Model.php:1944) |
| `$withCount` | `Model` | `[]` | — | `newQueryWithoutScopes()` (Model.php:1945) |
| `$touches` | `HasRelationships` | `[]` | `#[Touches]` | `getTouchedRelations()`, `touchOwners()` |
| `$refreshes` | `Model` | `[]` | `#[Refreshes]` (only while `[]`) | `refreshSavedAttributes()` (Model.php:1723) |
| `$perPage` | `Model` | `15` | — | `getPerPage()` |
| `$dispatchesEvents` | `HasEvents` | `[]` | — | `fireCustomModelEvent()`, `dispatchesEvents()` |
| `$observables` | `HasEvents` | `[]` | — | `getObservableEvents()` (and `observe()` through it) |

**Not declaration targets**

| Property | Why no key |
|---|---|
| `$exists`, `$wasRecentlyCreated`, `$original`, `$changes`, `$previous`, `$relations`, `$classCastCache`, `$attributeCastCache`, `$relationAutoloadCallback`, `$relationAutoloadContext`, `$forceDeleting` | runtime state |
| `$preventsLazyLoading` | set per hydrated model from the global `Model::preventsLazyLoading()` (Builder.php:500) |
| `$escapeWhenCastingToString` | toggled at runtime by `escapeWhenCastingToString()` |
| `$usesUniqueIds` | written by the `HasUniqueIds` initializer (`HasUuids`, `HasUlids`) |
| static `$collectionClass`, `$builder` | declared once, on `Model`, so assigning either from a subclass changes it for every model. Their per-class forms are `#[CollectedBy]` / `#[UseEloquentBuilder]` (§2.6) |
| static `$unguarded`, `$snakeAttributes`, `$encrypter`, `$resolver`, `$dispatcher`, `$modelsShouldPreventLazyLoading`, ... | global to every model |
| `CREATED_AT`, `UPDATED_AT` (and `SoftDeletes`' `DELETED_AT`) | PHP constants, read as `static::CREATED_AT`: nothing can assign them |

### 1.3 Public methods

**Declaration targets**

| Method | Signature | Effect |
|---|---|---|
| `observe` | `static ($classes): void`. `@param object\|string[]\|string $classes` | `new static`, then `registerObserver()` per class: one `eloquent.{event}: {Model}` listener `Observer@event` per observer method named after an observable event |
| `addGlobalScope` | `static ($scope, $implementation = null): mixed` | a class-string `$scope` that `is_subclass_of(Scope::class)` is stored as `new $scope`, keyed by its class. Anything else without an `$implementation` throws `InvalidArgumentException` |
| `getRouteKeyName` | `(): string` | `resolveClassAttribute(RouteKey::class, 'key') ?? getKeyName()` |

**Overridden by `DeclaredModel`, no key**

| Method | Why |
|---|---|
| `resolveObserveAttributes` / `resolveGlobalScopeAttributes` | `public static`. Laravel's own seams for `#[ObservedBy]` / `#[ScopedBy]`: `bootHasEvents()` / `bootHasGlobalScopes()` pass their return value to `observe()` / `addGlobalScopes()` |
| `isIgnoringTouch` | `public static ($class = null): bool`. Reads the `timestamps` **class default** with `get_class_vars()` (§1.4.5) |

**Not declaration targets**

| Method | Why no key |
|---|---|
| `addGlobalScopes` | `foreach ($scopes as $scope) static::addGlobalScope($scope)`. The `addGlobalScope` list is that loop (the `patterns` rule, declarative-router.md §2.6) |
| `casts()` | runs in `initializeHasAttributes()` and is merged over `$casts`. One map, one key: `casts` |
| `getTable` / `getKeyName` / `getKeyType` / `getIncrementing` / `getConnectionName` / `getDateFormat` / `getPerPage` / `getFillable` / ... | getters of §1.2's properties. The property is the key |
| `resolveRelationUsing` | `static ($name, Closure $callback)`. Needs a Closure, and a Closure-defined `belongsTo` / `morphTo` misnames itself (§1.4.7, §2.6) |
| `hasOne` / `hasMany` / `belongsTo` / `belongsToMany` / `morphTo` / `morphMany` / `hasManyThrough` / ... | called from a relation method in the class (§2.6) |
| `creating` / `created` / `saving` / ... (`registerModelEvent()`) | `observe` already registers one listener per event method. Two keys for one effect |
| `newCollection` / `newEloquentBuilder` (`#[CollectedBy]`, `#[UseEloquentBuilder]`) | they set the types `all()` and `query()` return, which the code calls. Types belong to the class (§2.6) |
| `newFactory` (`#[UseFactory]`, `HasFactory`), `prunable` (`Prunable`), `newUniqueId` / `uniqueIds` (`HasUuids`) | need a trait in the class |
| `shouldBeStrict` / `preventLazyLoading` / `preventSilentlyDiscardingAttributes` / `preventAccessingMissingAttributes` / `automaticallyEagerLoadRelationships` / `unguard` / `handle*ViolationUsing` | static on `Model`: global to every model, not one model's body (§2.6) |
| `withoutTouching` / `withoutTimestamps` / `withoutEvents` / `withoutBroadcasting` | take a callback: runtime scopes |
| `query` / `all` / `find` / `create` / `save` / `fill` / `toArray` / ... | runtime |
| `clearBootedModels` / `setConnectionResolver` / `setEventDispatcher` / `flushEventListeners` | framework plumbing |
| `macro`-style `Builder::macro()` | not `Model` |

**Class attributes (v13) and their key**

| Attribute | Key | Attribute | Key |
|---|---|---|---|
| `#[Table(name, key, keyType, incrementing, timestamps, dateFormat)]` | `table`, `primaryKey`, `keyType`, `incrementing`, `timestamps`, `dateFormat` | `#[Touches]` | `touches` |
| `#[Connection]` | `connection` | `#[Refreshes]` | `refreshes` |
| `#[WithoutIncrementing]` | `incrementing: false` | `#[ObservedBy]` | `observe` |
| `#[WithoutTimestamps]` | `timestamps: false` | `#[ScopedBy]` | `addGlobalScope` |
| `#[DateFormat]` | `dateFormat` | `#[RouteKey]` | `getRouteKeyName` |
| `#[Fillable]` / `#[Guarded]` / `#[Unguarded]` | `fillable` / `guarded` / `guarded: []` | `#[CollectedBy]`, `#[UseEloquentBuilder]` | none (§2.6) |
| `#[Hidden]` / `#[Visible]` / `#[Appends]` | `hidden` / `visible` / `appends` | `#[UseFactory]`, `#[UsePolicy]`, `#[UseResource]`, `#[UseResourceCollection]` | none: other APIs (§2.6) |
| `#[Scope]`, `#[Boot]`, `#[Initialize]` | none: they mark methods | | |

### 1.4 How a declaration reaches a model

```php
// Model.php:325
public function __construct(array $attributes = [])
{
    $this->bootIfNotBooted();
    $this->initializeTraits();
    $this->initializeModelAttributes();
    $this->syncOriginal();
    $this->fill($attributes);
}

// HasAttributes.php:207 — a trait initializer: starts from $this->casts, the class-body value
protected function initializeHasAttributes()
{
    $this->casts = $this->ensureCastsAreStringValues(array_merge($this->casts, $this->casts()));
    $this->dateFormat ??= static::resolveClassAttribute(DateFormat::class, 'format') ?? /* #[Table(dateFormat)] */ null;
    $this->mergeAppends(static::resolveClassAttribute(Appends::class, 'columns') ?? []);
}

// Model.php:444 — after the trait initializers
public function initializeModelAttributes()
{
    $table = static::resolveClassAttribute(Table::class);
    $declaresTable = /* the class itself declares `protected $table` */;
    if (! $declaresTable && $reflection->getAttributes(Table::class) !== []) {
        $this->table = $table->name ?? null;                      // an inherited or assigned $table is replaced
    } else {
        $this->table ??= $table->name ?? null;
    }
    $this->connection ??= static::resolveClassAttribute(Connection::class, 'name');
    // primaryKey only while 'id', keyType only while 'int', incrementing, refreshes only while []
}

// HasEvents.php:39 / :49 — Laravel's #[ObservedBy] seam
public static function bootHasEvents()
{
    static::whenBooted(fn () => static::observe(static::resolveObserveAttributes()));
}
public static function resolveObserveAttributes()
{
    // #[ObservedBy] arguments of static::class; for an "Eloquent grandchild" (parent is not Model),
    // merged after get_parent_class(static::class)::resolveObserveAttributes()
}

// HasGlobalScopes.php:22 / :63 — Laravel's #[ScopedBy] seam
public static function bootHasGlobalScopes()
{
    static::addGlobalScopes(static::resolveGlobalScopeAttributes());   // same grandchild merge
}
public static function addGlobalScope($scope, $implementation = null)
{
    // ...
    } elseif (is_string($scope) && class_exists($scope) && is_subclass_of($scope, Scope::class)) {
        return static::$globalScopes[static::class][$scope] = new $scope;
    }
    throw new InvalidArgumentException('Global scope must be an instance of Closure or Scope or be a class name of a class extending '.Scope::class);
}

// Model.php:551 — called as $model::isIgnoringTouch() by Relation::touch() (Relation.php:297)
public static function isIgnoringTouch($class = null)
{
    $class = $class ?: static::class;
    if (! $class::UPDATED_AT) return true;
    $timestamps = /* #[Table(timestamps)] */ ?? /* #[WithoutTimestamps] */ ?? get_class_vars($class)['timestamps'];   // the CLASS default
    if (! $timestamps) return true;
    return array_any(static::$ignoreOnTouch, /* ... */);
}

// Model.php:2615 — router.model: RouteBinding::forModel() -> resolveRouteBinding($value) -> here
public function resolveRouteBindingQuery($query, $value, $field = null)
{
    return $query->where($field ?? $this->getRouteKeyName(), $value);
}

// HasRelationships.php:507 — belongsTo() and morphTo() call this when no name is given
protected function guessBelongsToRelation()
{
    [, , $caller] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
    return $caller['function'];
}
```

Consequences, each verified against v13.33.0 with Testbench:

1. **An absent key keeps Laravel's default.** `DataModel` hydrates an absent property key to `null`, and `Model::properties()` drops `null`s. So `guarded` stays `['*']`, `timestamps` stays `true` and `perPage` stays `15`. Every property whose Laravel default is `null` (`connection`, `table`, `dateFormat`) loses nothing by this. A `DeclaredModel` with no entry is a plain Eloquent model: `Undeclared` gets the table `undeclareds` and the route key `id`.
2. **`observe` and `addGlobalScope` run at boot, through Laravel's calls.** `DeclaredModel` appends the declared classes to `resolveObserveAttributes()` / `resolveGlobalScopeAttributes()`, so Laravel's own `observe()` / `addGlobalScopes()` register them, at the moment it registers `#[ObservedBy]` / `#[ScopedBy]`. Bad references fail at the first `new` of the model, with Laravel's exceptions:
   - `observe: [App\Observers\Missing]` fails with `InvalidArgumentException: Unable to find observer: App\Observers\Missing`,
   - `addGlobalScope: [App\Scopes\Missing]`, or a class that is not a `Scope`, fails with `InvalidArgumentException: Global scope must be an instance of Closure or Scope or be a class name of a class extending Illuminate\Database\Eloquent\Scope`.
   The scope error is thrown inside `bootTraits()`, so `Model::$booting[static::class]` is never unset. `clearBootedModels()` does not clear it. For the rest of the PHP process (a queue worker, Octane, a test run), every later `new` of that class fails with `LogicException: The [Illuminate\Database\Eloquent\Model::bootIfNotBooted] method may not be called on model [...] while it is being booted.` A bad `#[ScopedBy]` does the same. The observer error is thrown after boot completes, so it does not poison the class.
3. **The global scope reaches route binding.** `router.model` resolves through `resolveRouteBindingQuery()` on the model's own query, so `getRouteKeyName: code` makes `/flights/AA100` look up `code = 'AA100'`, and `NotCancelled` makes a cancelled flight a 404 (§3.8). This is also how `getRouteKeyName` gives `router.model` a binding field, which declarative-router-bindings.md §1.4.2 says `model` cannot take from `{key:field}`.
4. **A `#[Table]` attribute on the class discards the declared `table`.** `initializeModelAttributes()` replaces a `$table` that the class did not declare itself whenever the class has `#[Table]`, even `#[Table(key: 'uuid')]`, which has no name. With `table: my_attributed` declared, `#[Table(key: 'uuid')] final class Attributed extends DeclaredModel {}` gets the table `attributeds`. The other attributes merge (`#[Fillable]`, `#[Hidden]`, `#[Visible]`, `#[Appends]`), or apply only while the property still holds Laravel's default. Declare a model in one place.
5. **`isIgnoringTouch()` reads the class default, not the instance.** `get_class_vars($class)['timestamps']` returns `true` for a class whose `timestamps: false` lives in the manifest. `DeclaredModel` therefore overrides it. Without the override, saving a `Flight` that `touches: [airline]` against an `Airline` declared `timestamps: false` fails with `QueryException: SQLSTATE[HY000]: General error: 1 no such column: updated_at (... SQL: update "airlines" set "updated_at" = ... where "airlines"."id" = 1)` (the §3.8 mutation). This is the only read of a class default in `Illuminate/Database` (`get_class_vars` has no other caller).
6. **Subclasses: properties are not inherited, observers and scopes are.** The entry is found by `static::class` only. So `final class Charter extends Flight {}` without an entry of its own gets the table `charters`. It still inherits `Flight`'s declared observers and scopes, because Laravel's grandchild merge calls `Flight::resolveObserveAttributes()`, which is `DeclaredModel`'s override. That is the rule for `#[ObservedBy]` on a parent class too.
7. **A Closure-defined `belongsTo` misnames itself.** `Airline::resolveRelationUsing('owner', fn ($airline) => $airline->belongsTo(Flight::class))` gets a relation name of the form `{closure:{closure:<file>:<line>}:<line>}` and the foreign key `{closure:…}_id`: `guessBelongsToRelation()` reads the caller's function name, and PHP 8.4 names a Closure after its file and line. This is why relations are not keys (§2.6).

### 1.5 The PHP this replaces

```php
namespace App\Models;

#[Table('my_flights', key: 'flight_id', keyType: 'string', incrementing: false, dateFormat: 'U')]
#[Fillable(['flight_id', 'airline_id', 'name', 'code', 'status'])]
#[Hidden(['secret'])]
#[Appends(['label'])]
#[Touches(['airline'])]
#[Refreshes(['status'])]
#[ObservedBy([FlightObserver::class])]
#[ScopedBy([NotCancelled::class])]
#[RouteKey('code')]
final class Flight extends Model
{
    protected $attributes = ['delayed' => false, 'options' => '[]'];

    protected $with = ['airline'];

    protected $perPage = 5;

    protected $dispatchesEvents = ['created' => FlightCreated::class];

    protected $observables = ['boarding'];

    protected function casts(): array
    {
        return ['delayed' => 'boolean', 'options' => 'array', 'departed_at' => 'datetime'];
    }

    public function airline(): BelongsTo { return $this->belongsTo(Airline::class); }

    protected function label(): Attribute { return Attribute::get(fn (): string => $this->code.' '.$this->name); }
}
```

```yaml
models:
  - class: App\Models\Flight
    table: my_flights
    primaryKey: flight_id
    keyType: string
    incrementing: false
    dateFormat: U
    attributes: {delayed: false, options: '[]'}
    casts: {delayed: boolean, options: array}
    fillable: [flight_id, airline_id, name, code, status]
    hidden: [secret]
    appends: [label]
    with: [airline]
    touches: [airline]
    refreshes: [status]
    perPage: 5
    dispatchesEvents: {created: App\Events\FlightCreated}
    observables: [boarding]
    observe: [App\Observers\FlightObserver]
    addGlobalScope: [App\Models\Scopes\NotCancelled]
    getRouteKeyName: code
```

```php
final class Flight extends DeclaredModel               // what stays: behavior
{
    public function airline(): BelongsTo { return $this->belongsTo(Airline::class); }

    protected function label(): Attribute { return Attribute::get(fn (): string => $this->code.' '.$this->name); }

    protected function casts(): array { return ['departed_at' => 'datetime']; }   // optional: merged over `casts`
}
```

---

## 2. Manifest schema proposal

### 2.1 Design rule

> **A `models` entry is the class body of one `DeclaredModel` subclass.** The reserved key `class` names the subclass. Every other key is an `Illuminate\Database\Eloquent\Model` **property** name, and its value is that property's default. Three keys are `Model` **method** names instead: `observe` and `addGlobalScope` take the method's argument, and `getRouteKeyName` takes its return value. `DeclaredModel` applies every key where Laravel applies the class attribute for the same setting (§1.1).

This is the rule `requests` follows (declarative-requests.md §2.1): a key is a member name, a property key holds the property's value, and a method key holds what the method takes or returns. `redirect` there is keyed by the property, not by `#[RedirectTo]`, and `table` here is keyed by the property, not by `#[Table]`.

**Why property names, not attribute names** (`Table: {name: my_flights}`):

1. **The property is the system of record.** Every v13 attribute of §1.3 is applied by writing a §1.2 property, or through a resolver that `DeclaredModel` overrides. `attributes`, `casts`, `with`, `withCount`, `perPage`, `dispatchesEvents` and `observables` have no attribute at all.
2. **One mechanism, no translation.** Assigning the properties before `bootIfNotBooted()` is the class body, so Laravel's initializers apply their own precedence on top (§1.1.1). Attribute-shaped keys would make the package construct attribute instances, and give `WithoutTimestamps: false` a meaning Laravel does not have.
3. **Laravel's documentation of each property still applies verbatim** ("you may define an `$attributes` property on your model", eloquent.md).

**Why a seam class, not a provider loop.** Instance properties can only be assigned from inside the instance, and Eloquent creates every instance itself with `new static` (§1.1.2). A provider cannot reach them. That is roadmap rule 5 ("One seam class per Laravel base class"), which `DeclaredRequest` follows for `FormRequest`.

**Why a list keyed by `class`.** `static::class` is the only identity that survives `new static`, and `providers` already uses a `class` key. It is a list, like `providers` and `requests`, so every entry is a map of Laravel's keys and nothing else.

### 2.2 Values

| Key | Value | Laravel does | Rules |
|---|---|---|---|
| `class` | `DeclaredModel` subclass | the entry is found by `static::class` | spelled as `static::class` spells it: no leading `\`, same case — a leading `\` fails the schema (§3.4); other spellings are ignored at runtime (§2.5) |
| `connection`, `table`, `primaryKey`, `keyType`, `dateFormat` | `string` | §1.2 | — |
| `incrementing`, `timestamps` | `bool` | §1.2 | `timestamps: false` also stops touches from other models (§1.4.5) |
| `perPage` | `int` ≥ 1 | `paginate()` page size | — |
| `attributes` | `map<column, raw value>` | the new model's attributes, then `syncOriginal()` | raw, storable form: `options: '[]'` (quoted) for an `array` cast. `options: []` decodes to a PHP array |
| `casts` | `map<attribute, cast string>` | `getCasts()` | every Laravel cast string passes through: `boolean`, `array`, `datetime:Y-m-d`, `decimal:2`, `encrypted:array`, `hashed`, an enum or `CastsAttributes` class, `Class:arg,...` (what `AsCollection::using()` / `AsEnumCollection::of()` return) |
| `fillable`, `guarded`, `hidden`, `visible`, `appends`, `with`, `withCount`, `touches`, `refreshes`, `observables` | `list<string>` | §1.2 | `appends`, `with`, `withCount` and `touches` name a method in the class (an accessor or a relation) |
| `dispatchesEvents` | `map<event, class-string>` | `dispatch(new $class($model))` | — |
| `observe` | `list<class-string>` | `observe($classes)` at boot | an ordinary class whose method names are events |
| `addGlobalScope` | `list<class-string>` | `addGlobalScope($scope)` per item at boot | a class implementing `Illuminate\Database\Eloquent\Scope` |
| `getRouteKeyName` | `string` | `getRouteKeyName()` | a column |

**YAML.** Write class names plain or single-quoted, and quote a bare `*`:

| YAML | Decodes to | Result |
|---|---|---|
| `observe: [App\Observers\FlightObserver]` | `['App\Observers\FlightObserver']` | correct |
| `observe: ["App\Observers\FlightObserver"]` | `ParseException: Found unknown escape character "\O"` | the manifest fails to load |
| `casts: {published_at: datetime:Y-m-d}` | `'datetime:Y-m-d'` | correct: no space after the `:` |
| `hash: App\Casts\Hash:sha256,1` (block style, under `casts:`) | `'App\Casts\Hash:sha256,1'` | correct |
| `casts: {hash: App\Casts\Hash:sha256,1}` | `ParseException: Malformed inline YAML string` | the manifest fails to load. In a flow map, single-quote it: `{hash: 'App\Casts\Hash:sha256,1'}` |
| `attributes: {options: '[]'}` | `'[]'` | correct: the storable JSON |
| `guarded: *` | `ParseException: Reference "" does not exist` | the manifest fails to load. Write `guarded: ['*']` |

**References.** Observers, scopes and events are ordinary classes. Nothing extends a package type:

```php
namespace App\Observers;

final class FlightObserver                                  // make()d by the dispatcher: constructor DI
{
    public function creating(Flight $flight): void { /* ... */ }   // one listener per event-named method

    public function boarding(Flight $flight): void { /* ... */ }   // a custom event: listened to because `observables` declares it
}

namespace App\Models\Scopes;

/** @implements Scope<Flight> */
final class NotCancelled implements Scope                   // `new NotCancelled` at boot: no constructor arguments
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('status', '!=', 'cancelled');
    }
}

namespace App\Events;

final readonly class FlightCreated                          // dispatchesEvents: new FlightCreated($flight)
{
    public function __construct(public Flight $flight) {}
}
```

### 2.3 Full example

```yaml
router:
  model:
    flight: App\Models\Flight                    # {flight} -> Flight::resolveRouteBinding(): by getRouteKeyName, through NotCancelled

models:                                          # ——— Eloquent\Model class bodies (this document) ———
  - class: App\Models\Flight                     # reserved: `final class Flight extends DeclaredModel`
    connection: mysql                            # -> $connection ≙ #[Connection('mysql')]
    table: my_flights                            # -> $table ≙ #[Table('my_flights')]
    primaryKey: flight_id                        # -> $primaryKey ≙ #[Table(key: 'flight_id')]
    keyType: string                              # -> $keyType ≙ #[Table(keyType: 'string')]
    incrementing: false                          # -> $incrementing ≙ #[WithoutIncrementing]
    dateFormat: U                                # -> $dateFormat ≙ #[DateFormat('U')]: dates stored as Unix seconds
    attributes:                                  # -> $attributes: raw, storable defaults
      delayed: false
      options: '[]'                              # quoted: the JSON string, not a PHP array
    casts:                                       # -> $casts; a casts() method in the class is merged over it
      delayed: boolean
      options: array
      departed_at: datetime:Y-m-d
      status: App\Enums\FlightStatus             # enum cast
    fillable: [flight_id, airline_id, name, code, status]   # -> $fillable ≙ #[Fillable]
    hidden: [secret]                             # -> $hidden ≙ #[Hidden]
    appends: [label]                             # -> $appends ≙ #[Appends]; label() is an accessor in the class
    with: [airline]                              # -> $with: eager loaded by every query
    withCount: [passengers]                      # -> $withCount: passengers_count on every query
    touches: [airline]                           # -> $touches ≙ #[Touches]
    refreshes: [status]                          # -> $refreshes ≙ #[Refreshes]: re-read after each save
    perPage: 25                                  # -> $perPage
    dispatchesEvents:                            # -> $dispatchesEvents
      created: App\Events\FlightCreated
    observables: [boarding]                      # -> $observables: custom events observers may handle
    observe:                                     # -> observe($classes) at boot ≙ #[ObservedBy]
      - App\Observers\FlightObserver
    addGlobalScope:                              # -> addGlobalScope($scope) per item at boot ≙ #[ScopedBy]
      - App\Models\Scopes\NotCancelled
    getRouteKeyName: code                        # -> getRouteKeyName() ≙ #[RouteKey('code')]

  - class: App\Models\Airline                    # an empty class body in PHP: everything is here
    timestamps: false                            # -> $timestamps ≙ #[WithoutTimestamps]; Flight's touches skip it
    guarded: []                                  # -> $guarded ≙ #[Unguarded]
    visible: [id, name]                          # -> $visible ≙ #[Visible]

routes:
  - path: "flights/{flight}"
    methods: GET
    action: App\Http\Controllers\ShowFlight
    middleware: [web]                            # SubstituteBindings runs router.model
```

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ZeroToProd\LaravelDeclaration\DeclaredModel;

/**
 * @property string $code
 * @property string $name
 */
final class Flight extends DeclaredModel
{
    /** @return BelongsTo<Airline, $this> */
    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    /** @return HasMany<Passenger, $this> */
    public function passengers(): HasMany
    {
        return $this->hasMany(Passenger::class);
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => $this->code.' '.$this->name);
    }
}

final class Airline extends DeclaredModel {}
```

### 2.4 Key → member → signature map

| YAML key (per entry) | `Model` member | Value shape (YAML) | Applied (§2.5) | ≙ attribute | Absent → |
|---|---|---|---|---|---|
| `class` | — (reserved) | class-string | `models->get(static::class)` | — | required |
| `connection` | `$connection` | `string` | constructor | `#[Connection]` | default connection |
| `table` | `$table` | `string` | constructor | `#[Table(name)]` | snake plural of the basename |
| `primaryKey` | `$primaryKey` | `string` | constructor | `#[Table(key)]` | `id` |
| `keyType` | `$keyType` | `string` | constructor | `#[Table(keyType)]` | `int` |
| `incrementing` | `$incrementing` | `bool` | constructor | `#[WithoutIncrementing]` | `true` |
| `timestamps` | `$timestamps` | `bool` | constructor + `isIgnoringTouch()` | `#[WithoutTimestamps]` | `true` |
| `dateFormat` | `$dateFormat` | `string` | constructor | `#[DateFormat]` | the grammar's |
| `attributes` | `$attributes` | `map<column, raw>` | constructor | — | `[]` |
| `casts` | `$casts` | `map<attribute, cast>` | constructor | — | `[]` |
| `fillable` | `$fillable` | `list<string>` | constructor | `#[Fillable]` | `[]` |
| `guarded` | `$guarded` | `list<string>` | constructor | `#[Guarded]` / `#[Unguarded]` | `['*']` |
| `hidden` | `$hidden` | `list<string>` | constructor | `#[Hidden]` | `[]` |
| `visible` | `$visible` | `list<string>` | constructor | `#[Visible]` | `[]` |
| `appends` | `$appends` | `list<string>` | constructor | `#[Appends]` | `[]` |
| `with` | `$with` | `list<string>` | constructor | — | `[]` |
| `withCount` | `$withCount` | `list<string>` | constructor | — | `[]` |
| `touches` | `$touches` | `list<string>` | constructor | `#[Touches]` | `[]` |
| `refreshes` | `$refreshes` | `list<string>` | constructor | `#[Refreshes]` | `[]` |
| `perPage` | `$perPage` | `int` | constructor | — | `15` |
| `dispatchesEvents` | `$dispatchesEvents` | `map<event, class-string>` | constructor | — | `[]` |
| `observables` | `$observables` | `list<string>` | constructor | — | `[]` |
| `observe` | `observe($classes)` | `list<class-string>` | `resolveObserveAttributes()` | `#[ObservedBy]` | `#[ObservedBy]` only |
| `addGlobalScope` | `addGlobalScope($scope)` | `list<class-string>` | `resolveGlobalScopeAttributes()` | `#[ScopedBy]` | `#[ScopedBy]` only |
| `getRouteKeyName` | `getRouteKeyName()` | `string` | `getRouteKeyName()` | `#[RouteKey]` | `#[RouteKey]`, else the primary key |

Order: the property keys are assigned in the table's order, all before `bootIfNotBooted()`. They are independent assignments, so the order has no effect. `addGlobalScope` is applied when the model boots, and `observe` after its `booted()`. A missing `class` throws `PropertyRequiredException` when the manifest is read in `register()`. Unknown keys and entries whose `class` is not a `DeclaredModel` subclass spelled as `static::class` spells it are ignored at runtime — dead configuration; `laravel-declaration:validate` catches an unknown key at authoring time through the schema (`additionalProperties: false`). `addGlobalScopes`, `collectionClass`, `builder` and `casts()` are unknown keys (§1.3, §2.6).

### 2.5 Registration algorithm (for the provider)

There is none. `Manifest` gains one property, hydrated as `requests` is. `mapOf` with `key_by` keys the collection by `class`:

```php
// src/Manifest.php
/** @var Collection<string, Model> */
#[Describe([
    Describe::cast => [self::class, 'mapOf'],
    'type' => Model::class,
    'key_by' => 'class',
])]
public Collection $models;
```

`class` is a literal in `key_by`, as `Provider` has no constant for its `class` property: PHP reserves `const class`. An absent `models:` block hydrates to an empty collection.

`src/DeclaredModel.php` is the seam:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Database\Eloquent\Model as Eloquent;

/**
 * An Eloquent model whose class body is its `models` entry in the manifest.
 *
 * @link docs/declarative-model.md
 */
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

Each member maps to Laravel:

| Member | Laravel counterpart |
|---|---|
| `__construct()` loop | the class body: runs before `bootIfNotBooted()` and every initializer (§1.1.1) |
| `resolveObserveAttributes()` | `#[ObservedBy]`'s resolver. `bootHasEvents()` passes the result to `observe()` |
| `resolveGlobalScopeAttributes()` | `#[ScopedBy]`'s resolver. `bootHasGlobalScopes()` passes it to `addGlobalScopes()` |
| `getRouteKeyName()` | `#[RouteKey]`'s reader, and the method routing.md tells you to override |
| `isIgnoringTouch()` | the one place Laravel reads `timestamps` from the class default (§1.4.5) |
| `declaration()` | `DeclaredRequest::declaration()`: the manifest from the container, the entry by its handle |

That is the whole implementation: five members and one lookup. The provider is unchanged, because `register()` already binds the `Manifest` that `declaration()` reads (declarative-requests.md §2.5).

- **`self::declaration()`, not `static::`.** Rector's `ConvertStaticToSelfRector` rewrites calls to a private static method. `self::` is a forwarding call, so `static::class` inside `declaration()` is still the model's class.
- **`?? []` / `?? parent::` without `?->`.** An absent entry is `null`, and `??` covers the property fetch, as `$Manifest->router->pattern ?? []` does in the provider. The constructor needs `?->`, because it calls a method.
- **`abstract`**, as `Model` is. `DeclaredModel` itself has no entry, and Laravel's grandchild merge calls `DeclaredModel::resolveObserveAttributes()`, which gets `null` and adds nothing.
- **`Eloquent` alias.** The DataModel is `ZeroToProd\LaravelDeclaration\Model`, named after the Laravel class it declares, as `Request`, `Route`, `Router` and `View` are. `DeclaredRequest` aliases `ValidationFactory` the same way.
- **`isIgnoringTouch($class)`** stays untyped with `@param string|null`, as the parent declares it. A native `?string` would narrow the parent's parameter, and PHP rejects that.

### 2.6 Notes / non-goals

- **Relationships (gated, as roadmap Phase 4 is).** A relation is a method in the class. `resolveRelationUsing()` is the only way to register one without a method. It takes a Closure, and Laravel's docs say it is "not typically recommended for normal application development" (eloquent-relationships.md, "Dynamic Relationships"). A declared relation would have to name itself to avoid `{closure}` (§1.4.7), which is the package inventing arguments. It would also need a chain syntax (`->withPivot()->withTimestamps()`, `->latestOfMany()`, `->withDefault()`), the DSL that roadmap Phase 4 gates. The method also gives phpstan the relation's generic type (`BelongsTo<Airline, $this>`).
- **Local scopes, accessors, mutators, `booted()` closures, `serializeDate()`**: methods (`#[Scope]`, `Attribute`), so they stay PHP. `appends` and `with` name them.
- **Traits** (`SoftDeletes`, `HasUuids`, `HasUlids`, `HasFactory`, `Prunable`, `MassPrunable`, `BroadcastsEvents`): `use` them in the class. Their initializers run after the declared properties, so they compose (§1.1.1).
- **`CREATED_AT` / `UPDATED_AT` / `DELETED_AT`**: PHP constants. Declare them in the class.
- **`#[CollectedBy]` and `#[UseEloquentBuilder]` stay on the class.** They set the types `Flight::all()` and `Flight::query()` return. phpstan types both resolvers generic over `static` (`class-string<Collection<array-key, static>>`, `Builder<static>`), which a YAML string cannot carry. So a custom builder method declared in YAML would be an undefined method to static analysis. The static `$collectionClass` / `$builder` properties are shared by every model (§1.2).
- **Global Eloquent configuration is not a model's body.** `Model::shouldBeStrict()`, `preventLazyLoading()`, `preventSilentlyDiscardingAttributes()`, `preventAccessingMissingAttributes()`, `automaticallyEagerLoadRelationships()`, `unguard()` and `Relation::enforceMorphMap()` are static and change every model. A later `eloquent:` block keyed by those method names would mirror `router:`.
- **`observe` covers the event registrars.** `Flight::created('App\Listeners\X')` has the same effect as an observer's `created()` method, so it is not a second key. `dispatchesEvents` covers event classes.
- **Policies, factories, resources** (`#[UsePolicy]` / `Gate::policy()`, `#[UseFactory]`, `#[UseResource]`) are the Gate, factory and resource APIs, not `Model`.
- **Only `DeclaredModel` subclasses.** A vendor model (`extends Model`) cannot read an entry, so declaring one would be dead configuration: nothing at runtime rejects it (commit `78e8637`). Keep `Vendor\Model::observe(...)` in a provider.
- **Declare a model in one place.** YAML and class attributes compose by Laravel's precedence (§1.4.4), except that any `#[Table]` discards the declared `table`. A property the class body also declares is overwritten by the manifest, because the manifest assigns it in the constructor.
- **Subclasses need their own entry** (§1.4.6). Use a YAML anchor (`- &flight {class: ..., table: ...}` / `- {<<: *flight, class: App\Models\Charter}`). Don't repeat `observe` in the child: it already inherits the parent's observers, and a repeated observer registers its listeners twice.
- **Static analysis reads the class, not the manifest.** phpstan and Larastan read PHP, so keep `@property` tags on the class, as the fixture does.
- **Not a validation layer.** Values pass through as YAML decoded them, and every failure is Laravel's own, at first use (§1.4.2). Through the schema, `laravel-declaration:validate` catches an unknown key, a missing `class`, a leading `\`, a scalar where a list is required, a non-string cast and `perPage: 0`. Nothing at runtime checks that `class` extends `DeclaredModel` (commit `78e8637`); an entry naming a plain `Model` subclass is ignored — dead configuration the schema cannot see.
- **Caching.** Nothing here is cached. The entry is read on each construction, so no `config:cache` or `route:cache` is needed after editing it. A long-lived worker keeps its booted observers and scopes until it restarts, as it does for attributes.

---

## 3. Implementation plan

1. **`src/Model.php`** (new): one `const` + one nullable property per property key, in §2.4 order, then the three method keys. A missing `class` throws `PropertyRequiredException: Property `$class` is required.` at hydration.

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

2. **`src/DeclaredModel.php`** (new): the class in §2.5.

3. **`src/Manifest.php`**: add the `$models` property of §2.5 directly after `$requests`. `Collection` and `Describe` are already imported.

4. **`manifest.schema.json`**: add `models` to the root `properties` after `"requests"`:

   ```json
   "models": {
     "description": "Eloquent models. Each entry is the class body of a DeclaredModel subclass, found by static::class.",
     "type": "array",
     "items": { "$ref": "#/definitions/model" }
   }
   ```

   and these entries to `definitions` after `"request"`:

   ```json
   "columns": {
     "type": "array",
     "items": { "type": "string" }
   },
   "model": {
     "description": "Every key except the reserved `class` is an Illuminate\\Database\\Eloquent\\Model property, assigned before Laravel initializes the model as if written in the class body, or a Model method. An unknown key fails this schema (authoring-time guard) and is ignored at runtime.",
     "type": "object",
     "additionalProperties": false,
     "required": ["class"],
     "properties": {
       "class": {
         "description": "Reserved: the DeclaredModel subclass, spelled as static::class spells it (no leading `\\`).",
         "type": "string",
         "pattern": "^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$"
       },
       "connection": { "description": "-> $connection (#[Connection]): a database.connections name. Absent: the default connection.", "type": "string" },
       "table": { "description": "-> $table (#[Table(name)]). Absent: the snake-case plural of the class basename.", "type": "string" },
       "primaryKey": { "description": "-> $primaryKey (#[Table(key)]). Absent: id.", "type": "string" },
       "keyType": { "description": "-> $keyType (#[Table(keyType)]): int or string. Absent: int.", "type": "string" },
       "incrementing": { "description": "-> $incrementing (#[WithoutIncrementing]). Absent: true.", "type": "boolean" },
       "timestamps": { "description": "-> $timestamps (#[WithoutTimestamps]). Absent: true. false also makes other models skip touching this one.", "type": "boolean" },
       "dateFormat": { "description": "-> $dateFormat (#[DateFormat]): how date columns are stored. Absent: the connection grammar's format.", "type": "string" },
       "attributes": { "description": "-> $attributes: raw, storable default values of a new model.", "type": "object" },
       "casts": {
         "description": "-> $casts: attribute => cast string (boolean, array, datetime:Y-m-d, encrypted:array, an enum, a cast class, Class:arg). A casts() method in the class is merged over it.",
         "type": "object",
         "additionalProperties": { "type": "string" }
       },
       "fillable": { "description": "-> $fillable (#[Fillable]): mass-assignable attributes.", "$ref": "#/definitions/columns" },
       "guarded": { "description": "-> $guarded (#[Guarded]). Absent: ['*']. [] is #[Unguarded].", "$ref": "#/definitions/columns" },
       "hidden": { "description": "-> $hidden (#[Hidden]): left out of toArray() and toJson().", "$ref": "#/definitions/columns" },
       "visible": { "description": "-> $visible (#[Visible]): when non-empty, the only attributes in toArray() and toJson().", "$ref": "#/definitions/columns" },
       "appends": { "description": "-> $appends (#[Appends]): accessors added to toArray() and toJson(). The accessor is a method in the class.", "$ref": "#/definitions/columns" },
       "with": { "description": "-> $with: relations eager loaded by every query. The relation is a method in the class.", "$ref": "#/definitions/columns" },
       "withCount": { "description": "-> $withCount: relation counts ({relation}_count) loaded by every query.", "$ref": "#/definitions/columns" },
       "touches": { "description": "-> $touches (#[Touches]): relations whose updated_at is touched when this model saves.", "$ref": "#/definitions/columns" },
       "refreshes": { "description": "-> $refreshes (#[Refreshes]): columns re-read from the database after each save.", "$ref": "#/definitions/columns" },
       "perPage": { "description": "-> $perPage: the paginate() page size. Absent: 15.", "type": "integer", "minimum": 1 },
       "dispatchesEvents": {
         "description": "-> $dispatchesEvents: model event => event class, dispatched as new $class($model).",
         "type": "object",
         "additionalProperties": { "$ref": "#/definitions/classString" }
       },
       "observables": { "description": "-> $observables: custom event names an observer method may handle.", "$ref": "#/definitions/columns" },
       "observe": {
         "description": "-> observe($classes) when the model boots, where Laravel applies #[ObservedBy]: one listener per observer method named after an observable event.",
         "type": "array",
         "items": { "$ref": "#/definitions/classString" }
       },
       "addGlobalScope": {
         "description": "-> addGlobalScope($scope), one call per item when the model boots, where Laravel applies #[ScopedBy]: an Illuminate\\Database\\Eloquent\\Scope class.",
         "type": "array",
         "items": { "$ref": "#/definitions/classString" }
       },
       "getRouteKeyName": { "description": "-> getRouteKeyName() (#[RouteKey]): the column router.model and route() use. Absent: the primary key.", "type": "string" }
     }
   }
   ```

   The `class` pattern is `classString`'s without the optional leading `\\`. Checked with `laravel-declaration:validate`, the schema accepts `tests/Fixtures/manifest/models.yml` and rejects these:
   - `class: '\App\Models\Flight'`: `Does not match the regex pattern`,
   - `observe: App\Observers\X`: `String value found, but an array is required.`,
   - `perPage: 0`: `Must have a minimum value greater than or equal to 1.`,
   - `fillabel: [a]`: `The property fillabel is not defined`,
   - an entry without `class`: `The property class is required.`,
   - `casts: {a: [array]}` and `getRouteKeyName: [slug]`: `Array value found, but a string is required.`

5. **`composer-require-checker.json`**: add `"Illuminate\\Database\\Eloquent\\Model"` to `symbol-whitelist`, beside `Illuminate\\Foundation\\Http\\FormRequest`. `DeclaredModel` extends it, and `illuminate/database` is not in `require`: the package relies on the host's `laravel/framework`, as it does for `FormRequest` and `Router`. `app` is already whitelisted.

6. **`README.md`**: insert this section between `## Requests` and `## License`:

   ````markdown
   ## Models

   Define your application's Eloquent models in the `models` list. Each class
   extends `ZeroToProd\LaravelDeclaration\DeclaredModel`, and its entry is the
   class body: every key is a `Model` property (or `observe`, `addGlobalScope`,
   `getRouteKeyName`). Relations, accessors and local scopes stay methods on the
   class.

   Complete structure:

   ```yaml
   models:
     - class: App\Models\Flight                 # reserved: final class Flight extends DeclaredModel
       connection: mysql                        # -> $connection ≙ #[Connection]
       table: my_flights                        # -> $table ≙ #[Table(name)]
       primaryKey: flight_id                    # -> $primaryKey ≙ #[Table(key)]
       keyType: string                          # -> $keyType ≙ #[Table(keyType)]
       incrementing: false                      # -> $incrementing ≙ #[WithoutIncrementing]
       timestamps: true                         # -> $timestamps ≙ #[WithoutTimestamps] when false
       dateFormat: U                            # -> $dateFormat ≙ #[DateFormat]
       attributes: {delayed: false, options: '[]'}   # -> $attributes: raw, storable defaults
       casts: {delayed: boolean, options: array}     # -> $casts; casts() in the class is merged over it
       fillable: [name, code]                   # -> $fillable ≙ #[Fillable]
       guarded: ['*']                           # -> $guarded ≙ #[Guarded]; [] ≙ #[Unguarded]
       hidden: [secret]                         # -> $hidden ≙ #[Hidden]
       visible: []                              # -> $visible ≙ #[Visible]
       appends: [label]                         # -> $appends ≙ #[Appends]
       with: [airline]                          # -> $with: eager loaded by every query
       withCount: [passengers]                  # -> $withCount
       touches: [airline]                       # -> $touches ≙ #[Touches]
       refreshes: [status]                      # -> $refreshes ≙ #[Refreshes]
       perPage: 25                              # -> $perPage
       dispatchesEvents: {created: App\Events\FlightCreated}   # -> $dispatchesEvents
       observables: [boarding]                  # -> $observables
       observe: [App\Observers\FlightObserver]  # -> observe() at boot ≙ #[ObservedBy]
       addGlobalScope: [App\Models\Scopes\NotCancelled]   # -> addGlobalScope() at boot ≙ #[ScopedBy]
       getRouteKeyName: code                    # -> getRouteKeyName() ≙ #[RouteKey]; router.model binds by it
   ```

   The class:

   ```php
   use ZeroToProd\LaravelDeclaration\DeclaredModel;

   final class Flight extends DeclaredModel
   {
       public function airline(): BelongsTo { return $this->belongsTo(Airline::class); }
   }
   ```
   ````

7. **Fixtures.** PHP classes in `tests/Fixtures/App/Models/`, namespace `ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models`. Each file starts with `<?php`, `declare(strict_types=1);` and the namespace, as the other fixtures do. They pass phpstan level 9 with the repository's `phpstan.neon`, which analyses `tests/` except `tests/Feature`.

   ```php
   // Flight.php
   use Illuminate\Database\Eloquent\Casts\Attribute;
   use Illuminate\Database\Eloquent\Relations\BelongsTo;
   use ZeroToProd\LaravelDeclaration\DeclaredModel;

   /**
    * Declared in `models.yml`. The class body holds only PHP: a relation, an accessor and `casts()`.
    *
    * @property string $name
    * @property string $code
    * @property string $status
    * @property string|null $secret
    */
   final class Flight extends DeclaredModel
   {
       /** @return BelongsTo<Airline, $this> */
       public function airline(): BelongsTo
       {
           return $this->belongsTo(Airline::class);
       }

       protected function label(): Attribute
       {
           return Attribute::get(fn (): string => $this->code.' '.$this->name);
       }

       /** Laravel's initializeHasAttributes() merges this over the declared `casts`. */
       protected function casts(): array
       {
           return ['departed_at' => 'datetime'];
       }
   }

   // Airline.php
   use Illuminate\Database\Eloquent\Relations\HasMany;
   use ZeroToProd\LaravelDeclaration\DeclaredModel;

   /** Declared in `models.yml` with `timestamps: false`. */
   final class Airline extends DeclaredModel
   {
       /** @return HasMany<Flight, $this> */
       public function flights(): HasMany
       {
           return $this->hasMany(Flight::class);
       }
   }

   // Undeclared.php
   use ZeroToProd\LaravelDeclaration\DeclaredModel;

   /** No `models` entry: every property keeps Laravel's default. */
   final class Undeclared extends DeclaredModel {}

   // FlightObserver.php
   /** `observe: [FlightObserver]`: one listener per method named after an observable event. */
   final class FlightObserver
   {
       public function creating(Flight $flight): void
       {
           $flight->secret = 'observed';
       }

       /** A custom event: registered only because `observables` declares `boarding`. */
       public function boarding(Flight $flight): void
       {
           $flight->status = 'boarding';
       }
   }

   // NotCancelled.php
   use Illuminate\Database\Eloquent\Builder;
   use Illuminate\Database\Eloquent\Model;
   use Illuminate\Database\Eloquent\Scope;

   /**
    * `addGlobalScope: [NotCancelled]`: every Flight query, route binding included.
    *
    * @implements Scope<Flight>
    */
   final class NotCancelled implements Scope
   {
       public function apply(Builder $builder, Model $model): void
       {
           $builder->where('status', '!=', 'cancelled');
       }
   }

   // FlightCreated.php
   /** `dispatchesEvents: {created: FlightCreated}`: Laravel dispatches `new FlightCreated($flight)`. */
   final readonly class FlightCreated
   {
       public function __construct(public Flight $flight) {}
   }
   ```

   `boarding()` has a body on purpose: rector's dead-code set deletes an empty method, and the observer then no longer listens to `boarding`. The route reuses the Phase 1 fixture `Routing\ParametersController`, which returns `$route->parameters()` (declarative-router-bindings.md §3.7).

   `tests/Fixtures/manifest/models.yml`. It is a separate file, so every other test's routes stay unchanged. The tables are created in the test on Testbench's default `testing` connection (sqlite, `:memory:`):

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

   routes:
     - path: "flights/{flight}"
       methods: GET
       action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\ParametersController
       middleware: [Illuminate\Routing\Middleware\SubstituteBindings]
   ```

8. **Tests**: `tests/Feature/DeclaredModelTest.php`. These exact tests and fixtures pass, reproduced in a scratch copy of this repository:

   ```php
   <?php

   declare(strict_types=1);

   use Illuminate\Database\Schema\Blueprint;
   use Illuminate\Support\Facades\Artisan;
   use Illuminate\Support\Facades\DB;
   use Illuminate\Support\Facades\Event;
   use Illuminate\Support\Facades\Schema;
   use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Airline;
   use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Flight;
   use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\FlightCreated;
   use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Undeclared;
   use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\User;

   beforeEach(function (): void {
       $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/models.yml']);

       Schema::create('airlines', function (Blueprint $table): void {
           $table->id();
           $table->string('name');
       });

       Schema::create('my_flights', function (Blueprint $table): void {
           $table->string('flight_id')->primary();
           $table->foreignId('airline_id');
           $table->string('name');
           $table->string('code');
           $table->string('status')->default('scheduled');
           $table->text('options');
           $table->boolean('delayed');
           $table->string('secret')->nullable();
           $table->integer('departed_at')->nullable();
           $table->integer('created_at')->nullable();
           $table->integer('updated_at')->nullable();
       });
   });

   it('assigns every declared property before Laravel initializes the model', function (): void {
       $Flight = new Flight;

       expect($Flight->getTable())->toBe('my_flights')
           ->and($Flight->getKeyName())->toBe('flight_id')
           ->and($Flight->getKeyType())->toBe('string')
           ->and($Flight->getIncrementing())->toBeFalse()
           ->and($Flight->getDateFormat())->toBe('U')
           ->and($Flight->getAttributes())->toBe(['delayed' => false, 'options' => '[]'])
           ->and($Flight->getCasts())->toBe(['delayed' => 'boolean', 'options' => 'array', 'departed_at' => 'datetime'])
           ->and($Flight->getFillable())->toBe(['flight_id', 'airline_id', 'name', 'code', 'status'])
           ->and($Flight->getGuarded())->toBe(['*'])
           ->and($Flight->getHidden())->toBe(['secret'])
           ->and($Flight->getAppends())->toBe(['label'])
           ->and($Flight->getTouchedRelations())->toBe(['airline'])
           ->and($Flight->getPerPage())->toBe(5)
           ->and($Flight->dispatchesEvents())->toBe(['created' => FlightCreated::class])
           ->and($Flight->getObservableEvents())->toContain('boarding')
           ->and(array_keys($Flight->newQuery()->getEagerLoads()))->toBe(['airline'])
           ->and($Flight->getRouteKeyName())->toBe('code');

       $Airline = new Airline;

       expect($Airline->getConnectionName())->toBe('testing')
           ->and($Airline->usesTimestamps())->toBeFalse()
           ->and($Airline->getGuarded())->toBeEmpty()
           ->and($Airline->getVisible())->toBe(['id', 'name', 'flights_count']);
   });

   it('keeps Laravel defaults for a class without an entry', function (): void {
       $Undeclared = new Undeclared;

       expect($Undeclared->getTable())->toBe('undeclareds')
           ->and($Undeclared->getKeyName())->toBe('id')
           ->and($Undeclared->getGuarded())->toBe(['*'])
           ->and($Undeclared->usesTimestamps())->toBeTrue()
           ->and($Undeclared->getPerPage())->toBe(15)
           ->and($Undeclared->getRouteKeyName())->toBe('id');
   });

   it('persists through the declared table, casts, observer, event and refreshed columns', function (): void {
       $created = null;
       Event::listen(FlightCreated::class, function (FlightCreated $FlightCreated) use (&$created): void {
           $created = $FlightCreated->flight;
       });

       $Airline = Airline::create(['name' => 'Acme Air']);
       $Flight = Flight::create(['flight_id' => 'f-1', 'airline_id' => $Airline->id, 'name' => 'Boston', 'code' => 'AA100']);

       expect($Flight->status)->toBe('scheduled')
           ->and($Flight->secret)->toBe('observed')
           ->and($created)->toBe($Flight)
           ->and(DB::table('my_flights')->value('created_at'))->toBeNumeric()
           ->and(Flight::query()->sole()->options)->toBe([]);
   });

   it('registers declared observers and global scopes when the model boots', function (): void {
       $Airline = Airline::create(['name' => 'Acme Air']);
       Flight::create(['flight_id' => 'f-1', 'airline_id' => $Airline->id, 'name' => 'Boston', 'code' => 'AA100']);
       Flight::create(['flight_id' => 'f-2', 'airline_id' => $Airline->id, 'name' => 'Denver', 'code' => 'AA200', 'status' => 'cancelled']);

       expect(Flight::all())->toHaveCount(1)
           ->and(Flight::withoutGlobalScopes()->count())->toBe(2)
           ->and(Flight::query()->sole()->relationLoaded('airline'))->toBeTrue()
           ->and(Airline::query()->sole()->flights_count)->toBe(1)
           ->and(Event::hasListeners('eloquent.boarding: '.Flight::class))->toBeTrue();
   });

   it('binds a router.model parameter by the declared route key, through the declared scope', function (): void {
       $Airline = Airline::create(['name' => 'Acme Air']);
       Flight::create(['flight_id' => 'f-1', 'airline_id' => $Airline->id, 'name' => 'Boston', 'code' => 'AA100']);
       Flight::create(['flight_id' => 'f-2', 'airline_id' => $Airline->id, 'name' => 'Denver', 'code' => 'AA200', 'status' => 'cancelled']);

       $this->get('/flights/AA100')
           ->assertOk()
           ->assertJsonPath('flight.flight_id', 'f-1')
           ->assertJsonPath('flight.label', 'AA100 Boston')
           ->assertJsonPath('flight.options', [])
           ->assertJsonPath('flight.delayed', false)
           ->assertJsonMissingPath('flight.secret')
           ->assertJsonPath('flight.airline', ['id' => 1, 'name' => 'Acme Air', 'flights_count' => 1]);

       $this->get('/flights/AA200')->assertNotFound();
   });

   it('does not touch a parent whose timestamps are declared false', function (): void {
       $Airline = Airline::create(['name' => 'Acme Air']);
       $Flight = Flight::create(['flight_id' => 'f-1', 'airline_id' => $Airline->id, 'name' => 'Boston', 'code' => 'AA100']);

       expect(Airline::isIgnoringTouch())->toBeTrue()
           ->and(Flight::isIgnoringTouch())->toBeFalse()
           ->and($Flight->update(['name' => 'Chicago']))->toBeTrue();
   });

   it('declares nothing without a models block', function (): void {
       $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/requests.yml']);

       $Flight = new Flight;

       expect($Flight->getTable())->toBe('flights')
           ->and($Flight->getGuarded())->toBe(['*'])
           ->and($Flight->getCasts())->toBe(['id' => 'int', 'departed_at' => 'datetime']);
   });

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

   it('reports the declaration through model:show', function (): void {
       Artisan::call('model:show', ['model' => Flight::class, '--json' => true]);

       /** @var array<string, mixed> $info */
       $info = json_decode(Artisan::output(), true);

       expect($info['table'])->toBe('my_flights')
           ->and($info['events'])->toBe([['event' => 'created', 'class' => FlightCreated::class]])
           ->and(array_column($info['observers'], 'event'))->toBe(['creating', 'boarding']);
   });
   ```

   What each assertion pins:
   - `getCasts()` holds the declared casts **and** `casts()`'s `departed_at`. So the properties are assigned before `initializeHasAttributes()` (§1.1.1).
   - `status` is `scheduled` only because `refreshes` re-read the column's database default. `secret` is `observed` only because the `observe` observer ran.
   - `created_at` is numeric because of `dateFormat: U`. `options` comes back as `[]` because of `casts`.
   - `Flight::all()` has one row because `NotCancelled` ran. `Flight::withoutGlobalScopes()` counts both.
   - `/flights/AA100` binds through `getRouteKeyName` and serializes with `hidden`, `appends`, `casts` and `with`, plus `Airline`'s `visible` and `withCount`. `/flights/AA200` is a 404 because the scope applies to route binding (§1.4.3).
   - `does not touch` passes only with the `isIgnoringTouch()` override (§1.4.5).
   - The last dataset covers each spelling that is ignored at runtime (commit `78e8637`).

   Two mutations were checked, and each fails the tests:
   - Assigning after `parent::__construct()` fails the first test: `departed_at` is lost.
   - Removing `isIgnoringTouch()` fails four tests with `QueryException: ... no such column: updated_at`.

   Also add to `tests/Feature/ValidateCommandTest.php`, before the "reports each schema violation" test:

   ```php
   test('laravel-declaration:validate accepts the models block', function (): void {
       $this->artisan('laravel-declaration:validate', ['--manifest' => __DIR__.'/../Fixtures/manifest/models.yml'])
           ->expectsOutputToContain('is valid')
           ->assertSuccessful();
   });
   ```

9. **`composer check`**. In the scratch copy, with steps 1–4, 7 and 8 applied:
   - `pint --test`, `rector process --dry-run` and `phpstan analyse` (level 9) pass.
   - `pest --coverage --min=100` passes 135 tests at 100.0%, with `DeclaredModel` and `Model` at 100.0%.
   - `bc-check` skips: `bin/bc-check.sh` exits early when no SemVer tag exists, and `git tag` lists none in this repository.

   `require-check` was not run: it downloads a phar from GitHub. The new public API is `DeclaredModel` (abstract: `__construct()`, `resolveObserveAttributes()`, `resolveGlobalScopeAttributes()`, `getRouteKeyName()`, `isIgnoringTouch()`), `Model` (its properties and `properties()`) and `Manifest::$models`. All of it is additive. The MCP `api` tool reflects `src/`, so it lists `DeclaredModel` and `Model` with no change (`PublicApiToolTest` passes), and the `readme` tool serves the updated README.

10. **Other docs**. Make three edits:
    - In `docs/declarative-request-to-view-roadmap.md` §1, add this sentence below the pipeline table: "Stage 6 resolves Eloquent models. A model's table, key, route key and global scopes are the `models` block ([declarative-model.md](declarative-model.md))."
    - In `docs/declarative-request-to-view-roadmap.md` Phase 4, append to "Open question that gates it": "Its root is a model, declared by [declarative-model.md](declarative-model.md). Relations stay methods there (§2.6), so `from: user.posts` calls a PHP method."
    - In `docs/declarative-router-bindings.md` §1.4.2, append: "To bind by another column, declare `getRouteKeyName` on the model's `models` entry ([declarative-model.md](declarative-model.md) §1.4.3)."

---

### Sources

1. Constructor, `bootIfNotBooted()`, `bootTraits()`, `initializeTraits()`, `initializeModelAttributes()`, `isIgnoringTouch()`, `newInstance()`, `newFromBuilder()`, `newQueryWithoutScopes()` (`$with`, `$withCount`), `refreshSavedAttributes()`, `getPerPage()`, `getRouteKeyName()`, `resolveRouteBinding()`, `resolveRouteBindingQuery()`, `resolveClassAttribute()`, `clearBootedModels()`, strictness statics — [Eloquent/Model.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Model.php)
2. `$attributes`, `$casts`, `$dateFormat`, `$appends`, `initializeHasAttributes()`, `casts()`, `getCasts()`, `mergeCasts()` — [Concerns/HasAttributes.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php); `$fillable`, `$guarded`, `initializeGuardsAttributes()` — [Concerns/GuardsAttributes.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php); `$hidden`, `$visible` — [Concerns/HidesAttributes.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Concerns/HidesAttributes.php); `$timestamps`, `usesTimestamps()` — [Concerns/HasTimestamps.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Concerns/HasTimestamps.php)
3. `$dispatchesEvents`, `$observables`, `bootHasEvents()`, `resolveObserveAttributes()` (grandchild merge), `observe()`, `registerObserver()`, `registerModelEvent()` — [Concerns/HasEvents.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Concerns/HasEvents.php)
4. `bootHasGlobalScopes()`, `resolveGlobalScopeAttributes()`, `addGlobalScope()`, `addGlobalScopes()` — [Concerns/HasGlobalScopes.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Concerns/HasGlobalScopes.php)
5. `$touches`, `touchOwners()`, `getTouchedRelations()`, `resolveRelationUsing()`, `belongsTo()`, `morphTo()`, `guessBelongsToRelation()` — [Concerns/HasRelationships.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Concerns/HasRelationships.php); `touch()` calls `$model::isIgnoringTouch()` — [Relations/Relation.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Relations/Relation.php), [Relations/BelongsToMany.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Relations/BelongsToMany.php)
6. `newCollection()`, `resolveCollectionFromAttribute()` — [HasCollection.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/HasCollection.php); `initializeSoftDeletes()` — [SoftDeletes.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/SoftDeletes.php); `$preventsLazyLoading` set per hydrated model — [Builder.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Builder.php)
7. Class attributes and their constructors — [Eloquent/Attributes](https://github.com/laravel/framework/tree/v13.33.0/src/Illuminate/Database/Eloquent/Attributes); `Scope<TModel>` — [Eloquent/Scope.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Scope.php)
8. `clearBootedModels()` in `register()`, `setEventDispatcher()` in `boot()` — [Database/DatabaseServiceProvider.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/DatabaseServiceProvider.php); `model:show` constructs the model — [Eloquent/ModelInspector.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/ModelInspector.php)
9. `forModel()` → `resolveRouteBinding($value)` — [Routing/RouteBinding.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Routing/RouteBinding.php)
10. Conventions, attributes, default attribute values, strictness in `AppServiceProvider::boot()`, observers, global scopes — [docs/repos/laravel/docs/eloquent.md](repos/laravel/docs/eloquent.md); casts — [eloquent-mutators.md](repos/laravel/docs/eloquent-mutators.md); `#[Hidden]`, `#[Visible]`, `#[Appends]` — [eloquent-serialization.md](repos/laravel/docs/eloquent-serialization.md); dynamic relationships, `#[Touches]` — [eloquent-relationships.md](repos/laravel/docs/eloquent-relationships.md#dynamic-relationships); `#[CollectedBy]` — [eloquent-collections.md](repos/laravel/docs/eloquent-collections.md); `#[RouteKey]` — [routing.md](repos/laravel/docs/routing.md); [laravel.com/docs/eloquent](https://laravel.com/docs/eloquent)
11. `Describe::required` throws `PropertyRequiredException` — `zero-to-prod/data-model` `src/DataModel.php` in this repository; `key_by` — `zero-to-prod/data-model-helper` `src/DataModelHelper.php`
12. YAML decoding of `"App\Observers\X"`, `datetime:Y-m-d`, `App\Casts\Hash:sha256,1`, `'[]'`, `[]` and a bare `*` — `symfony/yaml` v8.1.6, verified with `Yaml::parse()` in this repository
13. Every consequence in §1.1 and §1.4, the §2.5 class, the §3.4 schema checks, the §3.7–§3.8 fixtures and tests, the mutations and the §3.9 `composer check` results — reproduced with Testbench in a scratch copy of this repository (scratch files, not committed)
