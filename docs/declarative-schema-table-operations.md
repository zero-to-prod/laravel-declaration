# Declarative Schema Table Operations — `Schema::table()` / `rename()` / `drop()` / `dropIfExists()`, Alter Blueprints & the Foreign-Key Modifier Defect

Source of truth: `vendor/laravel/framework/src/Illuminate/Database/Schema/Builder.php` (`laravel/framework` v13.33.0), with `Blueprint.php`, `ColumnDefinition.php`, `ForeignIdColumnDefinition.php`, `ForeignKeyDefinition.php`, `IndexDefinition.php`, `BlueprintState.php`, `Illuminate/Support/Fluent.php`, and Laravel documentation: `docs/repos/laravel/docs/migrations.md`.

Goal: resolve Tier 1 gap inventory [declarative-tier1-gap-inventory.md](declarative-tier1-gap-inventory.md) §2.5 — "Database Schema & Blueprint `schema:` `[/] (narrower)`" — remediation ordering items **1** (the `ForeignKeyDefinition` modifier-discard defect + flat-form index list) and **2** (table operations `alter`, `rename`, `drop`, `dropIfExists`). Column types/modifiers are already fully dynamic; what remains is the **lifecycle around the blueprint**.

**Implementation requirement: dynamic dispatch with no new per-method code.** The inventory's missing lists (Builder operations, Blueprint alter verbs, `vectorIndex`/`rawIndex`, `addColumn`/`rawColumn`) are covered by three generalized mechanisms instead of per-method branches:

1. **One blueprint body, two Builder verbs.** `Builder::create($table, $callback)` and `Builder::table($table, $callback)` differ only in the single `$blueprint->create()` command (`Builder.php:520,508`). Every `Blueprint` method is valid in both modes — the grammar chooses `compileCreate` vs `compileChange`/alter commands from the blueprint's own `creating()` state (`Blueprint.php:325`, `Blueprint::addColumnDefinition` pushes columns into `$commands` when not creating). Therefore the existing `TableDefinition::apply(Blueprint)` serves `create` and `alter` **unchanged**.
2. **Native parameter names as manifest keys.** A map definition's keys that match `ReflectionParameter` names of the invoked `Blueprint` method are spread as **PHP named arguments** (`$blueprint->{$method}(...$map)`). This kills the positional `args`/`column` guessing, makes multi-argument factories (`foreign($columns, $name)`, `vectorIndex($column, $name)`, `rawIndex($expression, $name)`, `renameColumn($from, $to)`, `rawColumn($column, $definition)`, `addColumn($type, $name, $parameters)`) expressible natively, and fixes the mis-wired `vectorIndex`/`rawIndex` flat form without a whitelist.
3. **Sequential modifier dispatch by returned type.** Modifiers are dispatched in declaration order onto the *current* target; `constrained()` transitions the target from `ForeignIdColumnDefinition` to `ForeignKeyDefinition` exactly as Laravel's fluent chain does, so FK modifiers (`cascadeOnDelete`, `on`, `references`, `deferrable`) land on the `ForeignKeyDefinition` — including for standalone `Blueprint::foreign()` (the Rule 7 defect).

Design rules: [declarative-request-to-view-roadmap.md](declarative-request-to-view-roadmap.md) §2 (Rule 1: key = method name; Rule 2: one call per entry; Rule 7: fail where Laravel fails). Grounding documentation: [declarative-schema.md](declarative-schema.md) (the shipped `schema:` creation surface).

---

## 1. Native API (v13.33.0)

### 1.1 Lifecycle position (when the declaration runs)

```
php artisan declaration:migrate
  MigrateCommand::handle(Manifest)                       // src/Internal/Commands/MigrateCommand.php
    Schema::connection($manifest->schema->connection)    // one Builder for all operations
    Builder::dropIfExists($table)                        // Builder.php:548 — build(tap(createBlueprint(), fn ($bp) => $bp->dropIfExists()))
    Builder::drop($table)                               // Builder.php:535 — build(... $bp->drop())
    Builder::rename($from, $to)                         // Builder.php:612 — build(... $bp->rename($to))
    Builder::create($table, $callback)                  // Builder.php:520 — guarded by hasTable() (shipped)
      Blueprint::__construct($connection, $table, $callback)  // callback runs inside the constructor (Blueprint.php:104)
        $blueprint->create()                            // the ONLY difference vs table(): creates the 'create' command
        TableDefinition::apply($blueprint)              // dynamic dispatch — shared with alter
    Builder::table($table, $callback)                   // Builder.php:508 — NO hasTable guard of its own; guarded per action here
      Blueprint::__construct($connection, $table, $callback)
        creating() === false                            // Blueprint.php:325 — no 'create' command queued
        TableDefinition::apply($blueprint)              // same dispatch; addColumnDefinition() pushes to $commands (Blueprint.php:1859)
        $column->change()                               // Fluent::__call sets attributes['change'] (Fluent.php:299)
      BlueprintState::update($command)                  // SQLite alter/change emulation (Blueprint.php:150-151, :316)
```

Consequences, each verified against v13.33.0:

1. **`create` and `table` share one blueprint body.** `Builder::table()` passes the callback straight into `createBlueprint($table, $callback)` → `Blueprint::__construct(..., $callback)` runs it during construction; `Builder::create()` wraps it in `tap(...)` after queuing `$blueprint->create()`. Every column factory, modifier, index, and dropper behaves identically; the grammar compiles per-connection (`compileCreate` vs `compileChange`, `compileAdd`, `compileDropColumn`, …).
2. **SQLite (the test driver) supports the full alter surface.** When the connection is SQLite, the blueprint attaches a `BlueprintState` (`Blueprint.php:316`) and `toSql()` routes every command through `$this->state->update($command)` (`Blueprint.php:150`) — column changes and drops are emulated as table rebuilds. No driver special-casing is needed in the package.
3. **The four new Builder operations are one-liners natively.** `drop()`/`dropIfExists()`/`rename()` build a blueprint with a single verb command and `build()` it — they take no callback. `table()` takes the same callback shape as `create()`.
4. **`hasTable()`/`hasColumn()`/`hasIndex()`/`hasForeignKey()` query the live catalog** (`Builder.php:169,270,444,471`) — these are the idempotency primitives for alter operations (§3.6).

### 1.2 The four `Builder` operations (verified signatures)

| Method | Signature | Line | Blueprint verb queued |
|---|---|---|---|
| `Builder::table()` | `table(string $table, Closure $callback): void` | `Builder.php:508` | none (alter mode) |
| `Builder::create()` | `create(string $table, Closure $callback): void` | `Builder.php:520` | `$blueprint->create()` (`Blueprint.php:336`) |
| `Builder::drop()` | `drop(string $table): void` | `Builder.php:535` | `$blueprint->drop()` (`Blueprint.php:401`) |
| `Builder::dropIfExists()` | `dropIfExists(string $table): void` | `Builder.php:548` | `$blueprint->dropIfExists()` (`Blueprint.php:411`) |
| `Builder::rename()` | `rename(string $from, string $to): void` | `Builder.php:612` | `$blueprint->rename($to)` (`Blueprint.php:647`) |

Idempotency predicates on the same Builder (used by the executor, not re-implemented):

| Method | Signature | Line | Matches |
|---|---|---|---|
| `hasTable()` | `hasTable(string $table): bool` | `Builder.php:169` | catalog lookup |
| `hasColumn()` | `hasColumn(string $table, string $column): bool` | `Builder.php:270` | case-insensitive column name |
| `hasColumns()` | `hasColumns(string $table, array $columns): bool` | `Builder.php:284` | all columns present |
| `hasIndex()` | `hasIndex(string $table, string\|array $index, string\|null $type = null): bool` | `Builder.php:444` | index **name or column list** |
| `hasForeignKey()` | `hasForeignKey(string $table, string\|array $foreignKey): bool` | `Builder.php:471` | constraint **name or column list** |
| `whenTableHasColumn()` | `whenTableHasColumn(string $table, string $column, Closure $callback): void` | `Builder.php:299` | `hasColumn() ? table() : null` |
| `whenTableDoesntHaveColumn()` | `whenTableDoesntHaveColumn(string $table, string $column, Closure $callback): void` | `Builder.php:314` | `! hasColumn() ? table() : null` |
| `whenTableHasIndex()` / `whenTableDoesntHaveIndex()` | `(string $table, string\|array $index, Closure $callback, ?string $type = null): void` | `Builder.php:330,346` | `hasIndex() ? table() : null` |

