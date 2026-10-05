# Declarative Schema — `Illuminate\Database\Schema\Builder` & `Blueprint` Table Creation, Column Types, Modifiers, Indexes & Manifest Schema

> Manifest forms in this document are the pre-engine block shapes; see docs/general-purpose-migration-plan.md §2.1 and README for the current forms.

Source of truth: `vendor/laravel/framework/src/Illuminate/Database/Schema/Builder.php` (`laravel/framework` v13.33.0), with `Illuminate/Database/Schema/Blueprint.php`, `Illuminate/Database/Schema/ColumnDefinition.php`, `Illuminate/Database/Schema/ForeignIdColumnDefinition.php`, `Illuminate/Database/Schema/ForeignKeyDefinition.php`, `Illuminate/Database/Schema/IndexDefinition.php`, `Illuminate/Database/Connection.php`, `Illuminate/Database/DatabaseManager.php`, `Illuminate/Database/DatabaseServiceProvider.php`, and Laravel documentation: `docs/repos/laravel/docs/migrations.md` and `docs/repos/laravel/docs/database.md`.

Grounding documentation: `docs/declarative-request-to-view-roadmap.md` §1 Stage 5, §3 Phase 6.

Goal: a `schema:` block in `manifest/app.yml` whose **entries declare database schema operations without hand-written migration files**. Every verb key is a native `Illuminate\Database\Schema\Builder` method name (`create`, `table`, `rename`, `drop`, `dropIfExists`) and the `create:`/`table:` maps key table names to column, constraint, index, and table option definitions. The database schema catalog and grammar serve as the **system of record** for schema state, with `manifest/app.yml` acting as the declarative **data source**. **Every table-body key is an `Illuminate\Database\Schema\Blueprint` method name, and its value is that method's argument(s) and chained fluent modifiers.** The package ships the `Schema` DataModel, and leverages **dynamic dispatch** to invoke `Builder` operations, `Blueprint` factories, and the fluent modifier chain (`ColumnDefinition` → `ForeignIdColumnDefinition` → `ForeignKeyDefinition`) without monolithic conditional branching or hardcoded switch statements. Table creation executes idempotently via `php artisan declaration:migrate`, guarded by `Builder::hasTable($table)`; alter actions are individually guarded by the Builder's own `hasColumn`/`hasIndex`/`hasForeignKey` predicates (§2.1).

---

## 1. Public API of `Builder::class` and `Blueprint::class`

### 1.1 Lifecycle position (when the declaration runs)

```
// 1. Application initialization (bootstrap/app.php)
new Application($basePath);
ApplicationBuilder::withKernels()                  $app->singleton(HttpKernelContract::class, HttpKernel::class)
ApplicationBuilder::withProviders()                loads service providers

// 2. Provider registration & boot
DatabaseServiceProvider::register()                DatabaseServiceProvider.php:41:
                                                       Model::clearBootedModels()
                                                       $app->singleton('db.factory', ConnectionFactory::class)
                                                       $app->singleton('db', DatabaseManager::class)
                                                       $app->bind('db.schema', fn ($app) => $app['db']->connection()->getSchemaBuilder())
LaravelDeclarationProvider::register()             Manifest::$schema hydrated; Schema::validate() per table definition
                                                       <- unknown keys throw LogicException HERE
    $app->register(SchemaDeclarationServiceProvider::class)

$app->boot()                                       bootProviders()
    DatabaseServiceProvider::boot()                Model::setConnectionResolver($app['db'])
    SchemaDeclarationServiceProvider::boot()       $this->callAfterResolving('db', fn ($db) => ...):
                                                       <- Schema declaration QUEUED or APPLIED here:
                                                       Iterates $manifest->schema->tables
                                                       Guard: Schema::connection($conn)->hasTable($table)
                                                       If table does not exist:
                                                           Schema::connection($conn)->create($table, function (Blueprint $table) {
                                                               Dynamic dispatch of column factory calls
                                                               Dynamic dispatch of column modifier chains
                                                               Dynamic dispatch of table constraints & indexes
                                                           })

// 3. Optional explicit CLI execution:
// php artisan declaration:migrate                Idempotently executes missing table creation
```

Consequences, each verified against v13.33.0 with Testbench:

1. **Deferred until database connection resolver is bound.** `DatabaseServiceProvider::boot()` binds `Model::setConnectionResolver($this->app['db'])`. Registering the schema migration callback via `callAfterResolving('db')` ensures the database connection driver, PDO instance, and schema grammar are instantiated and configured before any DDL statements execute.
2. **Idempotent table creation via `hasTable()`.** `Illuminate\Database\Schema\Builder::hasTable($table)` checks table existence directly against database schema metadata (`SQLiteBuilder::getTables()`, `MySqlBuilder::getTables()`, `PostgresBuilder::getTables()`). If a table already exists, schema execution is safely skipped without throwing "table already exists" database errors.
3. **Fail-fast validation at boot.** Unknown table keys or invalid column declarations throw `LogicException` during manifest hydration in `LaravelDeclarationProvider::register()`, before any database connection attempt.
4. **Dynamic dispatch eliminates hardcoded procedural branching.** Column definitions, fluent modifiers, and indexes invoke `Blueprint` and `ColumnDefinition` methods dynamically via polymorphic attribute dispatch, matching the architectural patterns of `Kernel` and `Query`.

### 1.2 Properties

**Internal state on `Illuminate\Database\Schema\Builder`:**

| Property | Type | Visibility | Role in Declaration |
|---|---|---|---|
| `$connection` | `Illuminate\Database\Connection` | `protected` | Underlying database connection instance |
| `$grammar` | `Illuminate\Database\Schema\Grammars\Grammar` | `protected` | Connection-specific schema grammar compiling blueprint commands to DDL SQL |
| `$resolver` | `Closure` | `protected` | Blueprint resolver callback instantiating `Blueprint` instances |
| `$defaultStringLength` | `int` (static, default 255) | `public` | Default length applied to `string()` columns when omitted |
| `$defaultTimePrecision` | `int\|null` (static, default 0) | `public` | Default precision for time/timestamp columns |
| `$defaultMorphKeyType` | `string` (static, default `'int'`) | `public` | Morph key column type (`'int'`, `'uuid'`, or `'ulid'`) |

