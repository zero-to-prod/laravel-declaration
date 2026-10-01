<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\ForeignKeyDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\ColumnModifier;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\ColumnType;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\ForeignKeyModifier;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\TableConstraint;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\TableOption;
use ZeroToProd\LaravelDeclaration\BlueprintAction;
use ZeroToProd\LaravelDeclaration\Internal\BlueprintMethodKind;
use ZeroToProd\LaravelDeclaration\Internal\GuardKind;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Schema;
use ZeroToProd\LaravelDeclaration\TableDefinition;
use ZeroToProd\LaravelDeclaration\TableRename;

function blueprint(): Blueprint
{
    return createTestBlueprint('people');
}

test('it creates declared tables, columns, indexes, and constraints via declaration:migrate', function (): void {
    SchemaFacade::dropIfExists('users');
    SchemaFacade::dropIfExists('todos');
    SchemaFacade::dropIfExists('tags');
    SchemaFacade::dropIfExists('audits');

    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/schema.yml',
    ]);

    expect(SchemaFacade::hasTable('users'))->toBeFalse()
        ->and(SchemaFacade::hasTable('todos'))->toBeFalse()
        ->and(SchemaFacade::hasTable('tags'))->toBeFalse()
        ->and(SchemaFacade::hasTable('audits'))->toBeFalse();

    $this->artisan('declaration:migrate')
        ->expectsOutputToContain('Created')
        ->expectsOutputToContain('Schema migration complete. [4] table(s) created.')
        ->assertSuccessful();

    expect(SchemaFacade::hasTable('users'))->toBeTrue()
        ->and(SchemaFacade::hasTable('todos'))->toBeTrue()
        ->and(SchemaFacade::hasTable('tags'))->toBeTrue()
        ->and(SchemaFacade::hasTable('audits'))->toBeTrue()
        ->and(
            SchemaFacade::hasColumns('users', [
                'id',
                'name',
                'email',
                'email_verified_at',
                'password',
                'remember_token',
                'created_at',
                'updated_at',
            ])
        )->toBeTrue()
        ->and(
            SchemaFacade::hasColumns('todos', [
                'id',
                'user_id',
                'title',
                'description',
                'completed',
                'created_at',
                'updated_at',
            ])
        )->toBeTrue()
        ->and(
            SchemaFacade::hasColumns('tags', [
                'id',
                'name',
                'created_at',
                'updated_at',
            ])
        )->toBeTrue();

    // Verify unique index on users.email
    $userIndexes = SchemaFacade::getIndexes('users');
    $emailIndex = collect($userIndexes)->first(fn (array $idx): bool => $idx['columns'] === ['email']);
    expect($emailIndex)->not->toBeNull()
        ->and($emailIndex['unique'])->toBeTrue();

    // Verify composite index on todos(user_id, completed)
    $todoIndexes = SchemaFacade::getIndexes('todos');
    $compositeIndex = collect($todoIndexes)->first(fn (array $idx): bool => $idx['columns'] === ['user_id', 'completed']);
    expect($compositeIndex)->not->toBeNull();

    // Verify foreign key on todos.user_id
    $foreignKeys = SchemaFacade::getForeignKeys('todos');
    $userFk = collect($foreignKeys)->first(fn (array $fk): bool => $fk['columns'] === ['user_id']);
    expect($userFk)->not->toBeNull()
        ->and($userFk['foreign_table'])->toBe('users')
        ->and($userFk['foreign_columns'])->toBe(['id'])
        ->and($userFk['on_delete'])->toBe('cascade');
});

test('it applies schema idempotently without destroying existing data', function (): void {
    SchemaFacade::dropIfExists('users');
    SchemaFacade::dropIfExists('todos');
    SchemaFacade::dropIfExists('tags');
    SchemaFacade::dropIfExists('audits');

    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/schema.yml',
    ]);

    $this->artisan('declaration:migrate')->assertSuccessful();

    DB::table('users')->insert([
        'id' => 1,
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'secret',
    ]);

    expect(DB::table('users')->count())->toBe(1);

    $this->artisan('declaration:migrate')
        ->expectsOutputToContain('Already exists')
        ->expectsOutputToContain('Schema migration complete. [0] table(s) created.')
        ->assertSuccessful();

    expect(DB::table('users')->count())->toBe(1)
        ->and(DB::table('users')->where('id', 1)->value('name'))->toBe('Jane Doe');
});