### 1.3 The Blueprint alter-verb surface (verified signatures, all reachable through the existing `method_exists` dispatch)

| Method | Signature | Line | Docblock `@return` |
|---|---|---|---|
| `dropColumn()` | `dropColumn(string\|array $columns): Fluent` | `Blueprint.php:422` | `\Illuminate\Support\Fluent` |
| `renameColumn()` | `renameColumn(string $from, string $to): Fluent` | `Blueprint.php:436` | `Fluent` |
| `dropPrimary()` / `dropUnique()` / `dropIndex()` / `dropFullText()` / `dropSpatialIndex()` / `dropVectorIndex()` | `drop*(string\|array\|null $index): Fluent` | `Blueprint.php:447-502` | `Fluent` |
| `dropForeign()` | `dropForeign(string\|array $foreign): Fluent` | `Blueprint.php:513` | `Fluent` |
| `dropConstrainedForeignId()` | `dropConstrainedForeignId(string $column): void` | `Blueprint.php:524` | `void` |
| `dropForeignIdFor()` / `dropConstrainedForeignIdFor()` | `drop*ForeignIdFor(Model\|string $model, ?string $column = null)` — instantiates string models and calls `$model->getForeignKey()` | `Blueprint.php:538,554` | `void` |
| `renameIndex()` | `renameIndex(string $from, string $to): Fluent` | `Blueprint.php:570` | `Fluent` |
| `dropTimestamps()` / `dropTimestampsTz()` / `dropSoftDeletes()` / `dropSoftDeletesTz()` / `dropRememberToken()` / `dropMorphs()` | convenience droppers (`dropMorphs(string $name, ?string $indexName = null)`) | `Blueprint.php:580-634` | `void` |
| `removeColumn()` | `removeColumn(string $name): self` | `Blueprint.php:1901` | `$this` |
| `ColumnDefinition::change()` | *not a real method* — `Fluent::__call()` sets `attributes['change'] = true` (`Fluent.php:299`); consumed by `compileChange` and `BlueprintState` | `ColumnDefinition.php:11` (`@method $this change()`) | `$this` |
| `addColumn()` | `addColumn(string $type, string $name, array $parameters = []): ColumnDefinition` | `Blueprint.php:1849` | `ColumnDefinition` |
| `rawColumn()` | `rawColumn(string $column, string $definition): ColumnDefinition` — **two** arguments, not a single SQL string | `Blueprint.php:1757` | `ColumnDefinition` |
| `rawIndex()` | `rawIndex(string $expression, string $name): IndexDefinition` — wraps `new Expression($expression)` | `Blueprint.php:740` | `IndexDefinition` |
| `foreign()` | `foreign(string\|array $columns, ?string $name = null): ForeignKeyDefinition` | `Blueprint.php:752` | `ForeignKeyDefinition` |
| `vectorIndex()` | `vectorIndex(string $column, ?string $name = null): IndexDefinition` | `Blueprint.php:724` | `IndexDefinition` |

Modifier targets verified in full:

- **`ColumnDefinition`** (extends `Fluent`, `ColumnDefinition.php:17`): every modifier is either a real method (`nullable`, `default`, `unsigned`, `useCurrent`, `useCurrentOnUpdate`, …) or a `@method`-annotated Fluent verb — the docblock lists all of them including **`change()`** (`ColumnDefinition.php:11`).
- **`ForeignIdColumnDefinition`** (extends `ColumnDefinition`): real methods `constrained($table = null, $column = null, $indexName = null)` (`ForeignIdColumnDefinition.php:37`) and `references($column, $indexName = null)` (`:54`) — `references()` itself creates the FK via `$this->blueprint->foreign(...)` and returns the `ForeignKeyDefinition`, so the fluent target transitions even without `constrained()`.
- **`ForeignKeyDefinition`** (extends `Fluent`, `ForeignKeyDefinition.php:17`): real methods `cascadeOnUpdate/restrictOnUpdate/nullOnUpdate/noActionOnUpdate/cascadeOnDelete/restrictOnDelete/nullOnDelete/noActionOnDelete` plus `@method`-annotated `deferrable`, `initiallyImmediate`, `inplace`, `lock`, `on`, `onDelete`, `onUpdate`, `references`.
- **`IndexDefinition`** (extends `Fluent`): `@method`-annotated `algorithm`, `deferrable`, `initiallyImmediate`, `inplace`, `language`, `lock`, `nullsNotDistinct`, `online` — today the `indexes:` bag discards the returned `IndexDefinition`, so these are undeclarable; sequential dispatch makes them declarable for free.
- **Table options** `engine()`, `charset()`, `collation()`, `temporary()` are **property setters** on the blueprint (`Blueprint.php:347-395` — `$this->engine = $engine`), not Fluent commands; `comment()` queues a `tableComment` command (`Blueprint.php:1768`). Plain method dispatch covers all five; the `property_exists` branch in the shipped `TableDefinition::apply()` is unnecessary.

### 1.4 Return-type classification (the dynamic replacement for the two static key lists)

The v13.33.0 `Blueprint` methods declare **no native return types** (verified by reflection: `ReflectionMethod::getReturnType()` is empty for `string`, `index`, `foreign`, `morphs`, `dropColumn`, `timestamps`, `rawColumn`, `removeColumn`, `renameIndex`, `engine`, `temporary`, `comment`, `dropForeignIdFor`). The only machine-readable classification source is the **docblock `@return` tag**, which is complete and consistent across the DDL surface:

| `@return` tag | Classification | Methods (representative) |
|---|---|---|
| `\Illuminate\Database\Schema\ColumnDefinition` / `ForeignIdColumnDefinition` | **column factory** | `id`, `string`, `integer`, `foreignId`, `foreignIdFor`, `addColumn`, `rawColumn`, … |
| `\Illuminate\Database\Schema\IndexDefinition` | **index factory** | `primary`, `unique`, `index`, `fullText`, `spatialIndex`, `vectorIndex`, `rawIndex` (`Blueprint.php:658-738`) |
| `\Illuminate\Database\Schema\ForeignKeyDefinition` | **foreign-key factory** | `foreign` (`Blueprint.php:750`) |
| `\Illuminate\Support\Fluent` | **verb** | `dropColumn`, `dropUnique`, `dropIndex`, `dropForeign`, `renameColumn`, `renameIndex`, `comment` |
| `void` | **unit verb** | `morphs`, `temporary`, `engine`, `dropTimestamps`, `dropMorphs`, `dropConstrainedForeignId` |
| `\Illuminate\Support\Collection` | **multi-column factory** | `timestamps`, `nullableTimestampsTz`, `datetimes` |
| `$this` | **verb (stateful)** | `removeColumn` |
| anything else / none | **unknown → rejected at hydration** | `build` (`@return void` — denied), `toSql` (`@return array` → unknown) |

### 1.5 The defects being fixed (verified by code trace against `src/`)