**Internal state on `Illuminate\Database\Schema\Blueprint`:**

| Property | Type | Visibility | Role in Declaration |
|---|---|---|---|
| `$table` | `string` | `protected` | The table name being created or altered |
| `$columns` | `list<ColumnDefinition>` | `protected` | Columns registered for creation via column factory methods |
| `$commands` | `list<Fluent>` | `protected` | DDL commands (table options, indexes, drop actions) queued on the blueprint |
| `$after` | `string` | `protected` | Column name after which additions are positioned |

**Not declaration targets:**
- `$resolver`, `$grammar`, `$connection`: Internal framework plumbing configured by `DatabaseServiceProvider`.
- `$commands`, `$columns`: Populated dynamically by method invocation on `Blueprint`.

### 1.3 Public methods

#### 1.3.1 `Builder` public methods

**Declaration targets: Database Schema Builder**

| Method | Signature | Effect | Source |
|---|---|---|---|
| `create` | `create($table, Closure $callback)` | Execute table creation DDL via Blueprint callback (guarded by `hasTable()`) | `Builder.php:520` |
| `table` | `table($table, Closure $callback)` | Alter existing table schema via Blueprint callback (per-action guarded) | `Builder.php:508` |
| `hasTable` | `hasTable($table)` | Verify whether a table exists in the schema | `Builder.php:169` |
| `hasColumn` | `hasColumn($table, $column)` | Verify whether a column exists (case-insensitive) | `Builder.php:270` |
| `hasColumns` | `hasColumns($table, array $columns)` | Verify all columns exist | `Builder.php:284` |
| `hasIndex` | `hasIndex($table, $index, $type = null)` | Verify index by **name or column list** | `Builder.php:444` |
| `hasForeignKey` | `hasForeignKey($table, $foreignKey)` | Verify foreign key by **name or column list** | `Builder.php:471` |
| `drop` | `drop($table)` | Drop a table from the database (unguarded: fails loudly if missing) | `Builder.php:535` |
| `dropIfExists` | `dropIfExists($table)` | Drop a table only if it exists | `Builder.php:548` |
| `rename` | `rename($from, $to)` | Rename a table | `Builder.php:612` |
| `defaultStringLength`| `int $length` | Set global default VARCHAR length | `Builder.php:75` |

#### 1.3.2 `Blueprint` column factory methods (Dynamic Dispatch Targets)

**Declaration targets: Column creation**