test('declaration:migrate works via laravel-declaration:migrate alias', function (): void {
    SchemaFacade::dropIfExists('users');
    SchemaFacade::dropIfExists('todos');
    SchemaFacade::dropIfExists('tags');
    SchemaFacade::dropIfExists('audits');

    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/schema.yml',
    ]);

    $this->artisan('laravel-declaration:migrate')
        ->expectsOutputToContain('Created')
        ->expectsOutputToContain('Schema migration complete. [4] table(s) created.')
        ->assertSuccessful();

    $this->artisan('laravel-declaration:migrate')
        ->expectsOutputToContain('Already exists')
        ->expectsOutputToContain('Schema migration complete. [0] table(s) created.')
        ->assertSuccessful();
});

test('declaration:migrate outputs info message when no schema block is present', function (): void {
    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/app.yml',
    ]);

    $this->artisan('declaration:migrate')
        ->expectsOutputToContain('No declarative schema defined in manifest.')
        ->assertSuccessful();
});

test('it throws LogicException during manifest hydration when table key is unknown', function (): void {
    Manifest::from([
        'schema' => [
            'create' => [
                'users' => [
                    'nonExistentBlueprintMethod' => true,
                ],
            ],
        ],
    ]);
})->throws(LogicException::class, 'Unknown Blueprint method [nonExistentBlueprintMethod].');

test('it throws LogicException during manifest hydration when the Blueprint method is unknown', function (): void {
    expect(fn (): BlueprintAction => BlueprintAction::fromDefinition('invalidBlueprintCol', null))
        ->toThrow(LogicException::class, 'Unknown Blueprint method [invalidBlueprintCol].');
});

test('it executes drop, dropIfExists, rename, create, and table in fixed order', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/schema-alter.yml']);

    SchemaFacade::dropIfExists('users');
    SchemaFacade::dropIfExists('people');
    SchemaFacade::create('legacy', fn (Blueprint $table) => $table->id());
    SchemaFacade::create('old_users', fn (Blueprint $table) => $table->id());

    $this->artisan('declaration:migrate')
        ->expectsOutputToContain('Dropped if exists')
        ->expectsOutputToContain('Dropped')
        ->expectsOutputToContain('Renamed to users')
        ->expectsOutputToContain('Created')
        ->assertSuccessful();

    expect(SchemaFacade::hasTable('scratch'))->toBeFalse()
        ->and(SchemaFacade::hasTable('legacy'))->toBeFalse()
        ->and(SchemaFacade::hasTable('old_users'))->toBeFalse()
        ->and(SchemaFacade::hasTable('users'))->toBeTrue()
        ->and(SchemaFacade::hasTable('people'))->toBeTrue();
});

test('it alters an existing table idempotently through derived native guards', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/schema-alter.yml']);

    SchemaFacade::create('legacy', fn (Blueprint $table) => $table->id());
    SchemaFacade::create('old_users', fn (Blueprint $table) => $table->id());
    SchemaFacade::create('people', function (Blueprint $table): void {
        $table->id();
        $table->string('title', 100);
        $table->string('user_id');
        $table->string('obsolete');
        $table->index('title', 'people_title_index');
        $table->timestamps();
    });

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
    SchemaFacade::create('legacy', fn (Blueprint $table) => $table->id());

    $this->artisan('declaration:migrate')
        ->expectsOutputToContain('Rename skipped')
        ->assertSuccessful();

    expect(SchemaFacade::getColumnListing('people'))->toBe($before);
});

test('it dispatches the standalone foreign method onto ForeignKeyDefinition modifiers', function (): void {
    SchemaFacade::dropIfExists('users');
    SchemaFacade::dropIfExists('audits');

    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/schema.yml']);

    $this->artisan('declaration:migrate')->assertSuccessful();

    $foreignKey = collect(SchemaFacade::getForeignKeys('audits'))->first(
        fn (array $fk): bool => $fk['columns'] === ['user_id']
    );

    expect($foreignKey)->not->toBeNull()
        ->and($foreignKey['foreign_table'])->toBe('users')
        ->and($foreignKey['foreign_columns'])->toBe(['id'])
        ->and($foreignKey['on_delete'])->toBe('cascade');
});

