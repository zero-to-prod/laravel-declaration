<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ZeroToProd\LaravelDeclaration\Attributes\Mutation;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockClass;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Todo;

$manifest = __DIR__.'/../Fixtures/manifest/action.yml';
$dispatchManifest = __DIR__.'/../Fixtures/manifest/action-dispatch.yml';

beforeEach(function () use ($manifest): void {
    $this->withConfig([
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
        'laravel-declaration.manifest' => $manifest,
    ]);

    createTodosTable();
});

function createTodosTable(): void
{
    Schema::dropIfExists('todos');

    Schema::create('todos', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->boolean('completed')->default(false);
        $table->timestamps();
    });
}

it('creates a record through the declared mutation and redirects with flashed status', function (): void {
    $this->post('/todos', ['title' => 'Buy milk'])
        ->assertRedirect('todos.index')
        ->assertSessionHas('status', 'Todo created successfully!');

    $this->assertDatabaseHas('todos', ['title' => 'Buy milk', 'completed' => false]);
});

it('updates a bound model through the declared mutation and redirects with flashed status', function (): void {
    $todo = Todo::create(['title' => 'Original']);

    $this->patch("/todos/{$todo->id}", ['title' => 'Updated title'])
        ->assertRedirect('todos.index')
        ->assertSessionHas('status', 'Todo updated!');

    $this->assertDatabaseHas('todos', ['id' => $todo->id, 'title' => 'Updated title']);
});

it('toggles the declared boolean column on a bound model', function (): void {
    $todo = Todo::create(['title' => 'Toggle me', 'completed' => false]);

    $this->post("/todos/{$todo->id}/toggle")
        ->assertRedirect('todos.index');

    expect($todo->refresh()->completed)->toBeTrue();

    $this->post("/todos/{$todo->id}/toggle");

    expect($todo->refresh()->completed)->toBeFalse();
});

it('deletes a bound model through the declared mutation and redirects with flashed status', function (): void {
    $todo = Todo::create(['title' => 'Delete me']);

    $this->delete("/todos/{$todo->id}")
        ->assertRedirect('todos.index')
        ->assertSessionHas('status', 'Todo deleted!');

    $this->assertDatabaseMissing('todos', ['id' => $todo->id]);
});

it('aborts the declared action before any mutation when the declared request fails validation', function (): void {
    $this->post('/todos', [])
        ->assertRedirect()
        ->assertSessionHasErrors('title');

    $this->assertDatabaseCount('todos', 0);
});

it('returns a 404 before the action runs when the bound entity does not exist', function (): void {
    $this->patch('/todos/999', ['title' => 'Ghost'])
        ->assertNotFound();
});

it('runs declared action routes identically after the route collection is cached', function () use ($manifest): void {
    $this->artisan('route:cache')->assertSuccessful();

    $this->refreshApplication();
    $this->withConfig([
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
        'laravel-declaration.manifest' => $manifest,
    ]);

    createTodosTable();

    $this->post('/todos', ['title' => 'Cached create'])
        ->assertRedirect('todos.index')
        ->assertSessionHas('status', 'Todo created successfully!');

    $this->assertDatabaseHas('todos', ['title' => 'Cached create']);

    foreach (glob($this->app->bootstrapPath('cache/routes-v*.php')) ?: [] as $cached) {
        @unlink($cached);
    }
});