1. **`ForeignKeyDefinition` modifier discard (Rule 7 violation).** `Blueprint::foreign()` returns `ForeignKeyDefinition`, which extends `Illuminate\Support\Fluent` — **not** `ColumnDefinition` (`ForeignKeyDefinition.php:17`). The shipped `ColumnDefinitionModel::apply()` dispatches FK modifiers only when `constrained !== null && $column instanceof ForeignIdColumnDefinition`, and remaining modifiers only when `$column instanceof ColumnDefinition`. A declaration like `foreign: {column: user_id, references: users, on: id, cascadeOnDelete: true}` therefore **silently discards every modifier**. Verified: no `ForeignKeyDefinition` branch exists in `src/ColumnDefinitionModel.php`.
2. **Flat-form index list omits `vectorIndex` and `rawIndex`.** The static list in `TableDefinition::from()` is `['primary', 'unique', 'index', 'fullText', 'spatialIndex']`; `vectorIndex` (`Blueprint.php:724`) and `rawIndex` (`Blueprint.php:740`) fall through to the column branch and are mis-handled as columns — their `IndexDefinition` results are not `ColumnDefinition`, and the `name`/`column` key collision overwrites `factoryArguments[0]` with the index name (e.g. `vectorIndex: {column: embedding, name: idx}` dispatches `vectorIndex('idx')`).
3. **Named-index map form mis-dispatch (new finding, beyond the inventory list).** In `TableDefinition::apply()`, the `indexes:` loop passes a map definition straight through: `$table->{$indexMethod}($definition)` — so `index: {columns: [user_id], name: my_idx}` calls `$table->index(['columns' => …, 'name' => …])`, treating the map itself as the `$columns` argument. The doc's §1.4 pseudocode (`isset($indexArgs['columns'])`) was never implemented.
4. **`Fluent::__call` swallows unknown modifier typos.** `string: {column: x, nulable: true}` sets a dead `nulable` attribute (`Fluent.php:299`) — silent no-op. Likewise `length: 255` on `string` works today only *accidentally* (the Fluent attribute named `length` overwrites the null factory argument). Named arguments make both correct-by-construction and unknown modifiers become loud failures.

---

## 2. Manifest schema (`schema:`)

### 2.1 Design rule — the block is a map of `Schema\Builder` method names

The shipped `schema:` block has a single non-native key (`tables`). The completed schema makes every verb key a **native `Illuminate\Database\Schema\Builder` method name** (Design Rule 1), following the `routes:` precedent (the shipped list was preserved verbatim under the method that actually registers it, `addRoute`). The shipped `tables:` surface moves under `create` (`Builder::create()`, still guarded by `hasTable()`); the four operations the inventory lists are added under their native names. This corrects the inventory's proposed keys: `schema.alter.tables.<table>` → **`schema.table`** (the native `Builder::table()` method name; `alter` is an invented verb), and `schema.drop`/`schema.dropIfExists`/`schema.rename` map 1:1.

- `create` / `table` — map of table name → table body (one `Builder::create()` / `Builder::table()` call per entry, Rule 2).
- `rename` — list of `{from, to}` entries (the native parameter names of `Builder::rename(string $from, string $to)`); one call per entry.
- `drop` / `dropIfExists` — list of table names; one call per entry. `drop` is intentionally **unguarded** (Laravel errors loudly on a missing table — Rule 7); `dropIfExists` is the native idempotent form. The pair expresses the intent choice natively instead of inventing a "guard" flag.
- Execution order is fixed and documented: **`dropIfExists` → `drop` → `rename` → `create` → `table`** (clear the way, rename targets, create new tables, adjust existing ones).

**Alter idempotency is derived, not declared.** Every action inside a `table:` body is dispatched through a guard derived from the action's own native semantics, mapped onto the Builder's own predicates (`hasColumn`/`hasColumns`/`hasIndex`/`hasForeignKey` — the same predicates `whenTableHasColumn()` & co. call internally, `Builder.php:299-360`):

| Declared action (kind) | Guard (native predicate) | Rationale |
|---|---|---|
| column factory (`string`, `integer`, …) | `! hasColumn($table, $column)` | add only when missing |
| column factory with `change: true` | `hasColumn($table, $column)` | change only when present |
| `dropColumn`, `dropConstrainedForeignId`, `removeColumn` | `hasColumns($table, $columns)` | drop only when present |
| `dropForeignIdFor` / `dropConstrainedForeignIdFor` | none (Laravel errors loudly) | target computed from the model |
| `dropPrimary`, `dropUnique`, `dropIndex`, `dropFullText`, `dropSpatialIndex`, `dropVectorIndex` | `hasIndex($table, $index)` | drop only when present |
| `dropForeign` | `hasForeignKey($table, $columns)` | constraint presence |
| `renameColumn` | `hasColumn($from) && ! hasColumn($to)` | idempotent rename |
| `renameIndex` | `hasIndex($from) && ! hasIndex($to)` | idempotent rename |
| `dropTimestamps`, `dropTimestampsTz`, `dropSoftDeletes`, `dropSoftDeletesTz`, `dropRememberToken`, `dropMorphs` | `hasColumn($canonical)` | conventional droppers (`created_at`, `remember_token`, `deleted_at`, `{name}_id`) |
| table-level index factories (`primary`, `unique`, `index`, `fullText`, `spatialIndex`, `vectorIndex`, `rawIndex`) | `! hasIndex($table, $columns)` | add only when missing |
| `foreign` | `! hasForeignKey($table, $columns)` | constraint add only when missing |
| multi-column factories (`timestamps`, `morphs`, `rememberToken`, `softDeletes`, `id`, …) | `! hasColumn($canonical or $column)` | add only when missing |
| table options (`engine`, `charset`, `collation`, `temporary`, `comment`) | none | property/command setters are naturally re-runnable |
| unrecognized verb | none | runs unguarded; inapplicable verbs fail with Laravel's own error (Rule 7) |

Because every action is individually guarded, a **fresh** database (where `create` built the full desired state) and an **existing** database (where `table` fills the diff) both converge without errors — the same convergence property the `hasTable()` guard gives creation.

### 2.2 Complete example

```yaml
schema:
  connection: ~                     # null = default connection; or 'sqlite', 'pgsql'

  # Builder::dropIfExists($table) — one call per entry
  dropIfExists:
    - scratch_table

  # Builder::drop($table) — one call per entry (unguarded: loud if missing)
  drop:
    - legacy_table

  # Builder::rename($from, $to) — one call per entry; keys are the native parameter names
  rename:
    - from: users
      to: people

  # Builder::create($table, Closure) — guarded by hasTable(); identical body shape to the shipped `tables:`
  create:
    people:
      id: ~
      string:
        - name
        - column: email
          unique: true
        - password
      timestamp:
        column: email_verified_at
        nullable: true
      rememberToken: ~
      timestamps: ~

    audits:
      id: ~
      string:
        column: user_id
      # standalone Blueprint::foreign() — FK modifiers now dispatch onto ForeignKeyDefinition (the fixed defect)
      foreign:
        - column: user_id
          references: people
          on: id
          cascadeOnDelete: true

  # Builder::table($table, Closure) — alter mode; every action individually guarded
  table:
    todos:
      string:
        - column: nickname
          length: 64
        - column: title
          length: 200
          change: true               # alter an existing column (ColumnDefinition @method change)
      integer:
        - column: priority
          default: 0
      dropColumn:
        - column: legacy_flag
      renameColumn:
        from: user_id
        to: owner_id
      renameIndex:
        from: todos_user_id_index
        to: todos_owner_id_index
      index:
        - columns: [owner_id, priority]     # named form — no longer mis-dispatched
          nullsNotDistinct: true            # IndexDefinition modifier (previously undeclarable)
      foreign:
        - column: owner_id
          references: people
          on: id
          cascadeOnDelete: true
      addColumn:                            # escape hatch: Blueprint::addColumn($type, $name, $parameters)
        - type: geometry
          name: location
          parameters: {srid: 4326}
      rawColumn:                            # escape hatch: Blueprint::rawColumn($column, $definition)
        - column: legacy_state
          definition: "VARCHAR(20)"
      rawIndex:                             # Blueprint::rawIndex($expression, $name)
        - expression: "lower(title)"
          name: todos_title_lower_idx
      dropTimestamps: ~                     # conventional dropper — guarded by hasColumn('created_at')
```

Column shapes are unchanged from the shipped surface (scalar shorthand, map with modifiers, list for duplicate types). New: map keys matching the method's **native parameter names** become named arguments; everything else is a validated modifier dispatched in declaration order.

### 2.3 Key → method → signature map