test('it dispatches guarded actions only when the derived native guard passes', function (): void {
    SchemaFacade::dropIfExists('widget');

    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/schema-none.yml']);

    $this->artisan('declaration:migrate')
        ->expectsOutputToContain('Altered [1] action(s)')
        ->assertSuccessful();

    expect(SchemaFacade::hasColumn('widget', 'uuid'))->toBeTrue();
});

test('it throws when foreign key modifiers target a non-foreign-key column', function (): void {
    // Hydration accepts FK modifiers for column factories — the chain may transition to
    // ForeignKeyDefinition via constrained()/references().
    $action = BlueprintAction::fromDefinition('string', ['column' => 'x', 'cascadeOnDelete' => true]);

    // Execution: the chain target is a plain ColumnDefinition — loud failure (Rule 7).
    expect(fn (): mixed => $action->apply(blueprint()))->toThrow(LogicException::class);

    // Execution: FK modifier declared before `constrained` on a ForeignIdColumnDefinition.
    $action = BlueprintAction::fromDefinition('foreignId', ['column' => 'user_id', 'cascadeOnDelete' => true, 'constrained' => true]);

    expect(fn (): mixed => $action->apply(blueprint()))->toThrow(LogicException::class);
});

test('it throws on unknown modifier keys and lifecycle verbs', function (): void {
    expect(fn (): BlueprintAction => BlueprintAction::fromDefinition('string', ['column' => 'x', 'nulable' => true]))
        ->toThrow(LogicException::class)
        ->and(fn (): TableDefinition => TableDefinition::from(['create' => []]))
        ->toThrow(LogicException::class)
        ->and(fn (): TableDefinition => TableDefinition::from(['drop' => []]))
        ->toThrow(LogicException::class)
        ->and(fn (): TableDefinition => TableDefinition::from(['build' => null]))
        ->toThrow(LogicException::class)
        ->and(fn (): TableDefinition => TableDefinition::from(['addFluentCommands' => null]))
        ->toThrow(LogicException::class)
        ->and(fn (): TableDefinition => TableDefinition::from(['getColumns' => null]))
        ->toThrow(LogicException::class)
        ->and(fn (): BlueprintAction => BlueprintAction::fromDefinition('dropColumn', ['columns' => 'x', 'bogus' => true]))
        ->toThrow(LogicException::class, 'does not accept modifiers')
        ->and(fn (): BlueprintAction => BlueprintAction::fromDefinition('dropColumn', ['columns' => 'x', 'change' => true]))
        ->toThrow(LogicException::class, 'does not accept modifiers');
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
        ->and($commands->firstWhere('index', 'people_title_lower_idx'))->not->toBeNull()
        ->and(collect($table->getColumns())->first(fn ($column): bool => $column['type'] === 'raw')['name'])->toBe('legacy_state')
        ->and(collect($table->getColumns())->first(fn ($column): bool => $column['type'] === 'integer')['name'])->toBe('count');
});