| YAML key | Blueprint Signature | Database Type / Description | Source |
|---|---|---|---|
| `id` | `id($column = 'id')` | Auto-incrementing UNSIGNED BIGINT primary key | `Blueprint.php:769` |
| `increments` | `increments($column)` | Auto-incrementing UNSIGNED INTEGER primary key | `Blueprint.php:780` |
| `integerIncrements` | `integerIncrements($column)` | Auto-incrementing UNSIGNED INTEGER primary key | `Blueprint.php:791` |
| `tinyIncrements` | `tinyIncrements($column)` | Auto-incrementing UNSIGNED TINYINT primary key | `Blueprint.php:802` |
| `smallIncrements` | `smallIncrements($column)` | Auto-incrementing UNSIGNED SMALLINT primary key | `Blueprint.php:813` |
| `mediumIncrements` | `mediumIncrements($column)` | Auto-incrementing UNSIGNED MEDIUMINT primary key | `Blueprint.php:824` |
| `bigIncrements` | `bigIncrements($column)` | Auto-incrementing UNSIGNED BIGINT primary key | `Blueprint.php:835` |
| `char` | `char($column, $length = null)` | CHAR fixed-length column | `Blueprint.php:847` |
| `string` | `string($column, $length = null)` | VARCHAR column with length | `Blueprint.php:861` |
| `tinyText` | `tinyText($column)` | TINYTEXT column (255 bytes) | `Blueprint.php:874` |
| `text` | `text($column)` | TEXT column (64 KB) | `Blueprint.php:885` |
| `mediumText` | `mediumText($column)` | MEDIUMTEXT column (16 MB) | `Blueprint.php:896` |
| `longText` | `longText($column)` | LONGTEXT column (4 GB) | `Blueprint.php:907` |
| `integer` | `integer($column, $autoIncrement = false, $unsigned = false)` | INTEGER column (4-byte) | `Blueprint.php:921` |
| `tinyInteger` | `tinyInteger($column, $autoIncrement = false, $unsigned = false)` | TINYINT column (1-byte) | `Blueprint.php:935` |
| `smallInteger` | `smallInteger($column, $autoIncrement = false, $unsigned = false)` | SMALLINT column (2-byte) | `Blueprint.php:949` |
| `mediumInteger` | `mediumInteger($column, $autoIncrement = false, $unsigned = false)` | MEDIUMINT column (3-byte) | `Blueprint.php:963` |
| `bigInteger` | `bigInteger($column, $autoIncrement = false, $unsigned = false)` | BIGINT column (8-byte) | `Blueprint.php:977` |
| `unsignedInteger` | `unsignedInteger($column, $autoIncrement = false)` | UNSIGNED INTEGER column | `Blueprint.php:989` |
| `unsignedTinyInteger`| `unsignedTinyInteger($column, $autoIncrement = false)` | UNSIGNED TINYINT column | `Blueprint.php:1001` |
| `unsignedSmallInteger`| `unsignedSmallInteger($column, $autoIncrement = false)` | UNSIGNED SMALLINT column | `Blueprint.php:1013` |
| `unsignedMediumInteger`| `unsignedMediumInteger($column, $autoIncrement = false)`| UNSIGNED MEDIUMINT column | `Blueprint.php:1025` |
| `unsignedBigInteger` | `unsignedBigInteger($column, $autoIncrement = false)` | UNSIGNED BIGINT column | `Blueprint.php:1037` |
| `foreignId` | `foreignId($column)` | UNSIGNED BIGINT for foreign key | `Blueprint.php:1048` |
| `foreignIdFor` | `foreignIdFor($model, $column = null)` | Foreign ID configured from Model FQCN | `Blueprint.php:1065` |
| `foreignUuidFor` | `foreignUuidFor($model, $column = null)` | Foreign UUID configured from Model FQCN | `Blueprint.php:1097` |
| `foreignUlidFor` | `foreignUlidFor($model, $column = null)` | Foreign ULID configured from Model FQCN | `Blueprint.php:1115` |
| `float` | `float($column, $precision = 53)` | FLOAT column | `Blueprint.php:1133` |
| `double` | `double($column)` | DOUBLE column | `Blueprint.php:1144` |
| `decimal` | `decimal($column, $total = 8, $places = 2)` | DECIMAL column with precision | `Blueprint.php:1157` |
| `boolean` | `boolean($column)` | BOOLEAN column | `Blueprint.php:1168` |
| `enum` | `enum($column, array $allowed)` | ENUM column with allowed values | `Blueprint.php:1180` |
| `set` | `set($column, array $allowed)` | SET column with allowed values | `Blueprint.php:1194` |
| `json` | `json($column)` | JSON column | `Blueprint.php:1205` |
| `jsonb` | `jsonb($column)` | JSONB column (PostgreSQL) | `Blueprint.php:1216` |
| `date` | `date($column)` | DATE column | `Blueprint.php:1227` |
| `dateTime` | `dateTime($column, $precision = null)` | DATETIME column | `Blueprint.php:1239` |
| `dateTimeTz` | `dateTimeTz($column, $precision = null)` | DATETIME with timezone column | `Blueprint.php:1253` |
| `time` | `time($column, $precision = null)` | TIME column | `Blueprint.php:1267` |
| `timeTz` | `timeTz($column, $precision = null)` | TIME with timezone column | `Blueprint.php:1281` |
| `timestamp` | `timestamp($column, $precision = null)` | TIMESTAMP column | `Blueprint.php:1295` |
| `timestampTz` | `timestampTz($column, $precision = null)` | TIMESTAMP with timezone column | `Blueprint.php:1309` |
| `timestamps` | `timestamps($precision = null)` | Returns Collection of created_at & updated_at columns | `Blueprint.php:1322` |
| `nullableTimestamps`| `nullableTimestamps($precision = null)` | Returns Collection of nullable created_at & updated_at | `Blueprint.php:1338` |
| `timestampsTz` | `timestampsTz($precision = null)` | Created_at & updated_at with timezone | `Blueprint.php:1349` |
| `nullableTimestampsTz`| `nullableTimestampsTz($precision = null)` | Nullable created_at & updated_at with timezone | `Blueprint.php:1365` |
| `datetimes` | `datetimes($precision = null)` | Returns Collection of created_at & updated_at datetimes | `Blueprint.php:1376` |
| `softDeletes` | `softDeletes($column = 'deleted_at', $precision = null)` | Nullable deleted_at column | `Blueprint.php:1391` |
| `softDeletesTz` | `softDeletesTz($column = 'deleted_at', $precision = null)` | Nullable deleted_at with timezone | `Blueprint.php:1403` |
| `softDeletesDatetime`| `softDeletesDatetime($column = 'deleted_at', $precision = null)` | Nullable deleted_at datetime | `Blueprint.php:1415` |
| `year` | `year($column)` | YEAR column | `Blueprint.php:1426` |
| `binary` | `binary($column, $length = null, $fixed = false)` | BLOB / BYTEA binary column | `Blueprint.php:1439` |
| `uuid` | `uuid($column = 'uuid')` | UUID column | `Blueprint.php:1450` |
| `foreignUuid` | `foreignUuid($column)` | UUID column for foreign key | `Blueprint.php:1461` |
| `ulid` | `ulid($column = 'ulid', $length = 26)` | ULID column | `Blueprint.php:1476` |
| `foreignUlid` | `foreignUlid($column, $length = 26)` | ULID column for foreign key | `Blueprint.php:1488` |
| `ipAddress` | `ipAddress($column = 'ip_address')` | IP address column | `Blueprint.php:1503` |
| `macAddress` | `macAddress($column = 'mac_address')` | MAC address column | `Blueprint.php:1514` |
| `geometry` | `geometry($column, $subtype = null, $srid = 0)` | Spatial geometry column | `Blueprint.php:1527` |
| `geography` | `geography($column, $subtype = null, $srid = 4326)` | Spatial geography column | `Blueprint.php:1540` |
| `vector` | `vector($column, $dimensions = null)` | Vector embedding column | `Blueprint.php:1564` |
| `morphs` | `morphs($name, $indexName = null, $after = null)` | Composite morph `{name}_type` & `{name}_id` (`void`) | `Blueprint.php:1590` |
| `nullableMorphs` | `nullableMorphs($name, $indexName = null, $after = null)` | Nullable morph columns (`void`) | `Blueprint.php:1609` |
| `uuidMorphs` | `uuidMorphs($name, $indexName = null, $after = null)` | UUID morph columns (`void`) | `Blueprint.php:1668` |
| `nullableUuidMorphs`| `nullableUuidMorphs($name, $indexName = null, $after = null)` | Nullable UUID morph columns (`void`) | `Blueprint.php:1687` |
| `ulidMorphs` | `ulidMorphs($name, $indexName = null, $after = null)` | ULID morph columns (`void`) | `Blueprint.php:1708` |
| `nullableUlidMorphs`| `nullableUlidMorphs($name, $indexName = null, $after = null)` | Nullable ULID morph columns (`void`) | `Blueprint.php:1727` |
| `rememberToken` | `rememberToken()` | Nullable VARCHAR(100) remember_token | `Blueprint.php:1745` |

#### 1.3.3 `ColumnDefinition` Fluent Modifiers (Dynamic Dispatch Targets)

**Declaration targets: Column modifiers**