describe('dynamic dispatch variants', function () use ($dispatchManifest): void {
    beforeEach(function () use ($dispatchManifest): void {
        $this->withConfig([
            'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
            'laravel-declaration.manifest' => $dispatchManifest,
        ]);

        createTodosTable();
    });

    it('resolves the smart `redirect` to an existing named route', function (): void {
        $this->post('/flash-todos', ['title' => 'Named'])
            ->assertRedirect(route('todos-index', absolute: false))
            ->assertSessionHas('status', 'Created!');
    });

    it('redirects through an explicitly declared named route in map and string shape', function (): void {
        $this->post('/routed-todos', ['title' => 'Routed map'])
            ->assertRedirect(route('todos-index', absolute: false));

        $this->post('/explicit-route-todos', ['title' => 'Routed string'])
            ->assertRedirect(route('todos-index', absolute: false));
    });

    it('redirects to a declared path with status, headers and every flash modifier', function (): void {
        $this->post('/path-todos', ['title' => 'Flash everything'])
            ->assertStatus(303)
            ->assertHeader('Location', 'http://localhost/goodbye#anchor')
            ->assertHeader('X-Declared', 'yes')
            ->assertSessionHas('errors')
            ->assertSessionHas('_old_input');

        expect(session('_old_input'))->toBe(['title' => 'Flash everything']);
    });

    it('redirects back via Redirector::back() in bool and map shape', function (): void {
        $todo = Todo::create(['title' => 'Back']);

        $this->patch("/back-todos/{$todo->id}", ['title' => 'Backed up'])->assertRedirect('/');

        $this->patch("/back-map-todos/{$todo->id}", ['title' => 'Backed up again'])
            ->assertRedirect('/from-back');
    });

    it('redirects away to an external URI without validation', function (): void {
        $todo = Todo::create(['title' => 'Away']);

        $this->delete("/away-todos/{$todo->id}")
            ->assertRedirect('https://external.example.com/away');

        $this->assertDatabaseMissing('todos', ['id' => $todo->id]);
    });

    it('redirects to a controller action in string and map shape', function (): void {
        $this->post('/action-todos', ['title' => 'Action'])
            ->assertRedirect(route('mock-target', absolute: false));

        $this->post('/action-map-todos', ['title' => 'Action map'])
            ->assertRedirect(route('mock-target', absolute: false));
    });

    it('falls back to `/` when no redirect generator is declared', function (): void {
        $this->post('/bare-todos', ['title' => 'Bare'])->assertRedirect('/');
    });

    it('defaults `call` to create for `model` and update for `target`', function (): void {
        $this->post('/default-call-todos', ['title' => 'Defaulted create'])
            ->assertRedirect('/');

        $this->assertDatabaseHas('todos', ['title' => 'Defaulted create']);

        $todo = Todo::create(['title' => 'Defaulted update']);

        $this->patch("/default-call-todos/{$todo->id}", ['title' => 'Updated by default'])
            ->assertRedirect('/');

        $this->assertDatabaseHas('todos', ['title' => 'Updated by default']);
    });

    it('lets declared `args` override the request attributes', function (): void {
        $this->post('/arg-todos', ['title' => 'Ignored request input'])
            ->assertRedirect(route('todos-index', absolute: false));

        $this->assertDatabaseHas('todos', ['title' => 'From args']);
    });

    it('toggles the default `completed` column when no column is declared', function (): void {
        $todo = Todo::create(['title' => 'Default toggle']);

        $this->post("/default-toggle/{$todo->id}")->assertRedirect(route('todos-index', absolute: false));

        expect($todo->refresh()->completed)->toBeTrue();
    });

    it('delegates toggle to a declared model method when one exists', function (): void {
        $todo = Todo::create(['title' => 'Custom toggle']);

        $this->post("/switches/{$todo->id}/toggle")->assertRedirect(route('todos-index', absolute: false));

        expect($todo->refresh()->completed)->toBeTrue();
    });

    it('touches the timestamp column with and without an explicit column', function (): void {
        $todo = Todo::create(['title' => 'Touch me', 'created_at' => now()->subYear(), 'updated_at' => now()->subYear()]);

        $this->post("/touch-todos/{$todo->id}");

        expect($todo->refresh()->updated_at->timestamp)->toBeGreaterThan(now()->subMinute()->timestamp)
            ->and($todo->created_at->timestamp)->toBeLessThan(now()->subMonth()->timestamp);

        $this->post("/touch-todos/{$todo->id}/bare");

        expect($todo->refresh()->updated_at->timestamp)->toBeGreaterThan(now()->subMinute()->timestamp);
    });

    it('rejects `call: toggle` on a class-string target', function (): void {
        $this->post('/toggle-models')->assertServerError();

        $this->assertDatabaseCount('todos', 0);
    });

    it('rejects a `model` that does not extend Eloquent Model', function (): void {
        $this->post('/bogus-models')->assertServerError();
    });

    it('rejects a `target` that resolves to nothing', function (): void {
        $todo = Todo::create(['title' => 'Bound']);

        $this->post("/ghost-targets/{$todo->id}")->assertServerError();
    });

    it('requires either `model` or `target` in setDefaults', function (): void {
        $this->post('/void-targets')->assertServerError();
    });

    it('rejects a redirect destination declared as a non-string', function (): void {
        $this->post('/bad-redirects')->assertServerError();
    });

    it('rejects an `action` map whose `action` value is neither string nor array', function (): void {
        $this->post('/bad-action-todos', ['title' => 'Bad action'])->assertServerError();
    });

    it('throws when Mutation is dispatched against a target that is not a Model', function (): void {
        $Mutation = new Mutation;

        expect(fn (): mixed => $Mutation->apply('stdClass', 'create'))->toThrow(LogicException::class)
            ->and(fn (): mixed => $Mutation->apply(new MockClass('x'), 'update'))
            ->toThrow(InvalidArgumentException::class);
    });
});