| YAML key | Target class | Native signature | Call shape |
|---|---|---|---|
| `connection` | `Schema` | `?string` | — |
| `create` | `Schema\Builder` | `create(string $table, Closure $callback): void` | one call per entry, `hasTable()`-guarded |
| `table` | `Schema\Builder` | `table(string $table, Closure $callback): void` | one call per entry, per-action guarded |
| `rename` | `Schema\Builder` | `rename(string $from, string $to): void` | one call per entry |
| `drop` | `Schema\Builder` | `drop(string $table): void` | one call per entry |
| `dropIfExists` | `Schema\Builder` | `dropIfExists(string $table): void` | one call per entry |
| table-body keys | `Schema\Blueprint` | every public DDL method (§1.3) | `$blueprint->{$method}(...$arguments)` |
| definition-map keys | factory parameters | `ReflectionParameter` names | PHP named arguments |
| remaining keys | `ColumnDefinition` / `ForeignIdColumnDefinition` / `ForeignKeyDefinition` / `IndexDefinition` | real methods + `@method` annotations | sequential fluent chain |

### 2.4 Notes / non-goals

- **No boot-time auto-migration** (unchanged from the shipped design): execution happens exclusively via `php artisan declaration:migrate`.
- **`Blueprint::after($column, Closure)` (batch grouping) is a non-goal** — it requires a Closure body. The column-level `after` *modifier* (`ColumnDefinition::after(string $column)`) covers positioning per column.
- **Blueprint macros** are not dispatched (`method_exists` gate only); declaring a macro'd factory name fails at hydration with `LogicException`.
- **`change()` is alter-only.** In `create` mode the `change` attribute is inert (the grammar only reads it for alter commands). Documented, not enforced.
- **No schema diffing.** The manifest is desired state; `table:` entries declare adjustments and are guarded per action — re-running converges. Full diff generation remains out of scope.
- **`dropIfExists` + `create` for the same table is a supported cycle** (drop first, then create) because execution order is fixed.

---

## 3. Implementation

### 3.0 `src/Internal/BlueprintMethodKind.php` — the dynamic classifier

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use Illuminate\Database\Schema\Blueprint;
use ReflectionMethod;

/**
 * Classification of an `Illuminate\Database\Schema\Blueprint` method by its
 * docblock `@return` tag — v13.33.0 `Blueprint` methods carry no native return types.
 *
 * @internal
 */
enum BlueprintMethodKind: string
{
    case Column = 'column';
    case Index = 'index';
    case ForeignKey = 'foreignKey';
    case Command = 'command';
    case Unit = 'unit';
    case Collection = 'collection';
    case Unknown = 'unknown';

    /** Blueprint lifecycle/execution verbs that must never be declared inside a table body. */
    public const array LIFECYCLE = ['build', 'toSql', 'create', 'drop', 'dropIfExists', 'rename', 'after'];

    /** @var array<string, self> */
    private const array RETURN_KINDS = [
        'ColumnDefinition' => self::Column,
        'ForeignIdColumnDefinition' => self::Column,
        'IndexDefinition' => self::Index,
        'ForeignKeyDefinition' => self::ForeignKey,
        'Fluent' => self::Command,
        'void' => self::Unit,
        'Collection' => self::Collection,
        '$this' => self::Command,
    ];

    public static function of(string $method): self
    {
        static $cache = [];

        if (! method_exists(Blueprint::class, $method)) {
            return self::Unknown;
        }

        if (isset($cache[$method])) {
            return $cache[$method];
        }

        return $cache[$method] = self::classify($method);
    }

    private static function classify(string $method): self
    {
        $docComment = (new ReflectionMethod(Blueprint::class, $method))->getDocComment();

        if ($docComment === false || ! preg_match('/@return\s+([\w\\\\$]+)/', $docComment, $matches)) {
            return self::Unknown;
        }

        $position = strrpos($matches[1], '\\');
        $short = $position === false ? $matches[1] : substr($matches[1], $position + 1);

        return self::RETURN_KINDS[$short] ?? self::Unknown;
    }

    /** @return array<string, int> map of `Blueprint::$method()` parameter name → position */
    public static function parameters(string $method): array
    {
        static $cache = [];

        if (isset($cache[$method])) {
            return $cache[$method];
        }

        $parameters = [];

        foreach ((new ReflectionMethod(Blueprint::class, $method))->getParameters() as $position => $parameter) {
            $parameters[$parameter->getName()] = $position;
        }

        return $cache[$method] = $parameters;
    }
}
```

### 3.1 `src/Internal/ActionGuard.php` — the native-predicate descriptor

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

/** @internal */
enum GuardKind: string
{
    case None = 'none';
    case ColumnMissing = 'column-missing';         // ! Builder::hasColumn()
    case ColumnExists = 'column-exists';           // Builder::hasColumn() / hasColumns()
    case IndexMissing = 'index-missing';           // ! Builder::hasIndex()
    case IndexExists = 'index-exists';             // Builder::hasIndex()
    case ForeignKeyMissing = 'foreignKey-missing'; // ! Builder::hasForeignKey()
    case ForeignKeyExists = 'foreignKey-exists';   // Builder::hasForeignKey()
}

/**
 * One derived idempotency predicate for a declared alter action. `target` is a
 * column name, a column list, an index name/column list, or an FK column list —
 * always resolved onto the native `Schema\Builder` predicates by the executor.
 *
 * @internal
 */
final readonly class ActionGuard
{
    public function __construct(
        public GuardKind $kind,
        public string|array|null $target = null,
    ) {}
}
```

### 3.2 `src/BlueprintAction.php` — one Blueprint invocation (renames `ColumnDefinitionModel`)

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
use Illuminate\Database\Schema\ForeignKeyDefinition;
use Illuminate\Database\Schema\IndexDefinition;
use Illuminate\Support\Fluent;
use LogicException;
use ReflectionClass;
use ZeroToProd\LaravelDeclaration\Internal\ActionGuard;
use ZeroToProd\LaravelDeclaration\Internal\BlueprintMethodKind;
use ZeroToProd\LaravelDeclaration\Internal\GuardKind;

/**
 * One declared `Blueprint` method invocation: the factory call (named or
 * positional arguments) plus the declared modifier chain, dispatched
 * sequentially in declaration order — `constrained()` transitions the chain
 * target from `ForeignIdColumnDefinition` to `ForeignKeyDefinition` exactly
 * as Laravel's fluent API does.
 */