test('it derives native guards per alter action', function (): void {
    $add = BlueprintAction::fromDefinition('integer', ['column' => 'priority', 'default' => 0]);
    $change = BlueprintAction::fromDefinition('string', ['column' => 'title', 'length' => 200, 'change' => true]);
    $drop = BlueprintAction::fromDefinition('dropColumn', ['columns' => ['obsolete']]);
    $rename = BlueprintAction::fromDefinition('renameColumn', ['from' => 'user_id', 'to' => 'owner_id']);
    $morphs = BlueprintAction::fromDefinition('morphs', ['name' => 'taggable']);
    $timestamps = BlueprintAction::fromDefinition('timestamps', null);
    $fk = BlueprintAction::fromDefinition('foreign', ['columns' => 'owner_id', 'references' => 'people', 'on' => 'id']);
    $index = BlueprintAction::fromDefinition('index', ['columns' => 'title']);
    $namedIndex = BlueprintAction::fromDefinition('index', ['columns' => ['owner_id'], 'name' => 'my_idx']);
    $addColumn = BlueprintAction::fromDefinition('addColumn', ['type' => 'geometry', 'name' => 'location', 'parameters' => ['srid' => 4326]]);
    $foreignIdFor = BlueprintAction::fromDefinition('foreignIdFor', ['model' => 'SomeModel']);
    $dropForeignIdFor = BlueprintAction::fromDefinition('dropForeignIdFor', 'SomeModel');
    $dropForeign = BlueprintAction::fromDefinition('dropForeign', 'owner_id');
    $foreignNull = BlueprintAction::fromDefinition('foreign', null);
    $temporary = BlueprintAction::fromDefinition('temporary', true);

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
        ->and($timestamps->guards()[0]->target)->toBe('created_at')
        ->and($fk->guards()[0]->kind)->toBe(GuardKind::ForeignKeyMissing)
        ->and($fk->guards()[0]->target)->toBe(['owner_id'])
        ->and($index->guards()[0]->kind)->toBe(GuardKind::IndexMissing)
        ->and($index->guards()[0]->target)->toBe(['title'])
        ->and($namedIndex->guards()[0]->target)->toBe('my_idx')
        ->and($addColumn->guards()[0]->kind)->toBe(GuardKind::ColumnMissing)
        ->and($addColumn->guards()[0]->target)->toBe('location')
        ->and($foreignIdFor->guards()[0]->kind)->toBe(GuardKind::None)
        ->and($dropForeignIdFor->guards())->toBeEmpty()
        ->and($dropForeign->guards()[0]->kind)->toBe(GuardKind::ForeignKeyExists)
        ->and($dropForeign->guards()[0]->target)->toBe(['owner_id'])
        ->and($foreignNull->guards()[0]->target)->toBe([])
        ->and($temporary->guards())->toBeEmpty();
});

test('it derives no guards for unknown Blueprint methods', function (): void {
    expect(BlueprintMethodKind::of('undeclared')->guards(new BlueprintAction('undeclared')))->toBeEmpty();
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
        ->and(BlueprintMethodKind::of('toSql'))->toBe(BlueprintMethodKind::Unknown)
        ->and(BlueprintMethodKind::of('getColumns'))->toBe(BlueprintMethodKind::Unknown)
        ->and(BlueprintMethodKind::of('addColumnDefinition'))->toBe(BlueprintMethodKind::Unknown)
        ->and(BlueprintMethodKind::of('addCommand'))->toBe(BlueprintMethodKind::Unknown);
});

test('BlueprintAction shapes definitions into named and positional arguments', function (): void {
    $scalar = BlueprintAction::fromDefinition('morphs', 'taggable');
    $list = BlueprintAction::fromDefinition('string', ['username', 100]);
    $boolean = BlueprintAction::fromDefinition('timestamps', true);
    $map = BlueprintAction::fromDefinition('foreignId', ['column' => 'user_id', 'constrained' => ['users', 'acc_id']]);

    expect($scalar->arguments)->toBe(['taggable'])
        ->and($list->arguments)->toBe(['username', 100])
        ->and($boolean->arguments)->toBeEmpty()
        ->and($map->arguments)->toBe(['column' => 'user_id'])
        ->and($map->modifiers)->toBe(['constrained' => ['users', 'acc_id']]);

    $column = $map->apply(blueprint());

    expect($column)->toBeInstanceOf(ForeignKeyDefinition::class);
});

test('TableDefinition handles instance context, declaration order, and non-array context', function (): void {
    // Instance context
    $orig = new TableDefinition;
    expect(TableDefinition::from($orig))->toBe($orig);

    // Non-array context
    $empty = TableDefinition::from(null);
    expect($empty->actions)->toBeEmpty();

    // Declaration-order dispatch across method kinds
    $tableDef = TableDefinition::from([
        'morphs' => 'taggable',
        'timestamps' => null,
        'comment' => 'table comment',
        'index' => [['columns' => ['taggable_id', 'taggable_type'], 'name' => 'composite_idx']],
    ]);

    $blueprint = createTestBlueprint('items');
    $tableDef->apply($blueprint);

    expect(collect($blueprint->getColumns())->pluck('name')->toArray())
        ->toContain('taggable_type', 'taggable_id', 'created_at', 'updated_at')
        ->and(collect($blueprint->getCommands())->first(
            fn ($command): bool => $command['index'] === 'composite_idx'
        )['columns'])->toBe(['taggable_id', 'taggable_type']);
});

