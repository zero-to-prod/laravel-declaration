<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Fluent;
use ZeroToProd\LaravelDeclaration\Internal\Engine\Guards;

/** @var list<array{0: string, 1: string}> */
$reports = [];

function builder(): Builder
{
    return Schema::connection(null);
}

function blueprint(string $table): Blueprint
{
    return new Blueprint(DB::connection(), $table);
}

beforeEach(function () use (&$reports): void {
    $reports = [];

    Schema::dropIfExists('users');
    Schema::dropIfExists('people');
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('people', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->foreignId('user_id')->constrained('users');
        $table->index('title', 'people_title_index');
        $table->timestamps();
    });

    $this->guards = new Guards(builder(), static function (string $subject, string $message) use (&$reports): void {
        $reports[] = [$subject, $message];
    });
});

it('guards the Builder verbs and reports each decision', function () use (&$reports): void {
    $guards = $this->guards;

    expect($guards(builder(), 'create', ['people'], []))->toBeFalse()
        ->and($guards(builder(), 'create', ['table' => 'ghosts'], []))->toBeTrue()
        ->and($guards->created)->toBe(1)
        ->and($guards(builder(), 'table', ['people'], []))->toBeTrue()
        ->and($guards(builder(), 'table', ['ghosts'], []))->toBeFalse()
        ->and($guards(builder(), 'table', ['ghosts'], []))->toBeFalse()               // reported once
        ->and($guards(builder(), 'rename', ['people', 'persons'], []))->toBeTrue()
        ->and($guards(builder(), 'rename', ['from' => 'people', 'to' => 'users'], []))->toBeFalse()
        ->and($guards(builder(), 'rename', ['ghosts', 'persons'], []))->toBeFalse()
        ->and($guards(builder(), 'drop', ['people'], []))->toBeTrue()
        ->and($guards(builder(), 'dropIfExists', ['ghosts'], []))->toBeTrue()
        ->and($guards(builder(), 'hasTable', ['people'], []))->toBeTrue()            // no row: unguarded
        ->and($reports)->toBe([
            ['people', '<fg=gray>Already exists</>'],
            ['ghosts', '<fg=green;options=bold>Created</>'],
            ['ghosts', '<fg=yellow>Table does not exist</>'],
            ['people', '<fg=green;options=bold>Renamed to persons</>'],
            ['people', '<fg=gray>Rename skipped</>'],
            ['ghosts', '<fg=gray>Rename skipped</>'],
            ['people', '<fg=yellow>Dropped</>'],
            ['ghosts', '<fg=yellow>Dropped if exists</>'],
        ]);
});

it('guards Blueprint columns: missing to add, present to change, canonical and morph targets', function (): void {
    $guards = $this->guards;
    $people = blueprint('people');

    expect($guards($people, 'string', ['column' => 'nickname'], []))->toBeTrue()
        ->and($guards($people, 'string', ['title'], []))->toBeFalse()                        // positional → named through the signature
        ->and($guards($people, 'string', ['column' => 'title', 'length' => 200], ['change' => null]))->toBeTrue()
        ->and($guards($people, 'string', ['column' => 'nickname'], ['change' => null]))->toBeFalse()
        ->and($guards($people, 'timestamps', [], []))->toBeFalse()                           // canonical created_at exists
        ->and($guards($people, 'softDeletes', [], []))->toBeTrue()                           // canonical deleted_at missing
        ->and($guards($people, 'morphs', ['name' => 'taggable'], []))->toBeTrue()            // taggable_id missing
        ->and($guards($people, 'nullableMorphs', [], []))->toBeTrue()                        // no target: unguarded
        ->and($guards($people, 'temporary', [], []))->toBeTrue()                             // no column target
        ->and($guards($people, 'nullabel', ['x'], []))->toBeTrue()                           // a __call surface has no parameters to name
        ->and($guards($people, 'create', [], []))->toBeFalse()                               // lifecycle verbs are not declarable in a body
        ->and($guards->altered('people'))->toBe(7);

    $guards->resetAltered();

    expect($guards->altered('people'))->toBe(0);
});

it('guards Blueprint indexes, foreign keys, renames and drops against the live schema', function (): void {
    $guards = $this->guards;
    $people = blueprint('people');

    expect($guards($people, 'index', ['columns' => ['title']], []))->toBeFalse()           // by columns
        ->and($guards($people, 'index', [['title'], 'people_title_index'], []))->toBeFalse() // by name
        ->and($guards($people, 'unique', ['columns' => 'user_id'], []))->toBeTrue()
        ->and($guards($people, 'index', [], []))->toBeTrue()                                 // no target
        ->and($guards($people, 'foreign', ['columns' => 'user_id'], []))->toBeFalse()
        ->and($guards($people, 'foreign', ['columns' => ['title']], []))->toBeTrue()
        ->and($guards($people, 'foreign', [], []))->toBeTrue()
        ->and($guards($people, 'renameColumn', ['from' => 'title', 'to' => 'headline'], []))->toBeTrue()
        ->and($guards($people, 'renameColumn', ['from' => 'title', 'to' => 'user_id'], []))->toBeFalse()
        ->and($guards($people, 'renameColumn', ['from' => 'title'], []))->toBeTrue()         // incomplete: PHP decides
        ->and($guards($people, 'renameIndex', ['people_title_index', 'people_title_ft'], []))->toBeTrue()
        ->and($guards($people, 'renameIndex', ['people_title_ft', 'people_title_index'], []))->toBeFalse()
        ->and($guards($people, 'dropIndex', ['people_title_index'], []))->toBeTrue()
        ->and($guards($people, 'dropIndex', ['index' => ['nope']], []))->toBeFalse()
        ->and($guards($people, 'dropIndex', [], []))->toBeTrue()
        ->and($guards($people, 'dropForeign', ['index' => ['user_id']], []))->toBeTrue()
        ->and($guards($people, 'dropForeign', ['title'], []))->toBeFalse()
        ->and($guards($people, 'dropForeign', [], []))->toBeTrue()
        ->and($guards($people, 'dropColumn', ['columns' => ['title', 'user_id']], []))->toBeTrue()
        ->and($guards($people, 'dropColumn', ['nope'], []))->toBeFalse()
        ->and($guards($people, 'dropTimestamps', [], []))->toBeTrue()                        // canonical created_at exists
        ->and($guards($people, 'dropSoftDeletes', [], []))->toBeFalse()
        ->and($guards($people, 'dropMorphs', ['name' => 'taggable'], []))->toBeTrue()        // no canonical, no columns target
        ->and($guards(new Fluent, 'nullable', [], []))->toBeTrue();                          // modifiers ride the return unguarded
});