final readonly class BlueprintAction
{
    /** Conventional column targets for zero-argument / void-returning factories and droppers. */
    private const array CANONICAL_COLUMNS = [
        'id' => 'id',
        'timestamps' => 'created_at',
        'timestampsTz' => 'created_at',
        'nullableTimestamps' => 'created_at',
        'nullableTimestampsTz' => 'created_at',
        'datetimes' => 'created_at',
        'rememberToken' => 'remember_token',
        'softDeletes' => 'deleted_at',
        'softDeletesTz' => 'deleted_at',
        'softDeletesDatetime' => 'deleted_at',
        'dropTimestamps' => 'created_at',
        'dropTimestampsTz' => 'created_at',
        'dropRememberToken' => 'remember_token',
    ];

    public function __construct(
        public string $method,
        public array $arguments = [],
        public array $modifiers = [],
    ) {}

    public function apply(Blueprint $Blueprint): mixed
    {
        $target = $Blueprint->{$this->method}(...$this->arguments);

        foreach ($this->modifiers as $modifier => $arguments) {
            if ($target instanceof ForeignIdColumnDefinition && ! method_exists($target, $modifier)) {
                throw new LogicException(
                    "Modifier [$modifier] on [{$this->method}] must be declared after `constrained` so the foreign key target exists."
                );
            }

            $next = $target->{$modifier}(...$this->spread($arguments));

            if ($next instanceof Fluent) {
                $target = $next;
            }
        }

        return $target;
    }

    /** @return list<ActionGuard> — all guards must pass before the action dispatches (AND semantics). */
    public function guards(): array
    {
        return match (BlueprintMethodKind::of($this->method)) {
            BlueprintMethodKind::Column => [$this->columnGuard()],
            BlueprintMethodKind::Index => [new ActionGuard(GuardKind::IndexMissing, $this->indexTarget() ?? [])],
            BlueprintMethodKind::ForeignKey => [new ActionGuard(GuardKind::ForeignKeyMissing, $this->argument('columns') ?? $this->argument(0) ?? [])],
            BlueprintMethodKind::Command => $this->commandGuards(),
            BlueprintMethodKind::Collection, BlueprintMethodKind::Unit => $this->convenienceGuards(),
            BlueprintMethodKind::Unknown => [],
        };
    }

    public static function fromDefinition(string $method, mixed $definition): self
    {
        $kind = BlueprintMethodKind::of($method);

        if ($kind === BlueprintMethodKind::Unknown) {
            throw new LogicException("Unknown Blueprint method [$method].");
        }

        if (in_array($method, BlueprintMethodKind::LIFECYCLE, true)) {
            throw new LogicException(
                "Blueprint method [$method] is a table lifecycle verb; use the schema `drop`, `dropIfExists`, `rename` or `create` operation."
            );
        }

        if ($definition === null || $definition === true) {
            return new self($method);
        }

        if (! is_array($definition)) {
            return new self($method, [$definition]);
        }

        if (array_is_list($definition)) {
            // One call: an index method receives the list as its `$columns`;
            // every other method spreads the list as positional arguments.
            return new self($method, $kind === BlueprintMethodKind::Index ? [$definition] : $definition);
        }

        $parameters = BlueprintMethodKind::parameters($method);
        $arguments = [];
        $modifiers = [];

        foreach ($definition as $key => $value) {
            if (isset($parameters[$key])) {
                $arguments[$key] = $value;

                continue;
            }

            if (! self::isValidModifier($kind, (string) $key)) {
                throw new LogicException("[$key] is neither a parameter of Blueprint::$method() nor a valid modifier.");
            }

            $modifiers[$key] = $value;
        }

        if ($modifiers !== [] && ! in_array($kind, [BlueprintMethodKind::Column, BlueprintMethodKind::Index, BlueprintMethodKind::ForeignKey], true)) {
            throw new LogicException("Blueprint::$method() does not accept modifiers.");
        }

        return new self($method, $arguments, $modifiers);
    }

    /** @return list<mixed>|array<string, mixed> */
    private function spread(mixed $arguments): array
    {
        if ($arguments === true || $arguments === null) {
            return [];
        }

        if (is_array($arguments)) {
            return $arguments;
        }

        return [$arguments];
    }

    private static function isValidModifier(BlueprintMethodKind $kind, string $modifier): bool
    {
        $targets = match ($kind) {
            BlueprintMethodKind::Column => [ColumnDefinition::class, ForeignIdColumnDefinition::class, ForeignKeyDefinition::class],
            BlueprintMethodKind::Index => [IndexDefinition::class],
            BlueprintMethodKind::ForeignKey => [ForeignKeyDefinition::class],
            default => [],
        };

        foreach ($targets as $target) {
            if (method_exists($target, $modifier) || isset(self::annotatedMethods($target)[$modifier])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> `@method`-annotated names up the class chain (memoized) */
    private static function annotatedMethods(string $class): array
    {
        static $cache = [];

        if (isset($cache[$class])) {
            return $cache[$class];
        }

        $methods = [];

        for ($current = $class; $current; $current = get_parent_class($current) ?: null) {
            $docComment = (new ReflectionClass($current))->getDocComment() ?: '';

            preg_match_all('/@method\s+(?:static\s+)?[\w\\\\$|]+\s+(\w+)\s*\(/', $docComment, $matches);

            foreach ($matches[1] as $name) {
                $methods[$name] = true;
            }
        }

        return $cache[$class] = $methods;
    }

    private function argument(string|int $key): mixed
    {
        if (is_string($key)) {
            $position = BlueprintMethodKind::parameters($this->method)[$key] ?? null;

            return $this->arguments[$key] ?? ($position !== null ? ($this->arguments[$position] ?? null) : null);
        }

        return $this->arguments[$key] ?? null;
    }

    private function columnGuard(): ActionGuard
    {
        $column = $this->argument('column')
            ?? self::CANONICAL_COLUMNS[$this->method]
            ?? $this->morphTarget();

        if ($column === null) {
            return new ActionGuard(GuardKind::None);
        }

        $exists = ($this->modifiers['change'] ?? false) !== false;

        return new ActionGuard($exists ? GuardKind::ColumnExists : GuardKind::ColumnMissing, $column);
    }

    private function convenienceGuards(): array
    {
        $column = $this->argument('column')
            ?? self::CANONICAL_COLUMNS[$this->method]
            ?? $this->morphTarget();

        if ($column === null) {
            return [];
        }

        $exists = str_starts_with($this->method, 'drop');

        return [new ActionGuard($exists ? GuardKind::ColumnExists : GuardKind::ColumnMissing, $column)];
    }

    /** @return list<ActionGuard> */
    private function commandGuards(): array
    {
        $arguments = $this->stringArguments();

        return match (true) {
            in_array($this->method, ['dropColumn', 'dropConstrainedForeignId', 'removeColumn'], true) && $arguments !== []
                => [new ActionGuard(GuardKind::ColumnExists, $arguments)],
            in_array($this->method, ['dropPrimary', 'dropUnique', 'dropIndex', 'dropFullText', 'dropSpatialIndex', 'dropVectorIndex'], true) && $arguments !== []
                => [new ActionGuard(GuardKind::IndexExists, $arguments)],
            $this->method === 'dropForeign' && $arguments !== []
                => [new ActionGuard(GuardKind::ForeignKeyExists, $arguments)],
            $this->method === 'renameColumn' && count($arguments) === 2
                => [new ActionGuard(GuardKind::ColumnExists, $arguments[0]), new ActionGuard(GuardKind::ColumnMissing, $arguments[1])],
            $this->method === 'renameIndex' && count($arguments) === 2
                => [new ActionGuard(GuardKind::IndexExists, $arguments[0]), new ActionGuard(GuardKind::IndexMissing, $arguments[1])],
            default => [],
        };
    }

    /** @return list<string> every scalar string argument (positional or named), flattened */
    private function stringArguments(): array
    {
        $flat = [];

        array_walk_recursive($this->arguments, function (mixed $value) use (&$flat): void {
            if (is_string($value)) {
                $flat[] = $value;
            }
        });

        return $flat;
    }

    private function indexTarget(): ?string
    {
        $columns = $this->argument('columns') ?? $this->argument(0);

        return is_string($columns) ? $columns : null;
    }

    private function morphTarget(): ?string
    {
        if ($this->method !== 'morphs' && ! str_ends_with($this->method, 'Morphs')) {
            return null;
        }

        $name = $this->argument('name');

        return is_string($name) ? "{$name}_id" : null;
    }
}
```

### 3.3 `src/TableDefinition.php` — one actions bag, no static key lists

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Database\Schema\Blueprint;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class TableDefinition
{
    use DataModel;

    /**
     * Declaration-ordered actions: every key is a native `Blueprint` method name.
     *
     * @param  array<string, list<BlueprintAction>>  $actions
     */
    public function __construct(
        public array $actions = []
    ) {}

    public function apply(Blueprint $table): void
    {
        foreach ($this->actions as $actions) {
            foreach ($actions as $action) {
                $action->apply($table);
            }
        }
    }

    public static function from(mixed $context = []): self
    {
        if ($context instanceof self) {
            return $context;
        }

        if (! is_array($context)) {
            return new self;
        }

        $actions = [];

        foreach ($context as $key => $value) {
            $method = (string) $key;
            $items = is_array($value) && array_is_list($value) ? $value : [$value];

            foreach ($items as $item) {
                $actions[$method][] = BlueprintAction::fromDefinition($method, $item);
            }
        }

        return new self($actions);
    }
}
```

The `options` / `columns` / `indexes` bags, the two static key lists (`['engine', …]`, `['primary', …]`), the `count($context) === 3` explicit-form branch, and the `property_exists` option dispatch all disappear: table options are ordinary `Blueprint` methods (§1.3), indexes are `IndexDefinition`-returning methods, and YAML declaration order is preserved end-to-end.

### 3.4 `src/Schema.php` — five native `Builder` verbs

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Support\Collection;
use ReflectionProperty;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Schema
{
    use DataModel;

    public const string connection = 'connection';

    public const string create = 'create';

    public const string table = 'table';

    public const string rename = 'rename';

    public const string drop = 'drop';

    public const string dropIfExists = 'dropIfExists';

    #[Key, Describe([Describe::nullable => true])]
    public ?string $connection;

    /** @var Collection<string, TableDefinition> */
    #[Key, Describe([Describe::default => [], Describe::cast => [self::class, 'mapOf'], 'type' => TableDefinition::class])]
    public Collection $create;

    /** @var Collection<string, TableDefinition> */
    #[Key, Describe([Describe::default => [], Describe::cast => [self::class, 'mapOf'], 'type' => TableDefinition::class])]
    public Collection $table;

    /** @var list<TableRename> */
    #[Key, Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => TableRename::class])]
    public array $rename;

    /** @var list<string> */
    #[Key, Describe([Describe::default => []])]
    public array $drop;

    /** @var list<string> */
    #[Key, Describe([Describe::default => []])]
    public array $dropIfExists;

    /**
     * @param  array<string, mixed>  $context
     * @return list<TableRename>
     */
    public static function listOf(mixed $value, array $context, ?ReflectionAttribute $Attribute, ReflectionProperty $Property): array
    {
        $arguments = $Attribute?->getArguments()[0];
        $type = is_array($arguments) ? ($arguments['type'] ?? null) : null;

        if (! is_string($type) || ! is_array($value)) {
            return [];
        }

        /** @var list<array<string, mixed>> $items */
        $items = array_values($value);

        return array_map(static fn (array $item): object => $type::from($item), $items);
    }
}
```

### 3.5 `src/TableRename.php` — one `Builder::rename()` call per entry

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class TableRename
{
    use DataModel;

    public const string from = 'from';

    public const string to = 'to';

    #[Describe([Describe::required => true])]
    public string $from;

    #[Describe([Describe::required => true])]
    public string $to;
}
```

`from`/`to` are the native parameter names of `Builder::rename(string $from, string $to)` — no invented nouns.

### 3.6 `src/Internal/Commands/MigrateCommand.php` — the five operations + guard dispatch

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use ZeroToProd\LaravelDeclaration\Internal\ActionGuard;
use ZeroToProd\LaravelDeclaration\Internal\GuardKind;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Schema;
use ZeroToProd\LaravelDeclaration\TableDefinition;

/** @internal */
class MigrateCommand extends Command
{
    /** @var string */
    protected $signature = 'declaration:migrate';

    /** @var array<int, string> */
    protected $aliases = ['laravel-declaration:migrate'];

    /** @var string */
    protected $description = 'Execute declarative database schema actions declared in manifest';

    public function handle(Manifest $manifest): int
    {
        if (! $manifest->schema instanceof Schema) {
            $this->components->info('No declarative schema defined in manifest.');

            return self::SUCCESS;
        }

        $schema = $manifest->schema;
        $builder = SchemaFacade::connection($schema->connection);

        // Fixed execution order: clear the way, rename targets, create, adjust.
        foreach ($schema->dropIfExists as $table) {
            $builder->dropIfExists($table);
            $this->components->twoColumnDetail($table, '<fg=yellow>Dropped if exists</>');
        }

        foreach ($schema->drop as $table) {
            $builder->drop($table);
            $this->components->twoColumnDetail($table, '<fg=yellow>Dropped</>');
        }

        foreach ($schema->rename as $rename) {
            if (! $builder->hasTable($rename->from) || $builder->hasTable($rename->to)) {
                $this->components->twoColumnDetail($rename->from, '<fg=gray>Rename skipped</>');

                continue;
            }

            $builder->rename($rename->from, $rename->to);
            $this->components->twoColumnDetail($rename->from, "<fg=green;options=bold>Renamed to {$rename->to}</>");
        }

        $created = 0;

        foreach ($schema->create as $table => $definition) {
            if ($builder->hasTable($table)) {
                $this->components->twoColumnDetail($table, '<fg=gray>Already exists</>');

                continue;
            }

            $builder->create($table, fn (Blueprint $blueprint) => $definition->apply($blueprint));
            $this->components->twoColumnDetail($table, '<fg=green;options=bold>Created</>');
            $created++;
        }

        foreach ($schema->table as $table => $definition) {
            $this->alter($builder, $table, $definition);
        }

        $this->components->info("Schema migration complete. [{$created}] table(s) created.");

        return self::SUCCESS;
    }

    private function alter(Builder $builder, string $table, TableDefinition $definition): void
    {
        if (! $builder->hasTable($table)) {
            $this->components->twoColumnDetail($table, '<fg=yellow>Table does not exist</>');

            return;
        }

        $altered = 0;

        foreach ($definition->actions as $actions) {
            foreach ($actions as $action) {
                foreach ($action->guards() as $guard) {
                    if (! $this->passes($builder, $table, $guard)) {
                        continue 2;
                    }
                }

                $builder->table($table, fn (Blueprint $blueprint) => $action->apply($blueprint));
                $altered++;
            }
        }

        if ($altered > 0) {
            $this->components->twoColumnDetail($table, "<fg=green;options=bold>Altered [{$altered}] action(s)</>");
        }
    }

    private function passes(Builder $builder, string $table, ActionGuard $guard): bool
    {
        $target = $guard->target;

        return match ($guard->kind) {
            GuardKind::None => true,
            GuardKind::ColumnMissing => is_array($target)
                ? ! $builder->hasColumns($table, $target)
                : ! $builder->hasColumn($table, (string) $target),
            GuardKind::ColumnExists => is_array($target)
                ? $builder->hasColumns($table, $target)
                : $builder->hasColumn($table, (string) $target),
            GuardKind::IndexMissing => ! $builder->hasIndex($table, $target ?? []),
            GuardKind::IndexExists => $builder->hasIndex($table, $target ?? []),
            GuardKind::ForeignKeyMissing => ! $builder->hasForeignKey($table, $target ?? []),
            GuardKind::ForeignKeyExists => $builder->hasForeignKey($table, $target ?? []),
        };
    }
}
```

### 3.7 `src/ColumnDefinitionModel.php` — deleted

Replaced by `src/BlueprintAction.php`. Its three argument-shaping rules (`args`, `name`/`column` → first positional, the FK-modifier name list `str_ends_with('OnDelete'|'OnUpdate')`), the `applyConstraint()` map form, and the `constrained` special case are all subsumed by native named arguments and sequential dispatch. The `ForeignKeyDefinition` modifier discard (§1.5 defect 1) cannot recur: there is no modifier bag that depends on the declared type — the chain target is the object Laravel actually returns.

### 3.8 `src/Manifest.php` — unchanged

`Manifest::$schema` keeps its `?Schema` type and `#[Describe([Describe::nullable => true])]` attribute; the block model changed shape, not the manifest integration.

### 3.9 `manifest.schema.json` delta

```json
"schema": {
  "description": "Declarative database schema definition. Verb keys are native Illuminate\\Database\\Schema\\Builder method names.",
  "type": ["object", "null"],
  "additionalProperties": false,
  "properties": {
    "connection": {
      "description": "The database connection name. If omitted or null, the default connection is used.",
      "type": ["string", "null"]
    },
    "create": {
      "description": "Builder::create($table, $callback) — one guarded call per entry; body = Blueprint methods.",
      "type": "object",
      "additionalProperties": { "$ref": "#/definitions/tableDefinition" }
    },
    "table": {
      "description": "Builder::table($table, $callback) — alter blueprint; every action is dispatched through its derived native guard.",
      "type": "object",
      "additionalProperties": { "$ref": "#/definitions/tableDefinition" }
    },
    "rename": {
      "description": "Builder::rename($from, $to) — one call per entry.",
      "type": "array",
      "items": {
        "type": "object",
        "additionalProperties": false,
        "required": ["from", "to"],
        "properties": {
          "from": { "type": "string" },
          "to": { "type": "string" }
        }
      }
    },
    "drop": {
      "description": "Builder::drop($table) — one call per entry (unguarded: fails loudly if the table is missing).",
      "type": "array",
      "items": { "type": "string" }
    },
    "dropIfExists": {
      "description": "Builder::dropIfExists($table) — one call per entry.",
      "type": "array",
      "items": { "type": "string" }
    }
  }
}
```

`definitions.tableDefinition` stays permissive (`additionalProperties: anyOf null|string|number|boolean|array|object`) — hydration (`BlueprintAction::fromDefinition`) is the fail-fast authority (Rule 7), as it is today.

### 3.10 Documentation updates

1. `docs/declarative-schema.md` — §1.3.1 gains `table`/`rename`/`drop`/`dropIfExists`/`hasIndex`/`hasForeignKey` rows; §1.3.4 gains the standalone `foreign()` row; §1.3.5 gains the alter verbs and index modifiers; §1.4 pseudocode replaced by the sequential-dispatch form; §2 rewritten around the five Builder verbs; §3.5 updated for the new `MigrateCommand`.
2. `docs/declarative-tier1-gap-inventory.md` §2.5 — appended **Resolution** paragraph (house pattern from §2.1–§2.4): the four Builder operations ship under their native names (`schema.alter.tables.<table>` corrected to `schema.table` per Rule 1), the `ForeignKeyDefinition` modifier discard and the flat-form `vectorIndex`/`rawIndex` omission are closed by dynamic dispatch, and remediation items 1–2 are marked done; §1 row 8 reclassifies `[/]` → `[x]`.
3. `docs/declarative-request-to-view-roadmap.md` §3.1 — the `schema` bullet gains "table operations (alter/rename/drop/dropIfExists) and idempotent, guard-derived alters".

---

## 4. Tests

Fixture `tests/Fixtures/manifest/schema.yml` — the shipped `tables:` key becomes `create:` (bodies unchanged). New fixture `tests/Fixtures/manifest/schema-alter.yml`:

```yaml
schema:
  connection: ~
  dropIfExists:
    - scratch
  drop:
    - legacy
  rename:
    - from: users
      to: people
  create:
    people:
      id: ~
      string:
        - column: title
          length: 100
        - column: user_id
      timestamps: ~
  table:
    people:
      string:
        - column: nickname
          length: 64
        - column: title
          length: 200
          change: true
      integer:
        - column: priority
          default: 0
      dropColumn:
        - column: obsolete
      renameColumn:
        from: user_id
        to: owner_id
      renameIndex:
        from: people_title_index
        to: people_title_ft
      dropIndex:
        - people_title_index
      index:
        - columns: [owner_id, priority]
          nullsNotDistinct: true
      foreign:
        - column: owner_id
          references: people
          on: id
          cascadeOnDelete: true
      dropTimestamps: ~
```

`tests/Feature/SchemaRegistrationTest.php` — the shipped create tests are updated for the `create:` key; new coverage:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use LogicException;
use ZeroToProd\LaravelDeclaration\BlueprintAction;
use ZeroToProd\LaravelDeclaration\Internal\BlueprintMethodKind;
use ZeroToProd\LaravelDeclaration\Internal\GuardKind;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Schema;
use ZeroToProd\LaravelDeclaration\TableDefinition;

function blueprint(): Blueprint
{
    return new Blueprint(DB::connection(), 'people');
}

test('it executes drop, dropIfExists, rename, create, and table in fixed order', function (): void {
    SchemaFacade::dropIfExists('legacy');
    SchemaFacade::dropIfExists('users');
    SchemaFacade::create('users', fn (Blueprint $table) => $table->id());

    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/schema-alter.yml']);

    $this->artisan('declaration:migrate')
        ->expectsOutputToContain('Dropped if exists')
        ->expectsOutputToContain('Dropped')
        ->expectsOutputToContain('Renamed to people')
        ->expectsOutputToContain('Created')
        ->assertSuccessful();

    expect(SchemaFacade::hasTable('scratch'))->toBeFalse()
        ->and(SchemaFacade::hasTable('legacy'))->toBeFalse()
        ->and(SchemaFacade::hasTable('users'))->toBeFalse()
        ->and(SchemaFacade::hasTable('people'))->toBeTrue();
});

test('it alters an existing table idempotently through derived native guards', function (): void {
    SchemaFacade::dropIfExists('people');
    SchemaFacade::create('people', function (Blueprint $table): void {
        $table->id();
        $table->string('title', 100);
        $table->string('user_id');
        $table->string('obsolete');
        $table->index('title', 'people_title_index');
        $table->timestamps();
    });

    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/schema-alter.yml']);

    $this->artisan('declaration:migrate')->assertSuccessful();

    expect(SchemaFacade::hasColumns('people', ['nickname', 'priority', 'owner_id']))
        ->toBeTrue()
        ->and(SchemaFacade::hasColumn('people', 'obsolete'))->toBeFalse()
        ->and(SchemaFacade::hasColumn('people', 'user_id'))->toBeFalse()
        ->and(SchemaFacade::getColumnListing('people'))->toContain('owner_id')->not->toContain('obsolete')
        ->and(collect(SchemaFacade::getIndexes('people'))->first(
            fn (array $index): bool => $index['name'] === 'people_owner_id_priority_index'
        ))->not->toBeNull()
        ->and(collect(SchemaFacade::getForeignKeys('people'))->first(
            fn (array $fk): bool => $fk['columns'] === ['owner_id']
        )['on_delete'])->toBe('cascade');

    // Idempotent re-run: every guard fails, zero actions dispatch.
    $before = SchemaFacade::getColumnListing('people');

    $this->artisan('declaration:migrate')->assertSuccessful();

    expect(SchemaFacade::getColumnListing('people'))->toBe($before);
});

test('it dispatches the standalone foreign method onto ForeignKeyDefinition modifiers', function (): void {
    SchemaFacade::dropIfExists('audits');

    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/schema.yml']);

    $this->artisan('declaration:migrate')->assertSuccessful();

    $foreignKey = collect(SchemaFacade::getForeignKeys('audits'))->first(
        fn (array $fk): bool => $fk['columns'] === ['user_id']
    );

    expect($foreignKey)->not->toBeNull()
        ->and($foreignKey['foreign_table'])->toBe('people')
        ->and($foreignKey['foreign_columns'])->toBe(['id'])
        ->and($foreignKey['on_delete'])->toBe('cascade');
});

test('it throws when foreign key modifiers target a non-foreign-key column', function (): void {
    // Hydration: cascadeOnDelete is not a ColumnDefinition/ForeignIdColumnDefinition modifier.
    expect(fn () => BlueprintAction::fromDefinition('string', ['column' => 'x', 'cascadeOnDelete' => true]))
        ->toThrow(LogicException::class);

    // Execution: FK modifier declared before `constrained` on a ForeignIdColumnDefinition.
    $action = BlueprintAction::fromDefinition('foreignId', ['column' => 'user_id', 'cascadeOnDelete' => true, 'constrained' => true]);

    expect(fn () => $action->apply(blueprint()))->toThrow(LogicException::class);
});

test('it throws on unknown modifier keys and lifecycle verbs', function (): void {
    expect(fn () => BlueprintAction::fromDefinition('string', ['column' => 'x', 'nulable' => true]))
        ->toThrow(LogicException::class)
        ->and(fn () => TableDefinition::from(['create' => []]))
        ->toThrow(LogicException::class)
        ->and(fn () => TableDefinition::from(['drop' => []]))
        ->toThrow(LogicException::class)
        ->and(fn () => TableDefinition::from(['build' => null]))
        ->toThrow(LogicException::class);
});

test('it dispatches index modifiers, addColumn, rawColumn, and rawIndex onto the blueprint', function (): void {
    $table = blueprint();

    TableDefinition::from([
        'index' => [['columns' => ['title'], 'nullsNotDistinct' => true]],
        'rawIndex' => [['expression' => 'lower(title)', 'name' => 'people_title_lower_idx']],
        'addColumn' => [['type' => 'integer', 'name' => 'count']],
        'rawColumn' => [['column' => 'legacy_state', 'definition' => 'VARCHAR(20)']],
    ])->apply($table);

    $commands = collect($table->getCommands())->map(fn ($command) => $command->toArray());

    expect($commands->firstWhere('name', 'index')['nullsNotDistinct'])->toBeTrue()
        ->and($commands->firstWhere('name', 'index')['columns'])->toBe(['title'])
        ->and($commands->firstWhere('name', 'rawIndex')['name'])->toBe('people_title_lower_idx')
        ->and(collect($table->getColumns())->first(fn ($column) => $column['type'] === 'raw')['name'])->toBe('legacy_state')
        ->and(collect($table->getColumns())->first(fn ($column) => $column['type'] === 'integer')['name'])->toBe('count');
});

test('it derives native guards per alter action', function (): void {
    $add = BlueprintAction::fromDefinition('integer', ['column' => 'priority', 'default' => 0]);
    $change = BlueprintAction::fromDefinition('string', ['column' => 'title', 'length' => 200, 'change' => true]);
    $drop = BlueprintAction::fromDefinition('dropColumn', [['columns' => ['obsolete']]]);
    $rename = BlueprintAction::fromDefinition('renameColumn', ['from' => 'user_id', 'to' => 'owner_id']);
    $morphs = BlueprintAction::fromDefinition('morphs', ['name' => 'taggable']);
    $timestamps = BlueprintAction::fromDefinition('timestamps', null);

    expect($add->guards()[0]->kind)->toBe(GuardKind::ColumnMissing)
        ->and($add->guards()[0]->target)->toBe('priority')
        ->and($change->guards()[0]->kind)->toBe(GuardKind::ColumnExists)
        ->and($change->guards()[0]->target)->toBe('title')
        ->and($drop->guards()[0]->kind)->toBe(GuardKind::ColumnExists)
        ->and($drop->guards()[0]->target)->toBe(['obsolete'])
        ->and($rename->guards()[0]->kind)->toBe(GuardKind::ColumnExists)
        ->and($rename->guards()[0]->target)->toBe('user_id')
        ->and($rename->guards()[1]->kind)->toBe(GuardKind::ColumnMissing)
        ->and($rename->guards()[1]->target)->toBe('owner_id')
        ->and($morphs->guards()[0]->kind)->toBe(GuardKind::ColumnMissing)
        ->and($morphs->guards()[0]->target)->toBe('taggable_id')
        ->and($timestamps->guards()[0]->target)->toBe('created_at');
});

test('it classifies Blueprint methods by docblock return type', function (): void {
    expect(BlueprintMethodKind::of('string'))->toBe(BlueprintMethodKind::Column)
        ->and(BlueprintMethodKind::of('foreignId'))->toBe(BlueprintMethodKind::Column)
        ->and(BlueprintMethodKind::of('index'))->toBe(BlueprintMethodKind::Index)
        ->and(BlueprintMethodKind::of('vectorIndex'))->toBe(BlueprintMethodKind::Index)
        ->and(BlueprintMethodKind::of('rawIndex'))->toBe(BlueprintMethodKind::Index)
        ->and(BlueprintMethodKind::of('foreign'))->toBe(BlueprintMethodKind::ForeignKey)
        ->and(BlueprintMethodKind::of('dropColumn'))->toBe(BlueprintMethodKind::Command)
        ->and(BlueprintMethodKind::of('renameIndex'))->toBe(BlueprintMethodKind::Command)
        ->and(BlueprintMethodKind::of('morphs'))->toBe(BlueprintMethodKind::Unit)
        ->and(BlueprintMethodKind::of('temporary'))->toBe(BlueprintMethodKind::Unit)
        ->and(BlueprintMethodKind::of('timestamps'))->toBe(BlueprintMethodKind::Collection)
        ->and(BlueprintMethodKind::of('removeColumn'))->toBe(BlueprintMethodKind::Command)
        ->and(BlueprintMethodKind::of('toSql'))->toBe(BlueprintMethodKind::Unknown);
});
```

Additional coverage required by the Definition-of-Done `--min=100` gate: the `GuardKind::None` branch (a `Column`-kind factory with no derivable target — e.g. a future zero-argument factory not in the canonical map), `GuardKind::ForeignKeyExists` (declare `dropForeign: [people_owner_id_foreign]` in the alter fixture), the `dropForeignIdFor` unguarded path, `Schema::listOf` with a non-array value, and the `MigrateCommand` early-return guard (`No declarative schema defined in manifest.` — already covered by the shipped suite).

---

## 5. Verification log (claims vs source of truth, v13.33.0)

1. **Claim: the four Builder operations are missing.** Confirmed — `src/Internal/Commands/MigrateCommand.php` calls only `hasTable()` and `create()`; `Builder::table/rename/drop/dropIfExists` verified at `Builder.php:508,612,535,548`.
2. **Claim: alter verbs missing.** Refined — they exist on `Blueprint` (`Blueprint.php:422-634`, `rawColumn:1757` two args, `addColumn:1849`, `removeColumn:1901`) and already dispatch through `method_exists`, but (a) the flat form mis-classifies index methods and multi-arg factories, (b) no execution seam calls `Builder::table()`. Both are what this plan ships.
3. **Claim: `ForeignKeyDefinition` modifier discard.** Confirmed — `ForeignKeyDefinition extends Fluent` (`ForeignKeyDefinition.php:17`), and `src/ColumnDefinitionModel.php::apply()` has no `ForeignKeyDefinition` branch, so `foreign: {...cascadeOnDelete: true}` silently discards.
4. **Claim: flat form omits `vectorIndex`/`rawIndex`.** Confirmed — `TableDefinition::from()` static list is `['primary', 'unique', 'index', 'fullText', 'spatialIndex']`; `vectorIndex` is `Blueprint.php:724`, `rawIndex` `Blueprint.php:740`.
5. **New finding: named-index map form mis-dispatches** — the shipped `indexes:` loop passes an assoc map as `$columns`; the plan's named-argument form replaces it.
6. **New finding: no native return types on `Blueprint` methods** (reflection-verified) — docblock `@return` is the only dynamic classification source, and it is complete for the DDL surface (§1.4).
7. **New finding: `change()` is a `Fluent::__call` attribute** (`Fluent.php:299`; `ColumnDefinition.php:11` `@method`), consumed by `compileChange`/`BlueprintState` — declarable today, pinned by test.
8. **New finding: `Blueprint::engine/charset/collation/temporary` are property setters** (`Blueprint.php:347-395`) — the `property_exists` dispatch branch is dead weight; method dispatch suffices.
9. **New finding: `build()`'s docblock is `@return void`** — a kind-only allowlist would admit it; the `LIFECYCLE` deny list is required for hydration safety.
10. **Guard primitives verified:** `hasIndex()` matches index name *or* column list (`Builder.php:444`), `hasForeignKey()` matches name *or* columns (`Builder.php:471`), `whenTableHasColumn()`/`whenTableDoesntHaveColumn()` are `predicate + table()` (`Builder.php:299-360`) — the executor's predicate form is exactly equivalent.
11. **Constraint on guards:** `hasIndex($table, $index, $type)` type-matching is case-sensitive against grammar output (`$type === $value['type']`, `Builder.php:449-455`); the derived guards pass no type (any index on the columns counts) — documented trade-off, avoids false negatives for `vectorIndex`-style types.
12. **Coverage/DoD:** `composer check` (`lint`, `rector-lint`, `analyse`, `coverage --min=100`, `bc-check`) must pass after implementation; §4 enumerates the branch-level tests for the new guard/classifier code.
