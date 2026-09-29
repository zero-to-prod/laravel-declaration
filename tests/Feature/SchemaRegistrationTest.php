<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\ForeignKeyDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use ZeroToProd\LaravelDeclaration\Attributes\ColumnModifier;
use ZeroToProd\LaravelDeclaration\Attributes\ColumnType;
use ZeroToProd\LaravelDeclaration\Attributes\ForeignKeyModifier;
use ZeroToProd\LaravelDeclaration\Attributes\TableConstraint;
use ZeroToProd\LaravelDeclaration\Attributes\TableOption;
use ZeroToProd\LaravelDeclaration\ColumnDefinitionModel;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Providers\SchemaDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Schema;
use ZeroToProd\LaravelDeclaration\TableDefinition;

test('it creates declared tables, columns, indexes, and constraints on boot', function (): void {
    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/schema.yml',
    ]);

    expect(SchemaFacade::hasTable('users'))->toBeTrue()
        ->and(SchemaFacade::hasTable('todos'))->toBeTrue()
        ->and(SchemaFacade::hasTable('tags'))->toBeTrue();

    // Verify columns on users
    expect(SchemaFacade::hasColumns('users', [
        'id', 'name', 'email', 'email_verified_at', 'password', 'remember_token', 'created_at', 'updated_at',
    ]))->toBeTrue();

    // Verify columns on todos
    expect(SchemaFacade::hasColumns('todos', [
        'id', 'user_id', 'title', 'description', 'completed', 'created_at', 'updated_at',
    ]))->toBeTrue();

    // Verify columns on tags
    expect(SchemaFacade::hasColumns('tags', [
        'id', 'name', 'created_at', 'updated_at',
    ]))->toBeTrue();

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
    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/schema.yml',
    ]);

    DB::table('users')->insert([
        'id' => 1,
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'secret',
    ]);

    expect(DB::table('users')->count())->toBe(1);

    /** @var Manifest $manifest */
    $manifest = app(Manifest::class);
    $provider = new SchemaDeclarationServiceProvider(app());
    $provider->applySchema($manifest->schema);

    expect(DB::table('users')->count())->toBe(1)
        ->and(DB::table('users')->where('id', 1)->value('name'))->toBe('Jane Doe');
});

test('it skips table creation during boot when auto_migrate is false and creates tables via declaration:migrate', function (): void {
    SchemaFacade::dropIfExists('users');
    SchemaFacade::dropIfExists('todos');
    SchemaFacade::dropIfExists('tags');

    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/schema.yml',
        'laravel-declaration.schema.auto_migrate' => false,
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
        ->and(SchemaFacade::hasTable('tags'))->toBeTrue();

    // Re-running reports tables already exist
    $this->artisan('declaration:migrate')
        ->expectsOutputToContain('Already exists')
        ->expectsOutputToContain('Schema migration complete. [0] table(s) created.')
        ->assertSuccessful();
});

test('declaration:migrate works via laravel-declaration:migrate alias', function (): void {
    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/schema.yml',
    ]);

    $this->artisan('laravel-declaration:migrate')
        ->expectsOutputToContain('Already exists')
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
})->throws(LogicException::class, 'Unknown table key or Blueprint method [nonExistentBlueprintMethod].');