| YAML key | Modifier Signature | Effect | Source |
|---|---|---|---|
| `nullable` | `nullable(bool $value = true)` | Allow NULL values | `ColumnDefinition.php:24` |
| `default` | `default(mixed $value)` | Specify column default value | `ColumnDefinition.php:15` |
| `unique` | `unique(bool\|string\|null $indexName = null)` | Mark column as unique (queued for `Blueprint::addFluentIndexes()`) | `ColumnDefinition.php:32` |
| `index` | `index(bool\|string\|null $indexName = null)` | Mark column as indexed (queued for `Blueprint::addFluentIndexes()`) | `ColumnDefinition.php:21` |
| `primary` | `primary(bool $value = true)` | Mark column as primary key | `ColumnDefinition.php:26` |
| `unsigned` | `unsigned(bool $value = true)` | Mark integer as unsigned | `ColumnDefinition.php:33` |
| `autoIncrement` | `autoIncrement()` | Mark column as auto-incrementing | `ColumnDefinition.php:10` |
| `comment` | `comment(string $comment)` | Add comment metadata to column | `ColumnDefinition.php:14` |
| `after` | `after(string $column)` | Place column after given column | `ColumnDefinition.php:8` |
| `first` | `first()` | Place column first in table | `ColumnDefinition.php:16` |
| `storedAs` | `storedAs(string $expression)` | Create stored generated column | `ColumnDefinition.php:30` |
| `virtualAs` | `virtualAs(string $expression)` | Create virtual generated column | `ColumnDefinition.php:37` |
| `invisible` | `invisible()` | Make column invisible to `SELECT *` | `ColumnDefinition.php:22` |
| `useCurrent` | `useCurrent()` | Set default to `CURRENT_TIMESTAMP` | `ColumnDefinition.php:35` |
| `useCurrentOnUpdate`| `useCurrentOnUpdate()` | Auto-update timestamp on row update | `ColumnDefinition.php:36` |

#### 1.3.4 Foreign Key Constraints (Dynamic Dispatch on `ForeignIdColumnDefinition` / `ForeignKeyDefinition`)

| YAML key | Method Signature | Effect | Source |
|---|---|---|---|
| `constrained` | `constrained($table = null, $column = null, $indexName = null)` | Add foreign key constraint — transitions the chain target to `ForeignKeyDefinition` | `ForeignIdColumnDefinition.php:37` |
| `foreign` | `foreign(string\|array $columns, ?string $name = null)` | Standalone foreign-key factory — modifiers chain onto `ForeignKeyDefinition` directly | `Blueprint.php:752` |
| `cascadeOnUpdate` | `cascadeOnUpdate()` | Trigger `ON UPDATE CASCADE` | `ForeignKeyDefinition.php:20` |
| `restrictOnUpdate`| `restrictOnUpdate()` | Trigger `ON UPDATE RESTRICT` | `ForeignKeyDefinition.php:28` |
| `nullOnUpdate` | `nullOnUpdate()` | Trigger `ON UPDATE SET NULL` | `ForeignKeyDefinition.php:36` |
| `noActionOnUpdate`| `noActionOnUpdate()` | Trigger `ON UPDATE NO ACTION` | `ForeignKeyDefinition.php:44` |
| `cascadeOnDelete` | `cascadeOnDelete()` | Trigger `ON DELETE CASCADE` | `ForeignKeyDefinition.php:52` |
| `restrictOnDelete`| `restrictOnDelete()` | Trigger `ON DELETE RESTRICT` | `ForeignKeyDefinition.php:60` |
| `nullOnDelete` | `nullOnDelete()` | Trigger `ON DELETE SET NULL` | `ForeignKeyDefinition.php:68` |
| `noActionOnDelete`| `noActionOnDelete()` | Trigger `ON DELETE NO ACTION` | `ForeignKeyDefinition.php:76` |
| `deferrable` | `deferrable(bool $value = true)` | Set foreign key as deferrable (PostgreSQL) | `ForeignKeyDefinition.php:8` |

#### 1.3.5 Table-level Indexes, Constraints & Options on `Blueprint`

| YAML key | Blueprint Method Signature | Effect | Source |
|---|---|---|---|
| `primary` | `primary(string\|array $columns, string\|null $name = null, string\|null $algorithm = null)` | Add composite or table primary key | `Blueprint.php:660` |
| `unique` | `unique(string\|array $columns, string\|null $name = null, string\|null $algorithm = null)` | Add table-level unique index | `Blueprint.php:673` |
| `index` | `index(string\|array $columns, string\|null $name = null, string\|null $algorithm = null)` | Add table-level standard index | `Blueprint.php:686` |
| `fullText` | `fullText(string\|array $columns, string\|null $name = null, string\|null $algorithm = null)` | Add full-text search index | `Blueprint.php:699` |
| `spatialIndex` | `spatialIndex(string\|array $columns, string\|null $name = null, string\|null $operatorClass = null)`| Add spatial index | `Blueprint.php:712` |
| `vectorIndex` | `vectorIndex(string $column, string\|null $name = null)` | Add vector index | `Blueprint.php:724` |
| `rawIndex` | `rawIndex(string $expression, string $name)` | Add index on a raw SQL expression | `Blueprint.php:740` |
| `engine` | `engine(string $engine)` | Specify MySQL/MariaDB storage engine | `Blueprint.php:349` |
| `charset` | `charset(string $charset)` | Specify table default character set | `Blueprint.php:370` |
| `collation` | `collation(string $collation)` | Specify table default collation | `Blueprint.php:381` |
| `temporary` | `temporary()` | Create table as temporary | `Blueprint.php:391` |
| `comment` | `comment(string $comment)` | Add table-level comment | `Blueprint.php:1768` |

#### 1.3.6 `Blueprint` alter verbs (Table-Level, `Builder::table()` mode)

| YAML key | Blueprint Method Signature | Effect | Source |
|---|---|---|---|
| `dropColumn` | `dropColumn(string\|array $columns)` | Drop one or more columns | `Blueprint.php:422` |
| `renameColumn` | `renameColumn(string $from, string $to)` | Rename a column | `Blueprint.php:436` |
| `dropPrimary` / `dropUnique` / `dropIndex` / `dropFullText` / `dropSpatialIndex` / `dropVectorIndex` | `drop*(string\|array\|null $index)` | Drop an index by name or columns | `Blueprint.php:447-502` |
| `dropForeign` | `dropForeign(string\|array $foreign)` | Drop a foreign key (columns form required on SQLite) | `Blueprint.php:513` |
| `dropConstrainedForeignId` | `dropConstrainedForeignId($column)` | Drop the FK then the column | `Blueprint.php:524` |
| `renameIndex` | `renameIndex(string $from, string $to)` | Rename an index | `Blueprint.php:570` |
| `dropTimestamps` / `dropTimestampsTz` / `dropSoftDeletes` / `dropSoftDeletesTz` / `dropRememberToken` / `dropMorphs` | conventional droppers | Drop the conventional columns (`created_at`, `deleted_at`, `remember_token`, `{name}_id`) | `Blueprint.php:580-634` |
| `removeColumn` | `removeColumn(string $name)` | Remove a column from the blueprint | `Blueprint.php:1901` |
| `addColumn` | `addColumn(string $type, string $name, array $parameters = [])` | Escape hatch: add a column of any type | `Blueprint.php:1849` |
| `rawColumn` | `rawColumn(string $column, string $definition)` | Escape hatch: raw SQL column definition | `Blueprint.php:1757` |

