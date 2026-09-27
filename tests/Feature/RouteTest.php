<?php

declare(strict_types=1);

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;
use UnexpectedValueException;
use Zerotoprod\DataModel\PropertyRequiredException;
use ZeroToProd\LaravelDeclaration\App;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Route;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockController;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\UserController;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\UserMissingHandler;

it('defaults to no routes', function (): void {
    expect(App::from([])->routes->count())->toBe(0);
});

it('hydrates a route declaration', function (): void {
    $Route = Route::from([
        'path' => 'users/{user}',
        'methods' => 'GET',
        'action' => [UserController::class, 'show'],
        'name' => 'users.show',
        'prefix' => 'api',
        'domain' => '{account}.example.com',
        'middleware' => ['auth:sanctum'],
        'withoutMiddleware' => ['web'],
        'can' => ['ability' => 'view', 'models' => 'user'],
        'where' => ['user' => '[0-9]+'],
        'setDefaults' => ['user' => 1],
        'missing' => UserMissingHandler::class,
        'scopeBindings' => true,
        'withTrashed' => true,
        'block' => ['lockSeconds' => 10, 'waitSeconds' => 5],
        'metadata' => ['group' => 'admin'],
    ]);

    expect($Route->path)->toBe('users/{user}')
        ->and($Route->methods)->toBe('GET')
        ->and($Route->action)->toBe([UserController::class, 'show']);
});

it('exposes builders in dispatch order, skipping unset builders', function (): void {
    $Route = Route::from([
        'path' => 'users/{user}',
        'methods' => 'PUT',
        'action' => [UserController::class, 'update'],
        'name' => 'users.update',
        'prefix' => 'api',
        'domain' => '{account}.example.com',
        'middleware' => ['auth:sanctum'],
        'can' => ['ability' => 'update', 'models' => 'user'],
        'where' => ['user' => '[0-9]+'],
        'fallback' => false,
        'scopeBindings' => true,
        'metadata' => ['group' => 'admin'],
    ]);

    expect($Route->builders())->toBe([
        'name' => 'users.update',
        'prefix' => 'api',
        'domain' => '{account}.example.com',
        'middleware' => ['auth:sanctum'],
        'can' => ['ability' => 'update', 'models' => 'user'],
        'where' => ['user' => '[0-9]+'],
        'scopeBindings' => true,
        'metadata' => ['group' => 'admin'],
    ]);
});

it('exposes flag builders as true', function (): void {
    $Route = Route::from([
        'path' => 'users',
        'methods' => 'GET',
        'action' => UserController::class,
        'fallback' => true,
    ]);

    expect($Route->builders())->toBe(['fallback' => true]);
});

it('hydrates routes from the manifest', function (): void {
    $manifest = Manifest::from(
        Yaml::parseFile(__DIR__.'/../Fixtures/manifest/app.yml'),
    );

    expect($manifest->app->routes->count())->toBe(7)
        ->and($manifest->app->routes->first()->path)->toBe('/')
        ->and($manifest->app->routes->first()->methods)->toBe('GET')
        ->and($manifest->app->routes->first()->action)->toBe(
            MockController::class,
        )
        ->and($manifest->app->routes->first()->builders())->toBe(['name' => 'home'])
        ->and($manifest->app->routes->get(5)->builders()['can'])->toBe(
            ['ability' => 'view', 'models' => 'user'],
        )
        ->and($manifest->app->routes->last()->builders())->toBe([
            'where' => ['any' => '.*'],
            'fallback' => true,
        ]);
});

it('requires path, methods and action', function (): void {
    Route::from(['path' => '/', 'methods' => 'GET']);
})->throws(PropertyRequiredException::class);

it('rejects unknown keys', function (): void {
    Route::from([
        'path' => '/',
        'methods' => 'GET',
        'action' => MockController::class,
        'bogus' => 1,
    ]);
})->throws(InvalidArgumentException::class);

it('rejects multiple verbs', function (): void {
    Route::from([
        'path' => '/',
        'methods' => ['GET', 'POST'],
        'action' => MockController::class,
    ]);
})->throws(UnexpectedValueException::class);

it('rejects unknown verbs', function (): void {
    Route::from([
        'path' => '/',
        'methods' => 'ANY',
        'action' => MockController::class,
    ]);
})->throws(UnexpectedValueException::class);

it('accepts a lowercase verb', function (): void {
    expect(Route::from([
        'path' => '/',
        'methods' => 'get',
        'action' => MockController::class,
    ])->methods)->toBe('get');
});

it('treats a string context as empty', function (): void {
    Route::from('placeholder');
})->throws(PropertyRequiredException::class);

it('returns an instance context unchanged', function (): void {
    $Route = Route::from([
        'path' => '/',
        'methods' => 'GET',
        'action' => MockController::class,
    ]);

    expect(Route::from($Route))->toBe($Route);
});

it('round-trips through the array and collection helpers', function (): void {
    $Route = Route::from([
        'path' => '/',
        'methods' => 'GET',
        'action' => MockController::class,
        'name' => 'home',
    ]);

    expect($Route->toArray())->toBe([
        'path' => '/',
        'methods' => 'GET',
        'action' => MockController::class,
        'name' => 'home',
        'prefix' => null,
        'domain' => null,
        'middleware' => [],
        'withoutMiddleware' => [],
        'can' => [],
        'where' => [],
        'setDefaults' => [],
        'missing' => null,
        'fallback' => false,
        'scopeBindings' => false,
        'withoutScopedBindings' => false,
        'withTrashed' => false,
        'block' => null,
        'withoutBlocking' => false,
        'metadata' => [],
    ])
        ->and($Route->collect()->get('name'))->toBe('home');
});
