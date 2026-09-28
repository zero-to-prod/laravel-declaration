<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\RequestController;

$manifest = __DIR__.'/../Fixtures/manifest/requests.yml';

it('resolves a declared request end to end', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/users', [
        'name' => 'john doe',
        'nickname' => 'jd',
        'email' => 'me@example.com',
        'slug' => 'ok',
        'code' => 'ok',
    ])->assertOk()->assertJson([
        'name' => 'John Doe',
        'nickname' => 'jd',
        'email' => 'me@example.com',
        'slug' => 'ok',
        'code' => 'ok',
    ])->assertHeader('x-passed', '');
});

it('applies the prepare hook, custom messages and pipe rules', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $response = $this->postJson('/users', ['email' => 'me@example.com']);

    $response->assertStatus(422)->assertJsonValidationErrors(['name']);

    expect(array_keys($response->json('errors')))->toBe(['name'])
        ->and($response->json('errors.name.0'))->toBe('A name is required.');
});

it('rejects unknown fields', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/users', [
        'name' => 'john doe',
        'email' => 'me@example.com',
        'slug' => 'ok',
        'code' => 'ok',
        'hacker' => 1,
    ])->assertJsonValidationErrors(['hacker']);
});

it('rejects fields unknown to the rules before the after hooks', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $response = $this->postJson('/users', [
        'name' => 'john doe',
        'email' => 'me@example.com',
        'slug' => 'ok',
        'code' => 'ok',
        'status' => 'banned',
    ])->assertJsonValidationErrors(['status']);

    expect($response->json('errors.status'))->toContain('User is banned.');
});

it('resolves a rule class, static method and function per request', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $response = $this->postJson('/users', [
        'name' => 'john doe',
        'email' => 'taken@example.com',
        'slug' => 'ok',
        'code' => 'ok',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['email']);

    expect($response->json('errors.email.0'))->toBe('The email address has already been taken.');
});

it('resolves validationData and passedValidation references', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/data', ['name' => 'x'])
        ->assertOk()
        ->assertJson(['name' => 'x', 'extra' => 'manifest'])
        ->assertHeader('x-passed', 'yes');
});

it('builds the validator through a reference', function () use ($manifest): void {
    $this->withConfig([
        'laravel-declaration.manifest' => $manifest,
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
    ]);

    $this->postJson('/avatar', ['file' => 'x'])
        ->assertOk()
        ->assertJson(['action' => 'avatar']);
});

it('runs the withValidator reference', function () use ($manifest): void {
    $this->withConfig([
        'laravel-declaration.manifest' => $manifest,
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
    ]);

    $this->postJson('/avatar', ['file' => 'banned'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

it('redirects failed validation with the declared error bag', function () use ($manifest): void {
    $this->withConfig([
        'laravel-declaration.manifest' => $manifest,
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
    ]);

    $this->post('/avatar', [])
        ->assertRedirect('/failed');

    expect(session('errors')?->getBag('avatar')->has('file'))->toBeTrue();
});

it('answers with the failedValidation reference', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/hooks', [])
        ->assertStatus(422)
        ->assertJson(['errors' => ['custom name is custom-required.']]);
});

it('answers with the failedAuthorization reference', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/denied', [])
        ->assertStatus(403)
        ->assertJson(['message' => 'custom denied']);
});

it('throws the default authorization exception', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/forbidden', [])->assertForbidden();
});

it('authorizes through an access response', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/granted', ['name' => 'x'])
        ->assertOk()
        ->assertJson(['name' => 'x']);
});

it('throws when the metadata names an undeclared request', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    $this->withoutExceptionHandling();

    expect(fn (): TestResponse => $this->postJson('/ghost'))->toThrow(LogicException::class);
});

it('throws when the route declares no metadata.request', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    $this->withoutExceptionHandling();

    expect(fn (): TestResponse => $this->postJson('/untyped'))->toThrow(LogicException::class);
});

it('throws when no manifest is declared, binding an empty one', function (): void {
    app(Router::class)->post('manual', [RequestController::class, 'store']);

    expect(app(Manifest::class)->app->requests->count())->toBe(0);

    $this->withoutExceptionHandling();

    expect(fn (): TestResponse => $this->postJson('/manual'))->toThrow(LogicException::class);
});