Column-level and index-level modifiers (`nullable`, `default`, `change`, `nullsNotDistinct`, `algorithm`, `deferrable`, …) chain sequentially onto the target the factory returned — `constrained()`/`references()` transition the chain from `ForeignIdColumnDefinition` to `ForeignKeyDefinition` exactly as Laravel's fluent API does.

### 1.4 How a declaration reaches the database schema engine

```php
// 1. Connection Resolution — one Builder for all operations
$builder = Schema::connection($schema->connection);

// 2. Fixed Execution Order: clear the way, rename targets, create, adjust
foreach ($schema->dropIfExists as $table) {
    $builder->dropIfExists($table);          // Builder.php:548
}
foreach ($schema->drop as $table) {
    $builder->drop($table);                  // Builder.php:535 — unguarded (Rule 7)
}
foreach ($schema->rename as $rename) {
    $builder->rename($rename->from, $rename->to);   // Builder.php:612
}

// 3. Creation — one guarded Builder::create() call per entry (Builder.php:520)
foreach ($schema->create as $tableName => $tableDefinition) {
    if ($builder->hasTable($tableName)) {
        continue;                             // idempotency guard
    }

    // Builder::create() queues $blueprint->create(); the callback body is IDENTICAL
    // to alter mode — every Blueprint method is valid in both modes.
    $builder->create($tableName, fn (Blueprint $blueprint) => $tableDefinition->apply($blueprint));
}

// 4. Alteration — one Builder::table() call per action, each through its derived guard
foreach ($schema->table as $tableName => $tableDefinition) {
    foreach ($tableDefinition->actions as $actions) {
        foreach ($actions as $action) {
            foreach ($action->guards() as $guard) {   // derived from hasColumn/hasIndex/hasForeignKey
                if (! passes($builder, $tableName, $guard)) {
                    continue 2;
                }
            }

            $builder->table($tableName, fn (Blueprint $blueprint) => $action->apply($blueprint));
        }
    }
}

// 5. BlueprintAction::apply() — sequential modifier dispatch in declaration order
//    $target = $blueprint->{$this->method}(...$this->arguments);   // named arguments from native parameter names
//    foreach ($this->modifiers as $modifier => $arguments) {
//        $next = $target->{$modifier}(...$this->spread($arguments));
//        if ($next instanceof Fluent) {
//            $target = $next;   // constrained()/references() transition to ForeignKeyDefinition
//        }
//    }
```

Consequences, each verified against v13.33.0 with Testbench:

1. **One blueprint body, two Builder verbs.** `Builder::create()` and `Builder::table()` differ only in the single `$blueprint->create()` command (`Builder.php:520,508`). Every column factory, modifier, index, and dropper behaves identically; the grammar compiles per-connection (`compileCreate` vs `compileChange`, `compileAdd`, `compileDropColumn`, …) from the blueprint's own `creating()` state.
2. **Named arguments from native parameter names.** A definition map's keys that match `ReflectionParameter` names of the invoked `Blueprint` method are spread as PHP named arguments (`$blueprint->{$method}(...$map)`) — multi-argument factories (`foreign($columns, $name)`, `vectorIndex($column, $name)`, `rawIndex($expression, $name)`, `renameColumn($from, $to)`, `rawColumn($column, $definition)`, `addColumn($type, $name, $parameters)`) expressible natively; everything else is a validated modifier dispatched in declaration order.
3. **Sequential modifier dispatch by returned target.** `constrained()` transitions the chain target from `ForeignIdColumnDefinition` to `ForeignKeyDefinition` exactly as Laravel's fluent chain does, so FK modifiers (`cascadeOnDelete`, `on`, `references`, `deferrable`) land on the `ForeignKeyDefinition` — including for standalone `Blueprint::foreign()`. A modifier that misses the actual chain target fails loudly (`LogicException` — Rule 7).
4. **Grammar handles vendor dialect translation.** SQLite (the test driver) supports the full alter surface through `BlueprintState` (`Blueprint.php:150-151,316`), which `SQLiteGrammar::compileAlter()` emulates as table rebuilds. The manifest remains database-agnostic.

### 1.5 The PHP this replaces

```php
// Standard multi-file migration setup:
// database/migrations/2026_01_01_000001_create_users_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('users');
    }
};

// database/migrations/2026_01_01_000002_create_todos_table.php
return new class extends Migration {
    public function up(): void {
        Schema::create('todos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('completed')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'completed']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('todos');
    }
};
```

Replaced by a single declarative block in `manifest/app.yml`:

```yaml
schema:
  create:
    users:
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

    todos:
      id: ~
      foreignId:
        column: user_id
        constrained: true
        cascadeOnDelete: true
      string: title
      text:
        column: description
        nullable: true
      boolean:
        column: completed
        default: false
      timestamps: ~
      index:
        - [user_id, completed]
```

---

## 2. Manifest schema

### 2.1 Design rule — the block is a map of `Schema\Builder` method names

1. **Key = method name.** `create` → `Builder::create()`, `table` → `Builder::table()`, `rename` → `Builder::rename()`, `drop` → `Builder::drop()`, `dropIfExists` → `Builder::dropIfExists()`; inside a table body, `string` → `Blueprint::string()`, `nullable` → `ColumnDefinition::nullable()`. No invented verbs.
2. **One call per entry.** `create:`/`table:` map table names to bodies; `rename:` is a list of `{from, to}` (the native parameter names); `drop:`/`dropIfExists:` are lists of table names.
3. **Fixed execution order:** **`dropIfExists` → `drop` → `rename` → `create` → `table`** (clear the way, rename targets, create new tables, adjust existing ones). `drop` is intentionally unguarded (Laravel errors loudly on a missing table — Rule 7).
4. **Pass values through directly.** Literal values (`false`, `255`, `'users'`) pass into Laravel's method signatures untouched. Definition-map keys matching `ReflectionParameter` names spread as PHP **named arguments**; every remaining key is a validated modifier dispatched in declaration order.
5. **Dynamic dispatch over monolithic conditionals.** Methods are invoked via variable names (`$target->{$method}(...)`), guaranteeing 100% testable polymorphic delegation without switch blocks.
6. **Fail-fast schema validation.** Unknown keys, non-`Blueprint` methods, and lifecycle verbs (`build`, `toSql`, `create`, `drop`, …) throw `LogicException` during manifest hydration at boot.