test('Schema DataModel hydrates the five Builder verbs', function (): void {
    $schema = Schema::from([]);

    expect($schema->connection)->toBeNull()
        ->and($schema->create->isEmpty())->toBeTrue()
        ->and($schema->table->isEmpty())->toBeTrue()
        ->and($schema->rename)->toBeEmpty()
        ->and($schema->drop)->toBeEmpty()
        ->and($schema->dropIfExists)->toBeEmpty();

    // listOf with a non-array value yields an empty list
    expect(Schema::from(['rename' => 'not-a-list'])->rename)->toBeEmpty()
        ->and(Schema::from(['rename' => [['from' => 'a', 'to' => 'b']]])->rename[0])->toBeInstanceOf(TableRename::class);
});

function createTestBlueprint(string $table = 'test_table'): Blueprint
{
    $conn = DB::connection();
    $conn->useDefaultSchemaGrammar();

    return new Blueprint($conn, $table);
}

test('dynamic dispatch ColumnType attribute applies Blueprint methods', function (): void {
    $columnType = new ColumnType;
    $blueprint = createTestBlueprint();

    $result = $columnType->apply($blueprint, 'string', ['title', 100]);

    expect($result)->toBeInstanceOf(ColumnDefinition::class);
});

test('dynamic dispatch ColumnModifier attribute applies modifiers with various argument types', function (): void {
    $modifier = new ColumnModifier;
    $blueprint = createTestBlueprint();
    $column = $blueprint->string('test_col');

    // boolean true
    $modifier->apply($column, 'nullable', true);
    expect($column->get('nullable'))->toBeTrue();

    // null
    $modifier->apply($column, 'unsigned', null);
    expect($column->get('unsigned'))->toBeTrue();

    // scalar
    $modifier->apply($column, 'default', 'active');
    expect($column->get('default'))->toBe('active');

    // list array
    $modifier->apply($column, 'comment', ['a comment']);
    expect($column->get('comment'))->toBe('a comment');
});

test('dynamic dispatch ForeignKeyModifier attribute applies foreign key actions', function (): void {
    $fkModifier = new ForeignKeyModifier;
    $blueprint = createTestBlueprint();
    $column = $blueprint->foreignId('user_id');
    $foreignKey = $column->constrained('users');

    // boolean true
    $fkModifier->apply($foreignKey, 'cascadeOnDelete', true);
    expect($foreignKey->get('onDelete'))->toBe('cascade');

    // null
    $fkModifier->apply($foreignKey, 'cascadeOnUpdate', null);
    expect($foreignKey->get('onUpdate'))->toBe('cascade');

    // scalar
    $fkModifier->apply($foreignKey, 'onDelete', 'set null');
    expect($foreignKey->get('onDelete'))->toBe('set null');

    // list array
    $fkModifier->apply($foreignKey, 'onUpdate', ['restrict']);
    expect($foreignKey->get('onUpdate'))->toBe('restrict');
});

test('dynamic dispatch TableConstraint attribute applies constraints with columns map and scalar/array', function (): void {
    $constraint = new TableConstraint;
    $blueprint = createTestBlueprint();

    // array with columns and name
    $cmd1 = $constraint->apply($blueprint, 'index', ['columns' => ['col1', 'col2'], 'name' => 'custom_idx']);
    expect($cmd1->get('index'))->toBe('custom_idx')
        ->and($cmd1->get('columns'))->toBe(['col1', 'col2']);

    // direct column list
    $cmd2 = $constraint->apply($blueprint, 'index', ['col3']);
    expect($cmd2->get('columns'))->toBe(['col3']);
});

test('dynamic dispatch TableOption attribute applies table options', function (): void {
    $option = new TableOption;
    $blueprint = createTestBlueprint();

    // true/null
    $option->apply($blueprint, 'temporary', true);
    expect($blueprint->temporary)->toBeTrue();

    $option->apply($blueprint, 'temporary', null);
    expect($blueprint->temporary)->toBeTrue();

    // value
    $option->apply($blueprint, 'engine', 'InnoDB');
    expect($blueprint->engine)->toBe('InnoDB');
});
