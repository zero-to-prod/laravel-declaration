<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockController;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\UserController;

$manifest = __DIR__.'/../Fixtures/manifest/app.yml';

it('registers every declared route', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $Router = app(Router::class);

    expect($Router->getRoutes()->count())->toBe(7)
        ->and($Router->getRoutes()->hasNamedRoute('home'))->toBeTrue()
        ->and($Router->getRoutes()->hasNamedRoute('users.show'))->toBeTrue()
        ->and($Router->getRoutes()->hasNamedRoute('users.update'))->toBeTrue()
        ->and($Router->getRoutes()->hasNamedRoute('users.destroy'))->toBeTrue()
        ->and($Router->getRoutes()->hasNamedRoute('users.patch'))->toBeTrue()
        ->and($Router->getRoutes()->hasNamedRoute('users.store'))->toBeTrue();
});

it('answers GET / with the home action', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->get('/')->assertOk();
});

it('answers the declared verb and uri', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->getJson('/users/5')->assertOk()->assertJson(['action' => 'show']);
    $this->putJson('/users/5')->assertOk()->assertJson(['action' => 'update']);
});

it('uppercases lowercase verbs', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->patchJson('/users/5')->assertOk()->assertJson(['action' => 'update']);
});

it('applies prefix and domain', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->deleteJson('https://account.example.com/api/users/5')
        ->assertOk()
        ->assertJson(['action' => 'destroy']);
});

it('honours the where constraint before the fallback', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->getJson('/users/abc')->assertOk()->assertJson(['fallback' => true]);
});

it('answers unmatched paths with the fallback route', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->getJson('/anything/at/all')->assertOk()->assertJson(['fallback' => true]);
});

it('applies the builders to the registered route', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $Router = app(Router::class);

    $show = $Router->getRoutes()->getByName('users.show');

    expect($show->getMetadata('group'))->toBe('admin')
        ->and($show->getMissing())->toBeInstanceOf(Closure::class)
        ->and($show->getActionName())->toBe(
            UserController::class.'@show',
        );

    $destroy = $Router->getRoutes()->getByName('users.destroy');

    expect($destroy->locksFor())->toBe(10)
        ->and($destroy->waitsFor())->toBe(5)
        ->and($destroy->getPrefix())->toBe('api')
        ->and($destroy->getDomain())->toBe('{account}.example.com')
        ->and($destroy->bindingFieldFor('account'))->toBeNull()
        ->and($Router->getRoutes()->getByName('users.patch')->enforcesScopedBindings())->toBeTrue();

    $store = $Router->getRoutes()->getByName('users.store');

    expect($store->gatherMiddleware())->toContain('can:view,user')
        ->and($store->allowsTrashedBindings())->toBeTrue();

    $update = $Router->getRoutes()->getByName('users.update');

    expect($update->excludedMiddleware())->toContain('web')
        ->and($update->getActionName())->toBe(
            UserController::class.'@update',
        );
});

it('wraps the missing handler in a cache-safe Closure', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $missing = app(Router::class)->getRoutes()->getByName('users.show')->getMissing();

    expect($missing)->toBeInstanceOf(Closure::class)
        ->and($missing(new Request, new ModelNotFoundException)->status())->toBe(404);
});

it('rejects a non-invokable missing handler', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, <<<'YAML'
        app:
          routes:
            - path: "/"
              methods: GET
              action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockController
              name: temp-home
              missing: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\NotInvokable
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $file]);

        $missing = app(Router::class)->getRoutes()->getByName('temp-home')->getMissing();

        $missing(new Request, new ModelNotFoundException);
    } finally {
        unlink($file);
    }
})->throws(LogicException::class);

it('registers no routes without a manifest', function (): void {
    expect(app(Router::class)->getRoutes()->count())->toBe(0)
        ->and(app(MockController::class))->toBeInstanceOf(MockController::class);
});