**Alter idempotency is derived, not declared.** Every action inside a `table:` body dispatches through a guard derived from the action's own native semantics, mapped onto the Builder's own predicates (`hasColumn`/`hasColumns`/`hasIndex`/`hasForeignKey`):

| Declared action (kind) | Guard (native predicate) |
|---|---|
| column factory (`string`, `integer`, …) | `! hasColumn($table, $column)` — add only when missing |
| column factory with `change: true` | `hasColumn($table, $column)` — change only when present |
| `dropColumn`, `dropConstrainedForeignId`, `removeColumn` | `hasColumns($table, $columns)` |
| `dropForeignIdFor` / `dropConstrainedForeignIdFor` | none (Laravel errors loudly) |
| `dropPrimary`/`dropUnique`/`dropIndex`/`dropFullText`/`dropSpatialIndex`/`dropVectorIndex` | `hasIndex($table, $index)` |
| `dropForeign` | `hasForeignKey($table, $columns)` — columns form required on SQLite |
| `renameColumn` | `hasColumn($from) && ! hasColumn($to)` |
| `renameIndex` | `hasIndex($from) && ! hasIndex($to)` |
| `dropTimestamps`/`dropSoftDeletes`/`dropRememberToken`/`dropMorphs`/… | `hasColumn($canonical)` (`created_at`, `deleted_at`, `remember_token`, `{name}_id`) |
| table-level index factories (`primary`, `unique`, `index`, `fullText`, `spatialIndex`, `vectorIndex`, `rawIndex`) | `! hasIndex($table, $name ?? [$columns])` |
| `foreign` | `! hasForeignKey($table, [$columns])` |
| table options (`engine`, `charset`, `collation`, `temporary`, `comment`) | none — naturally re-runnable |
| unrecognized verb | none — inapplicable verbs fail with Laravel's own error (Rule 7) |

Because every action is individually guarded, a **fresh** database (where `create` built the full desired state) and an **existing** database (where `table` fills the diff) both converge without errors.

### 2.2 Values

Column definitions in YAML support three expressive shapes:

1. **Scalar shorthand (default arguments):**
   ```yaml
   id: ~                       # -> $table->id('id')
   string: title               # -> $table->string('title')
   timestamps: ~               # -> $table->timestamps()
   ```

2. **Map with named arguments and modifiers** — keys matching the `Blueprint` method's native parameter names become named arguments; the rest chain sequentially:
   ```yaml
   boolean:
     column: completed
     default: false            # -> $table->boolean(column: 'completed')->default(false)

   foreignId:
     column: user_id
     constrained: users        # -> ->constrained('users') — target transitions to ForeignKeyDefinition
     cascadeOnDelete: true     # -> ->cascadeOnDelete()

   renameColumn:
     from: user_id             # native parameter names of Blueprint::renameColumn($from, $to)
     to: owner_id

   index:
     - columns: [owner_id, priority]
       nullsNotDistinct: true  # IndexDefinition modifier — previously undeclarable
   ```

3. **List of definitions for duplicate types** — one call per entry (Rule 2). A flat list (`index: [user_id, completed]`) is two single-column indexes; a composite index keeps the nested form (`index: [- [user_id, completed]]`) or the `columns:` map form.

### 2.3 Full example

```yaml
schema:
  connection: ~                        # null uses default connection; or specify 'sqlite', 'pgsql'

  dropIfExists:                        # Builder::dropIfExists — one call per entry
    - scratch_table

  drop:                                # Builder::drop — unguarded: loud if missing
    - legacy_table

  rename:                              # Builder::rename — native parameter names from/to
    - from: users
      to: people

  create:                              # Builder::create — guarded by hasTable(); body = Blueprint methods
    people:
      id: ~
      string:
        - name
        - column: email
          unique: true
      timestamp:
        column: email_verified_at
        nullable: true
      rememberToken: ~
      timestamps: ~

    audits:
      id: ~
      string:
        column: user_id
      foreign:                         # standalone Blueprint::foreign() — FK modifiers dispatch onto ForeignKeyDefinition
        - columns: user_id
          on: people
          references: id
          cascadeOnDelete: true

  table:                               # Builder::table — alter mode; every action individually guarded
    people:
      string:
        - column: nickname
          length: 64
        - column: title
          length: 200
          change: true                 # alter an existing column
      dropColumn:
        - columns: legacy_flag
      renameColumn:
        from: user_id
        to: owner_id
      index:
        - columns: [owner_id, priority]
          nullsNotDistinct: true
      rawIndex:
        - expression: "lower(title)"
          name: people_title_lower_idx
      dropTimestamps: ~
```

### 2.4 Key → member → signature map

