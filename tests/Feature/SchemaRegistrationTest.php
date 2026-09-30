<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use ZeroToProd\LaravelDeclaration\Attributes\ColumnModifier;
use ZeroToProd\LaravelDeclaration\Attributes\ColumnType;
use ZeroToProd\LaravelDeclaration\Attributes\ForeignKeyModifier;
use ZeroToProd\LaravelDeclaration\Attributes\TableConstraint;
use ZeroToProd\LaravelDeclaration\Attributes\TableOption;
use ZeroToProd\LaravelDeclaration\ColumnDefinitionModel;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Schema;
use ZeroToProd\LaravelDeclaration\TableDefinition;

test('it creates declared tables, columns, indexes, and constraints via declaration:migrate', function (): void {
    SchemaFacade::dropIfExists('users');
    SchemaFacade::dropIfExists('todos');
    SchemaFacade::dropIfExists('tags');

    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/schema.yml',
    ]);

    expect(SchemaFacade::hasTable('users'))->toBeFalse()
        ->and(SchemaFacade::hasTable('todos'))->toBeFalse()
        ->and(SchemaFacade::hasTable('tags'))->toBeFalse();

    $this->artisan('declaration:migrate')
        ->expectsOutputToContain('Created')
        ->expectsOutputToContain('Schema migration complete. [3] table(s) created.')
        ->assertSuccessful();

    expect(SchemaFacade::hasTable('users'))->toBeTrue()
        ->and(SchemaFacade::hasTable('todos'))->toBeTrue()
        ->and(SchemaFacade::hasTable('tags'))->toBeTrue()
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

    // Verify columns on users

    // Verify columns on todos

    // Verify columns on tags

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

    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/schema.yml',
    ]);

    $this->artisan('laravel-declaration:migrate')
        ->expectsOutputToContain('Created')
        ->expectsOutputToContain('Schema migration complete. [3] table(s) created.')
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
            'tables' => [
                'users' => [
                    'nonExistentBlueprintMethod' => true,
                ],
            ],
        ],
    ]);
})->throws(LogicException::class, 'Unknown Blueprint method or table option [nonExistentBlueprintMethod].');

