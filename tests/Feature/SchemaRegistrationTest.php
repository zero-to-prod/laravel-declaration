<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as SchemaFacade;

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

test('declaration:migrate drives the named connection\'s schema builder with --connection', function (): void {
    SchemaFacade::dropIfExists('users');
    SchemaFacade::dropIfExists('todos');
    SchemaFacade::dropIfExists('tags');
    SchemaFacade::dropIfExists('audits');

    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/schema.yml',
    ]);

    $this->artisan('declaration:migrate', ['--connection' => 'testing'])
        ->expectsOutputToContain('Schema migration complete. [4] table(s) created.')
        ->assertSuccessful();

    expect(SchemaFacade::connection('testing')->hasTable('users'))->toBeTrue();
});

test('declaration:migrate outputs info message when no schema block is present', function (): void {
    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/app.yml',
    ]);

    $this->artisan('declaration:migrate')
        ->expectsOutputToContain('No declarative schema defined in manifest.')
        ->assertSuccessful();
});

test('it fails natively when a create body names an unknown Blueprint method', function (): void {
    SchemaFacade::dropIfExists('unknown_method');

    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        schema:
          create:
            unknown_method:
              nonExistentBlueprintMethod: ~
        YAML)]);

    $this->artisan('declaration:migrate');
})->throws(BadMethodCallException::class);   // Macroable::__call on the Blueprint

test('it stores a modifier typo on the Fluent column definition instead of rejecting it', function (): void {
    SchemaFacade::dropIfExists('typo_table');

    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        schema:
          create:
            typo_table:
              id: ~
              string:
                - column: x
                  nullabel: ~
        YAML)]);

    $this->artisan('declaration:migrate')
        ->expectsOutputToContain('Created')
        ->assertSuccessful();

    expect(SchemaFacade::hasTable('typo_table'))->toBeTrue()
        ->and(SchemaFacade::hasColumn('typo_table', 'x'))->toBeTrue();
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

test('it alters an existing table idempotently through the guard table', function (): void {
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

    $this->artisan('declaration:migrate')
        ->expectsOutputToContain('Table does not exist')   // the `ghost` table
        ->expectsOutputToContain('Altered')
        ->assertSuccessful();

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

test('it dispatches guarded actions only when the guard passes', function (): void {
    SchemaFacade::dropIfExists('widget');

    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/schema-none.yml']);

    $this->artisan('declaration:migrate')
        ->expectsOutputToContain('Altered [1] action(s)')
        ->assertSuccessful();

    expect(SchemaFacade::hasColumn('widget', 'uuid'))->toBeTrue();
});