| YAML key | Attribute | Target Class | Signature | Return Type | Default |
|---|---|---|---|---|---|
| `connection` | `#[Key]` | `Schema` | `string\|null` | — | `null` (default) |
| `create` | `#[Key]` | `Schema\Builder` | `create($table, Closure $callback)` | `void` | empty Collection |
| `table` | `#[Key]` | `Schema\Builder` | `table($table, Closure $callback)` | `void` | empty Collection |
| `rename` | `#[Key]` | `Schema\Builder` | `rename($from, $to)` | `void` | `[]` |
| `drop` | `#[Key]` | `Schema\Builder` | `drop($table)` | `void` | `[]` |
| `dropIfExists` | `#[Key]` | `Schema\Builder` | `dropIfExists($table)` | `void` | `[]` |
| `id` | `#[ColumnType]` | `Blueprint` | `id(string $column = 'id')` | `ColumnDefinition` | `'id'` |
| `string` | `#[ColumnType]` | `Blueprint` | `string(string $column, ?int $length = null)` | `ColumnDefinition` | omitted |
| `text` | `#[ColumnType]` | `Blueprint` | `text(string $column)` | `ColumnDefinition` | omitted |
| `boolean` | `#[ColumnType]` | `Blueprint` | `boolean(string $column)` | `ColumnDefinition` | omitted |
| `integer` | `#[ColumnType]` | `Blueprint` | `integer(string $column, bool $auto = false, bool $unsigned = false)` | `ColumnDefinition` | omitted |
| `foreignId` | `#[ColumnType]` | `Blueprint` | `foreignId(string $column)` | `ForeignIdColumnDefinition` | omitted |
| `timestamps` | `#[ColumnType]` | `Blueprint` | `timestamps(?int $precision = null)` | `Collection<int, ColumnDefinition>` | `null` |
| `softDeletes` | `#[ColumnType]` | `Blueprint` | `softDeletes(string $column = 'deleted_at', ?int $precision = null)` | `ColumnDefinition` | omitted |
| `nullable` | `#[ColumnModifier]`| `ColumnDefinition` | `nullable(bool $value = true)` | `$this` | omitted |
| `default` | `#[ColumnModifier]`| `ColumnDefinition` | `default(mixed $value)` | `$this` | omitted |
| `unique` | `#[ColumnModifier]`| `ColumnDefinition` | `unique(bool\|string\|null $name = null)` | `$this` | omitted |
| `constrained` | `#[ColumnModifier]`| `ForeignIdColumnDefinition` | `constrained(?string $table = null, ?string $column = null, ?string $indexName = null)` | `ForeignKeyDefinition` | omitted |
| `cascadeOnDelete`| `#[ForeignKeyModifier]`| `ForeignKeyDefinition` | `cascadeOnDelete()` | `$this` | omitted |
| `cascadeOnUpdate`| `#[ForeignKeyModifier]`| `ForeignKeyDefinition` | `cascadeOnUpdate()` | `$this` | omitted |
| `nullOnDelete` | `#[ForeignKeyModifier]`| `ForeignKeyDefinition` | `nullOnDelete()` | `$this` | omitted |
| `restrictOnDelete`| `#[ForeignKeyModifier]`| `ForeignKeyDefinition` | `restrictOnDelete()` | `$this` | omitted |
| `index` | `#[TableConstraint]`| `Blueprint` | `index(string\|array $columns, ?string $name = null, ?string $algorithm = null)` | `IndexDefinition` | omitted |
| `primary` | `#[TableConstraint]`| `Blueprint` | `primary(string\|array $columns, ?string $name = null, ?string $algorithm = null)` | `IndexDefinition` | omitted |
| `engine` | `#[TableOption]` | `Blueprint` | `engine(string $engine)` | `void` | omitted |
| `charset` | `#[TableOption]` | `Blueprint` | `charset(string $charset)` | `void` | omitted |
| `collation` | `#[TableOption]` | `Blueprint` | `collation(string $collation)` | `void` | omitted |
| `temporary` | `#[TableOption]` | `Blueprint` | `temporary()` | `void` | omitted |

### 2.5 Dynamic dispatch registration and execution algorithm

The dynamic dispatch architecture mirrors `Query` and `Kernel` by using attribute-tagged dispatchers:

1. **`#[ColumnType]` Attribute**: Decorates Blueprint column creation methods. Dispatches `$blueprint->{$method}(...$arguments)` returning the `ColumnDefinition`.
2. **`#[ColumnModifier]` Attribute**: Decorates fluent column modifiers. Dispatches `$columnDefinition->{$modifier}(...$modifierArgs)` on the returned column.
3. **`#[ForeignKeyModifier]` Attribute**: Decorates foreign key action methods on `ForeignKeyDefinition`. Dispatches `$foreignKey->{$modifier}(...$args)`.
4. **`#[TableConstraint]` Attribute**: Decorates table-level index methods (`index`, `unique`, `primary`, `fullText`, `spatialIndex`, `vectorIndex`) on `Blueprint`. Dispatches `$blueprint->{$method}($columns, $name)`.
5. **`#[TableOption]` Attribute**: Decorates table configuration methods (`engine`, `charset`, `collation`, `comment`, `temporary`) on `Blueprint`. Dispatches `$blueprint->{$method}($value)`.

```php
namespace ZeroToProd\LaravelDeclaration\Attributes;

use Attribute;
use Illuminate\Database\Schema\Blueprint;

#[Attribute(Attribute::TARGET_PROPERTY)]
class ColumnType
{
    /** @param list<mixed> $args */
    public function apply(Blueprint $blueprint, string $method, array $args): mixed
    {
        return $blueprint->{$method}(...$args);
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class ColumnModifier
{
    public function apply(object $target, string $modifier, mixed $args): void
    {
        if ($args === true || $args === null) {
            $target->{$modifier}();
        } elseif (is_array($args) && array_is_list($args)) {
            $target->{$modifier}(...$args);
        } else {
            $target->{$modifier}($args);
        }
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class ForeignKeyModifier
{
    public function apply(object $target, string $modifier, mixed $args): void
    {
        if ($args === true || $args === null) {
            $target->{$modifier}();
        } elseif (is_array($args) && array_is_list($args)) {
            $target->{$modifier}(...$args);
        } else {
            $target->{$modifier}($args);
        }
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class TableConstraint
{
    public function apply(Blueprint $blueprint, string $method, mixed $args): mixed
    {
        if (is_array($args) && isset($args['columns'])) {
            return $blueprint->{$method}($args['columns'], $args['name'] ?? null);
        }

        return $blueprint->{$method}($args);
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class TableOption
{
    public function apply(Blueprint $blueprint, string $method, mixed $value): void
    {
        if ($value === true || $value === null) {
            $blueprint->{$method}();
        } else {
            $blueprint->{$method}($value);
        }
    }
}
```

```php
namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Schema;

class SchemaDeclarationServiceProvider extends ServiceProvider
{
    public function boot(Manifest $manifest): void
    {
        if (! $manifest->schema instanceof Schema) {
            return;
        }

        $schema = $manifest->schema;

        $this->callAfterResolving('db', function () use ($schema): void {
            $this->applySchema($schema);
        });
    }

    public function applySchema(Schema $schema): void
    {
        $schemaBuilder = SchemaFacade::connection($schema->connection);

        foreach ($schema->create as $tableName => $tableDefinition) {
            if ($schemaBuilder->hasTable($tableName)) {
                continue;
            }

            $schemaBuilder->create($tableName, function (Blueprint $table) use ($tableDefinition): void {
                $tableDefinition->apply($table);
            });
        }
    }
}
```

