<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockController;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\UserController;

$manifest = __DIR__.'/../Fixtures/manifest/app.yml';
$registrars = __DIR__.'/../Fixtures/manifest/route-registrars.yml';

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
        routes:
          addRoute:
            - uri: "/"
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

it('verifies HTTP verb arrays and dynamic route constraints', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, <<<'YAML'
        routes:
          addRoute:
            - uri: "items/{id}/{type}"
              methods: [GET, POST]
              action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockController
              name: items.show
              whereNumber: id
              whereAlpha: type
              bindingFields:
                id: slug
              fallback: false
              block: ~
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $file]);

        $router = app(Router::class);
        $route = $router->getRoutes()->getByName('items.show');

        $ref = new ReflectionProperty($route, 'bindingFields');
        expect($route)->not->toBeNull()
            ->and($route->methods())->toContain('GET', 'POST')
            ->and($route->wheres['id'])->toBe('[0-9]+')
            ->and($route->wheres['type'])->toBe('[a-zA-Z]+')
            ->and($ref->getValue($route)['id'])->toBe('slug');
    } finally {
        unlink($file);
    }
});

it('throws LogicException when route specifies no action', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, <<<'YAML'
        routes:
          addRoute:
            - uri: "/no-action"
              methods: GET
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $file]);
        app(Router::class);
    } finally {
        unlink($file);
    }
})->throws(LogicException::class, 'Route for URI [/no-action] must specify an action.');

it('registers the flat surface under addRoute', function () use ($registrars): void {
    $this->withConfig(['laravel-declaration.manifest' => $registrars]);

    $this->get('/')->assertOk();
});

it('merges group attributes natively', function () use ($registrars): void {
    $this->withConfig([
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
        'laravel-declaration.manifest' => $registrars,
    ]);

    $this->get('/admin/settings/profile')->assertOk();

    $Route = app(Router::class)->getRoutes()->getByName('admin.settings.profile');

    expect($Route->uri())->toBe('admin/settings/profile')
        ->and($Route->gatherMiddleware())->toContain('web')
        ->and($Route->getMetadata('area'))->toBe('admin')
        ->and($Route->wheres['id'])->toBe('[0-9]+')
        ->and($Route->getActionName())->toContain('Admin\ProfileController');
});

it('registers resource routes with pending options', function () use ($registrars): void {
    $this->withConfig([
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
        'laravel-declaration.manifest' => $registrars,
    ]);

    $this->getJson('/photos/5')->assertOk();
    $this->getJson('/photos/abc')->assertNotFound();                     // whereNumber

    expect(app(Router::class)->getRoutes()->hasNamedRoute('gallery.index'))->toBeTrue();
});

it('registers a resource inside a group', function () use ($registrars): void {
    $this->withConfig(['laravel-declaration.manifest' => $registrars]);

    $Routes = app(Router::class)->getRoutes();

    expect($Routes->hasNamedRoute('admin.users.index'))->toBeTrue()
        ->and($Routes->hasNamedRoute('admin.users.destroy'))->toBeTrue()
        ->and($Routes->getByName('admin.users.destroy')->gatherMiddleware())->toContain('can:delete-users')
        ->and($Routes->getByName('admin.users.index')->getActionName())->toContain('Admin\UserController@index');
});

it('registers singleton and api resource registrars', function () use ($registrars): void {
    $this->withConfig(['laravel-declaration.manifest' => $registrars]);

    $Routes = app(Router::class)->getRoutes();

    expect($Routes->hasNamedRoute('profile.store'))->toBeTrue()          // creatable: true
        ->and($Routes->hasNamedRoute('profile.show'))->toBeTrue()
        ->and($Routes->hasNamedRoute('avatar.show'))->toBeTrue()
        ->and($Routes->hasNamedRoute('posts.index'))->toBeTrue()
        ->and($Routes->hasNamedRoute('posts.destroy'))->toBeFalse();     // except: [destroy]
});

it('registers the native view shortcut', function () use ($registrars): void {
    $this->withConfig([
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
        'laravel-declaration.manifest' => $registrars,
    ]);

    $directory = resource_path('views/pages');

    if (! is_dir($directory)) {
        mkdir($directory, recursive: true);
    }

    file_put_contents($directory.'/about.blade.php', '{{ $title }}');

    try {
        $this->get('/about')->assertOk()->assertHeader('X-Frame-Options', 'DENY')->assertSee('About');
        $this->head('/about')->assertOk();                               // GET|HEAD only
    } finally {
        unlink($directory.'/about.blade.php');
        rmdir($directory);
    }
});

it('registers redirect shortcuts', function () use ($registrars): void {
    $this->withConfig(['laravel-declaration.manifest' => $registrars]);

    $this->get('/old-posts/7')->assertRedirect('/posts/7');              // 302, parameter carried
    $this->get('/legacy')->assertStatus(301);
});

it('adapts list options to the pending signature shape', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, <<<'YAML'
        routes:
          addRoute:
            - uri: "/"
              methods: GET
              action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockController
              name: temp-home
          apiResource:
            - name: things
              controller: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockController
              options:
                only: [index, show]                                      # single-array parameter
                whereIn: [thing, [a, b]]                                 # two-argument parameter
                withTrashed: [show]                                      # single-array parameter
                shallow: false                                           # skipped
                metadata: ~                                              # skipped
          resource:
            - name: gadgets
              controller: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockController
              options:
                name:                                                    # Rule 2 map form
                  index: gadgets.index
                parameter:
                  gadgets: device
                withoutMiddlewareFor:
                  destroy: [web]
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $file]);

        $Routes = app(Router::class)->getRoutes();

        expect($Routes->hasNamedRoute('things.index'))->toBeTrue()
            ->and($Routes->getByName('things.show')->wheres['thing'])->toBe('a|b')
            ->and($Routes->getByName('things.show')->allowsTrashedBindings())->toBeTrue()
            ->and($Routes->hasNamedRoute('gadgets.index'))->toBeTrue()
            ->and($Routes->getByName('gadgets.show')->uri())->toBe('gadgets/{device}')
            ->and($Routes->getByName('gadgets.destroy')->excludedMiddleware())->toContain('web');
    } finally {
        unlink($file);
    }
});

it('fails an unknown pending option with Laravel\'s exception', function (): void {
    // Fixture `route-registrars-invalid.yml` declares a resource with `options: {nonexistent: true}`.
    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/route-registrars-invalid.yml']);
})->throws(BadMethodCallException::class);   // Macroable::__call at boot