test('it throws LogicException during manifest hydration when column method is unknown', function (): void {
    Manifest::from([
        'schema' => [
            'tables' => [
                'users' => [
                    'string' => [
                        'column' => 'email',
                    ],
                ],
            ],
        ],
    ]);

    expect(fn (): ColumnDefinitionModel => ColumnDefinitionModel::fromDefinition('invalidBlueprintCol', null))
        ->toThrow(LogicException::class, 'Unknown Blueprint column method [invalidBlueprintCol].');
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

test('ColumnDefinitionModel handles constraint shapes and factory parameters', function (): void {
    $blueprint = createTestBlueprint();

    // Constrained true with fk modifiers
    $model1 = new ColumnDefinitionModel(['user_id'], [], ['cascadeOnDelete' => true, 'onDelete' => 'cascade', 'onUpdate' => ['cascade']], true);
    $col1 = $model1->apply($blueprint, 'foreignId');
    expect($col1)->toBeInstanceOf(ForeignIdColumnDefinition::class);

    // Constrained null with col modifiers
    $modelNull = new ColumnDefinitionModel(['account_id'], ['nullable' => true, 'default' => 'active', 'comment' => ['a comment']], []);
    $colNull = $modelNull->apply($blueprint, 'foreignId');
    expect($colNull)->toBeInstanceOf(ForeignIdColumnDefinition::class);

    // Constrained string table
    $model2 = new ColumnDefinitionModel(['account_id'], [], [], 'accounts');
    $col2 = $model2->apply($blueprint, 'foreignId');
    expect($col2)->toBeInstanceOf(ForeignIdColumnDefinition::class);

    // Constrained list array
    $model3 = new ColumnDefinitionModel(['account_id'], [], [], ['accounts', 'acc_id']);
    $col3 = $model3->apply($blueprint, 'foreignId');
    expect($col3)->toBeInstanceOf(ForeignIdColumnDefinition::class);

    // Constrained map array
    $model4 = new ColumnDefinitionModel(['account_id'], [], [], ['table' => 'accounts', 'column' => 'acc_id', 'indexName' => 'acc_fk']);
    $col4 = $model4->apply($blueprint, 'foreignId');
    expect($col4)->toBeInstanceOf(ForeignIdColumnDefinition::class);

    // Constrained fallback/other (e.g. object)
    $model5 = new ColumnDefinitionModel(['obj_id'], [], [], (object) ['other' => true]);
    $col5 = $model5->apply($blueprint, 'foreignId');
    expect($col5)->toBeInstanceOf(ForeignIdColumnDefinition::class);

    // fromDefinition with column vs name
    $morphModel = ColumnDefinitionModel::fromDefinition('morphs', ['column' => 'imageable']);
    expect($morphModel->factoryArguments)->toBe(['imageable']);

    // fromDefinition with args array
    $decimalModel = ColumnDefinitionModel::fromDefinition('decimal', ['args' => ['balance', 8, 4]]);
    expect($decimalModel->factoryArguments)->toBe(['balance', 8, 4]);

    // fromDefinition with scalar value
    $enumModel = ColumnDefinitionModel::fromDefinition('string', 'username');
    expect($enumModel->factoryArguments)->toBe(['username']);

    // fromDefinition with null
    $nullModel = ColumnDefinitionModel::fromDefinition('timestamps', null);
    expect($nullModel->factoryArguments)->toBeEmpty();
});

test('TableDefinition handles instance context, scalar index, associative index, and pre-partitioned', function (): void {
    // Instance context
    $orig = new TableDefinition;
    expect(TableDefinition::from($orig))->toBe($orig);

    // Scalar index
    $scalarIdx = TableDefinition::from(['index' => 'single_col']);
    expect($scalarIdx->indexes['index'])->toBe(['single_col']);

    // Associative index (non-list array)
    $assocIdx = TableDefinition::from(['index' => ['columns' => ['col_a'], 'name' => 'idx_a']]);
    expect($assocIdx->indexes['index'])->toBe([['columns' => ['col_a'], 'name' => 'idx_a']]);

    // Non-array context
    $empty = TableDefinition::from(null);
    expect($empty->options)->toBeEmpty()
        ->and($empty->columns)->toBeEmpty()
        ->and($empty->indexes)->toBeEmpty();

    // Pre-partitioned context
    $partitioned = TableDefinition::from([
        'options' => ['engine' => 'InnoDB'],
        'columns' => ['string' => [new ColumnDefinitionModel(['col_a'])]],
        'indexes' => ['index' => ['col_a']],
    ]);
    expect($partitioned->options)->toBe(['engine' => 'InnoDB'])
        ->and($partitioned->columns['string'])->toHaveCount(1)
        ->and($partitioned->indexes['index'])->toBe(['col_a']);

    // Blueprint method that returns void (morphs) and Collection (timestamps) and options with method
    $tableDef = TableDefinition::from([
        'morphs' => 'taggable',
        'timestamps' => null,
        'comment' => 'table comment',
        'index' => [
            [['taggable_id', 'taggable_type'], 'composite_idx'],
            'taggable_id',
        ],
    ]);

    $blueprint = createTestBlueprint('items');
    $tableDef->apply($blueprint);

    expect(collect($blueprint->getColumns())->pluck('name')->toArray())
        ->toContain('taggable_type', 'taggable_id', 'created_at', 'updated_at');
});

test('ColumnDefinitionModel covers remaining branches', function (): void {
    $blueprint = createTestBlueprint();

    // fromDefinition throws when column type is not a Blueprint method
    expect(fn (): ColumnDefinitionModel => ColumnDefinitionModel::fromDefinition('invalidColType', null))
        ->toThrow(LogicException::class, 'Unknown Blueprint column method [invalidColType].');

    // Method parameter named 'column' receives 'name' from definition
    $nameModel = ColumnDefinitionModel::fromDefinition('string', ['name' => 'username']);
    expect($nameModel->factoryArguments)->toBe(['username']);

    // apply with scalar modifier
    $colModel = new ColumnDefinitionModel(['bio'], ['default' => 'none', 'nullable' => true, 'comment' => null]);
    $col = $colModel->apply($blueprint, 'text');
    expect($col->get('default'))->toBe('none');
});

test('Schema DataModel instantiates with null connection and empty tables collection by default', function (): void {
    $schema = Schema::from([]);
    expect($schema->connection)->toBeNull()
        ->and($schema->tables->isEmpty())->toBeTrue();
});
