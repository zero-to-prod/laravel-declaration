<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Post;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\User;

$manifest = __DIR__.'/../Fixtures/manifest/gate.yml';

it('binds the declared policy and grants the check for the request user', function () use ($manifest): void {
    $this->withConfig([
        'laravel-declaration.manifest' => $manifest,
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
    ]);
    $this->actingAs(new User(['id' => 1]));

    $this->getJson('/listed?title=ok')->assertOk()->assertJson(['title' => 'ok']);   // viewAny(User) via gate.policy
});

it('reaches a defined ability with no arguments declared', function () use ($manifest): void {
    $this->withConfig([
        'laravel-declaration.manifest' => $manifest,
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
    ]);
    $this->actingAs(new User(['id' => 1]));

    $this->getJson('/attached?title=ok')->assertOk();                // attach($user) → null default
});

it('returns the policy Response denial through the native seam', function () use ($manifest): void {
    $this->withConfig([
        'laravel-declaration.manifest' => $manifest,
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
    ]);
    $this->actingAs(new User(['id' => 2]));

    $this->getJson('/denied-listed?title=ok')
        ->assertForbidden()
        ->assertJson(['message' => 'Only user 1 may list posts.']);   // Response::message via ->authorize()
});

it('resolves a route parameter name to its bound value for the gate arguments', function () use ($manifest): void {
    $this->withConfig([
        'laravel-declaration.manifest' => $manifest,
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
    ]);
    $this->actingAs(new User(['id' => 1]));

    $this->putJson('/posts/1', ['title' => 'ok'])->assertOk();        // update($user, '1') === true
});

it('authorizes through a gate.define ability reference', function () use ($manifest): void {
    $this->withConfig([
        'laravel-declaration.manifest' => $manifest,
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
    ]);
    $this->actingAs(new User(['id' => 1]));

    $this->putJson('/publish/1', ['title' => 'ok'])->assertOk();      // PublishGate@publish
});

it('passes a class-string argument through to the policy', function () use ($manifest): void {
    $this->withConfig([
        'laravel-declaration.manifest' => $manifest,
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
    ]);
    $this->actingAs(new User(['id' => 1]));

    expect(app(Gate::class)->forUser(new User(['id' => 1]))
        ->check('viewAny', Post::class))->toBeTrue();                  // getPolicyFor(Post::class) → the binding
});

it('resolves an unknown unquoted argument to null', function () use ($manifest): void {
    $this->withConfig([
        'laravel-declaration.manifest' => $manifest,
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
    ]);
    $this->actingAs(new User(['id' => 1]));

    $this->postJson('/attach', ['title' => 'ok'])->assertOk();        // attach($user, null)
});

it('unquotes a quoted literal argument', function () use ($manifest): void {
    $this->withConfig([
        'laravel-declaration.manifest' => $manifest,
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
    ]);
    $this->actingAs(new User(['id' => 1]));

    $this->postJson('/literal', ['title' => 'ok'])->assertOk();       // attach($user, '5')
});

it('passes a non-string argument through untouched', function () use ($manifest): void {
    $this->withConfig([
        'laravel-declaration.manifest' => $manifest,
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
    ]);
    $this->actingAs(new User(['id' => 1]));

    $this->postJson('/numbered', ['title' => 'ok'])->assertOk();      // attach($user, 5)
});

it('resolves each list entry per the gate arguments contract', function () use ($manifest): void {
    $this->withConfig([
        'laravel-declaration.manifest' => $manifest,
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
    ]);
    $this->actingAs(new User(['id' => 1]));

    $this->postJson('/args-list', ['title' => 'ok'])->assertOk();     // attach($user, null, '5')
});

it('rejects an authorize map that declares more than one Gate method', function () use ($manifest): void {
    $this->withConfig([
        'laravel-declaration.manifest' => $manifest,
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
    ]);
    $this->actingAs(new User(['id' => 1]));
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/multi', ['title' => 'ok']))
        ->toThrow(LogicException::class);
});