### 2.6 Notes / non-goals

- **Non-destructive by default.** Schema creation runs via `hasTable()` guard, ensuring existing tables and data in persistent production environments are never wiped or overwritten.
- **No raw arbitrary SQL injection.** Manifest column and table definitions resolve strictly to native `Illuminate\Database\Schema\Blueprint` methods and typed grammar compilers.
- **Migration rollbacks.** The declarative manifest represents desired target state, not historical sequential migration versions. Reversing schema state belongs in explicit migration files or database refreshes (`migrate:fresh`).

---

## 3. Implementation plan

### 3.1 `src/Schema.php` DataModel
- `Schema` class extending `DataModel` — five native `Builder` verb keys plus `connection`:
  - `public const string connection/create/table/rename/drop/dropIfExists = 'connection'/'create'/'table'/'rename'/'drop'/'dropIfExists';`
  - `#[Key, Describe([Describe::nullable => true])] public ?string $connection;`
  - `/** @var Collection<string, TableDefinition> */ #[Key, Describe([Describe::cast => [self::class, 'mapOf'], 'type' => TableDefinition::class])] public Collection $create;`
  - `/** @var Collection<string, TableDefinition> */ #[Key, Describe([Describe::cast => [self::class, 'mapOf'], 'type' => TableDefinition::class])] public Collection $table;`
  - `/** @var list<TableRename> */ #[Key, Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => TableRename::class])] public array $rename;`
  - `/** @var list<string> */ #[Key, Describe([Describe::default => []])] public array $drop;`
  - `/** @var list<string> */ #[Key, Describe([Describe::default => []])] public array $dropIfExists;`

### 3.2 `src/TableDefinition.php` & `src/BlueprintAction.php`
- `TableDefinition` — one declaration-ordered actions bag: `@param array<string, list<BlueprintAction>> $actions`; `apply(Blueprint $table): void` dispatches every action; `from()` hydrates any table-body key through `BlueprintAction::fromDefinition()`.
- `BlueprintAction` — one declared `Blueprint` method invocation (named or positional arguments plus the declared modifier chain, dispatched sequentially in declaration order); derives per-action `ActionGuard`s from the native `Builder` predicates; `ColumnDefinitionModel` is deleted — the chain target is the object Laravel actually returns, so the `ForeignKeyDefinition` modifier-discard defect cannot recur.
- `src/Internal/BlueprintMethodKind.php` — classifies every `Blueprint` method by its docblock `@return` tag (Column / Index / ForeignKey / Command / Unit / Collection / Unknown) and reads native parameter names by reflection.
- `src/Internal/ActionGuard.php` — one derived idempotency predicate (`GuardKind` + string/list target) resolved onto the Builder's own predicates by the executor.

### 3.3 Dynamic Dispatch Attributes in `src/Attributes/`
- `ColumnType.php`: polymorphic Blueprint method dispatch.
- `ColumnModifier.php`: polymorphic ColumnDefinition modifier dispatch.
- `ForeignKeyModifier.php`: polymorphic ForeignKeyDefinition action dispatch.
- `TableConstraint.php`: polymorphic table-level index dispatch.
- `TableOption.php`: table option dispatch.

### 3.4 `src/Manifest.php` Integration
- Add `public const string schema = 'schema';`
- Add property `public ?Schema $schema;` with `#[Describe([Describe::nullable => true])]`.

### 3.5 Schema Migration Command
- `php artisan declaration:migrate` command in `src/Internal/Commands/MigrateCommand.php` (aliased as `laravel-declaration:migrate`).
- Executes the five operations in fixed order — `dropIfExists` → `drop` → `rename` → `create` → `table`; `create` is `hasTable()`-guarded, `table` entries alter an existing table one guarded action at a time (`Builder::table()` per action), and re-runs converge without errors.
- Schema operations are executed exclusively via CLI command; boot-time auto-migration is completely removed.
- Uses the `illuminate/database` classes (`Schema\Builder`, `Schema\Blueprint`) already whitelisted in `composer-require-checker.json`.

### 3.6 `manifest.schema.json`
- `schema` definition in JSON Schema matching `Schema` (`create`/`table` maps, `rename` object list, `drop`/`dropIfExists` string lists) and the permissive `tableDefinition` body (hydration is the fail-fast authority).

### 3.7 Fixtures & Tests
- Fixture: `tests/Fixtures/manifest/schema.yml` containing `users`, `todos`, `tags`, and the standalone-`foreign()` `audits` tables under `create:`.
- Fixture: `tests/Fixtures/manifest/schema-alter.yml` exercising `dropIfExists`/`drop`/`rename`/`create`/`table` including guarded column drops/renames, index renames, composite indexes with `nullsNotDistinct`, and a cascade foreign key.
- Feature Test: `tests/Feature/SchemaRegistrationTest.php`:
  - Verifies the fixed execution order, idempotent convergence of alter actions, and the standalone `foreign()` fix.
  - Verifies column types, nullability, defaults, unique indexes, and foreign keys using `Schema::getColumnListing()`, `Schema::hasColumns()`, and `Schema::getForeignKeys()`.
  - Verifies dynamic dispatch execution, guard derivation, and docblock-return classification without procedural branching.

---

### Sources

- `vendor/laravel/framework/src/Illuminate/Database/Schema/Builder.php`
- `vendor/laravel/framework/src/Illuminate/Database/Schema/Blueprint.php`
- `vendor/laravel/framework/src/Illuminate/Database/Schema/ColumnDefinition.php`
- `vendor/laravel/framework/src/Illuminate/Database/Schema/ForeignIdColumnDefinition.php`
- `vendor/laravel/framework/src/Illuminate/Database/Schema/ForeignKeyDefinition.php`
- `vendor/laravel/framework/src/Illuminate/Database/DatabaseServiceProvider.php`
- `docs/repos/laravel/docs/migrations.md`
- `docs/declarative-request-to-view-roadmap.md` §3 Phase 6