test('it throws LogicException during manifest hydration when column modifier is unknown', function (): void {
    Manifest::from([
        'schema' => [
            'tables' => [
                'users' => [
                    'string' => [
                        'column' => 'email',
                        'invalidModifier' => true,
                    ],
                ],
            ],
        ],
    ]);
})->throws(LogicException::class, 'Unknown column modifier or option [invalidModifier] for column type [string].');

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

    // Constrained true
    $model1 = new ColumnDefinitionModel([], [], [], true);
    expect($model1->isConstrained())->toBeTrue();
    $fk1 = $model1->applyConstraint($blueprint->foreignId('user_id'));
    expect($fk1)->toBeInstanceOf(ForeignKeyDefinition::class);

    // Constrained null
    $modelNull = new ColumnDefinitionModel([], [], []);
    expect($modelNull->isConstrained())->toBeFalse();

    // Constrained string table
    $model2 = new ColumnDefinitionModel([], [], [], 'accounts');
    $fk2 = $model2->applyConstraint($blueprint->foreignId('account_id'));
    expect($fk2->get('on'))->toBe('accounts');

    // Constrained list array
    $model3 = new ColumnDefinitionModel([], [], [], ['accounts', 'acc_id']);
    $fk3 = $model3->applyConstraint($blueprint->foreignId('account_id'));
    expect($fk3->get('on'))->toBe('accounts');

    // Constrained map array
    $model4 = new ColumnDefinitionModel([], [], [], ['table' => 'accounts', 'column' => 'acc_id', 'indexName' => 'acc_fk']);
    $fk4 = $model4->applyConstraint($blueprint->foreignId('account_id'));
    expect($fk4->get('on'))->toBe('accounts');

    // Constrained fallback/other
    $model5 = new ColumnDefinitionModel([], [], [], false);
    expect($model5->isConstrained())->toBeFalse();

    // morphs parameter mapping (column vs name)
    $morphModel = ColumnDefinitionModel::fromDefinition('morphs', ['column' => 'imageable']);
    expect($morphModel->factoryArguments())->toBe(['imageable']);

    // decimal parameters with defaults
    $decimalModel = ColumnDefinitionModel::fromDefinition('decimal', ['column' => 'balance', 'places' => 4]);
    expect($decimalModel->factoryArguments())->toBe(['balance', 8, 4]);

    // enum parameters
    $enumModel = ColumnDefinitionModel::fromDefinition('enum', ['column' => 'type', 'allowed' => ['a', 'b']]);
    expect($enumModel->factoryArguments())->toBe(['type', ['a', 'b']]);
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
        'columns' => [],
        'indexes' => [],
    ]);
    expect($partitioned->options)->toBe(['engine' => 'InnoDB']);

    // Blueprint method that returns void (morphs) and Collection (timestamps)
    $tableDef = TableDefinition::from([
        'morphs' => 'taggable',
        'timestamps' => null,
    ]);

    $blueprint = createTestBlueprint('items');
    $tableDef->apply($blueprint);

    expect(collect($blueprint->getColumns())->pluck('name')->toArray())
        ->toContain('taggable_type', 'taggable_id', 'created_at', 'updated_at');
});

test('ColumnDefinitionModel covers remaining branches', function (): void {
    $blueprint = createTestBlueprint();

    // Line 90: Fallback when constrained is neither true, string, nor array
    $modelObjectConstrained = new ColumnDefinitionModel([], [], [], (object) ['other' => true]);
    $fk = $modelObjectConstrained->applyConstraint($blueprint->foreignId('obj_id'));
    expect($fk)->toBeInstanceOf(ForeignKeyDefinition::class);

    // Line 96: fromDefinition throws when column type is not a Blueprint method
    expect(fn (): ColumnDefinitionModel => ColumnDefinitionModel::fromDefinition('invalidColType', null))
        ->toThrow(LogicException::class, 'Unknown table key or Blueprint method [invalidColType].');

    // Lines 122..123: Method parameter named 'column' receives 'name' from definition
    $nameModel = ColumnDefinitionModel::fromDefinition('string', ['name' => 'username']);
    expect($nameModel->factoryArguments())->toBe(['username']);

    // Line 144: Parameter with no default value not present in paramValues resolves to null
    $missingParamModel = ColumnDefinitionModel::fromDefinition('foreignIdFor', ['column' => 'author_id']);
    expect($missingParamModel->factoryArguments())->toBe([null, 'author_id']);
});

test('Schema DataModel instantiates with null connection and empty tables collection by default', function (): void {
    $schema = Schema::from([]);
    expect($schema->connection)->toBeNull()
        ->and($schema->tables->isEmpty())->toBeTrue();
});
