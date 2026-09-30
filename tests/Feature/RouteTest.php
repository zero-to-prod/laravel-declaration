<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Zerotoprod\DataModel\PropertyRequiredException;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Route;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockController;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\UserController;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\UserMissingHandler;

it('defaults to no routes', function (): void {
    expect(Manifest::from([])->routes->count())->toBe(0);
});

it('hydrates a route declaration', function (): void {
    $Route = Route::from([
        'uri' => 'users/{user}',
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

    expect($Route->uri)->toBe('users/{user}')
        ->and($Route->methods)->toBe('GET')
        ->and($Route->action)->toBe([UserController::class, 'show'])
        ->and($Route->builders['name'])->toBe('users.show')
        ->and($Route->builders['prefix'])->toBe('api');
});

it('exposes builders in dispatch order, skipping unset builders', function (): void {
    $Route = Route::from([
        'uri' => 'users/{user}',
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

    expect($Route->builders)->toBe([
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
});

it('exposes flag builders as true', function (): void {
    $Route = Route::from([
        'uri' => 'users',
        'methods' => 'GET',
        'action' => UserController::class,
        'fallback' => true,
    ]);

    expect($Route->builders)->toBe(['fallback' => true]);
});

it('hydrates routes from the manifest', function (): void {
    $manifest = Manifest::from(
        Yaml::parseFile(__DIR__.'/../Fixtures/manifest/app.yml'),
    );

    expect($manifest->routes->count())->toBe(7)
        ->and($manifest->routes->first()->uri)->toBe('/')
        ->and($manifest->routes->first()->methods)->toBe('GET')
        ->and($manifest->routes->first()->action)->toBe(
            MockController::class,
        )
        ->and($manifest->routes->first()->builders)->toBe(['name' => 'home'])
        ->and($manifest->routes->get(5)->builders['can'])->toBe(
            ['ability' => 'view', 'models' => 'user'],
        )
        ->and($manifest->routes->last()->builders)->toBe([
            'where' => ['any' => '.*'],
            'fallback' => true,
        ]);
});

it('requires uri, methods and action', function (): void {
    Route::from(['methods' => 'GET']);
})->throws(PropertyRequiredException::class);

it('accepts a lowercase verb', function (): void {
    expect(Route::from([
        'uri' => '/',
        'methods' => 'get',
        'action' => MockController::class,
    ])->methods)->toBe('get');
});

it('treats a string context as empty', function (): void {
    Route::from('placeholder');
})->throws(PropertyRequiredException::class);

it('returns an instance context unchanged', function (): void {
    $Route = Route::from([
        'uri' => '/',
        'methods' => 'GET',
        'action' => MockController::class,
    ]);

    expect(Route::from($Route))->toBe($Route);
});

it('round-trips through the array and collection helpers', function (): void {
    $Route = Route::from([
        'uri' => '/',
        'methods' => 'GET',
        'action' => MockController::class,
        'name' => 'home',
    ]);

    expect($Route->toArray())->toBe([
        'uri' => '/',
        'methods' => 'GET',
        'action' => MockController::class,
        'builders' => ['name' => 'home'],
    ])
        ->and($Route->collect()->get('builders'))->toBe(['name' => 'home']);
});
