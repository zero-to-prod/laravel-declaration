<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Zerotoprod\DataModel\PropertyRequiredException;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Route;
use ZeroToProd\LaravelDeclaration\RouteGroup;
use ZeroToProd\LaravelDeclaration\RouteResource;
use ZeroToProd\LaravelDeclaration\Routes;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockController;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\UserController;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\UserMissingHandler;

it('defaults to no routes', function (): void {
    expect(Manifest::from([])->routes->addRoute)->toBeEmpty();
});

it('hydrates an empty routes block', function (): void {
    expect(Manifest::from([])->routes->addRoute)->toBeEmpty()->and(Manifest::from([])->routes->group)->toBeEmpty();
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

    $addRoute = $manifest->routes->addRoute;

    expect($addRoute)->toHaveCount(7)
        ->and($addRoute[0]->uri)->toBe('/')
        ->and($addRoute[0]->methods)->toBe('GET')
        ->and($addRoute[0]->action)->toBe(
            MockController::class,
        )
        ->and($addRoute[0]->builders)->toBe(['name' => 'home'])
        ->and($addRoute[5]->builders['can'])->toBe(
            ['ability' => 'view', 'models' => 'user'],
        )
        ->and($addRoute[6]->builders)->toBe([
            'where' => ['any' => '.*'],
            'fallback' => true,
        ]);
});

it('hydrates a routes block of registrars', function (): void {
    $Routes = Routes::from([
        'addRoute' => [
            ['uri' => '/', 'methods' => 'GET', 'action' => MockController::class, 'name' => 'home'],
        ],
        'group' => [
            [
                'prefix' => 'admin',
                'as' => 'admin.',
                'namespace' => 'App\\Http\\Controllers\\Admin',
                'domain' => '{account}.example.com',
                'controller' => 'Controller',
                'middleware' => ['web'],
                'where' => ['id' => '[0-9]+'],
                'metadata' => ['area' => 'admin'],
                'routes' => [
                    'addRoute' => [
                        ['uri' => 'dashboard', 'methods' => 'GET', 'action' => 'DashboardController'],
                    ],
                ],
            ],
        ],
        'resource' => [
            ['name' => 'photos', 'controller' => 'PhotoController', 'options' => ['only' => ['index']]],
        ],
        'view' => [
            ['uri' => 'about', 'view' => 'pages.about', 'data' => ['title' => 'About'], 'headers' => ['X-Frame-Options' => 'DENY']],
        ],
        'redirect' => [
            ['uri' => 'old', 'destination' => 'new', 'status' => 301],
        ],
        'permanentRedirect' => [
            ['uri' => 'legacy', 'destination' => '/'],
        ],
    ]);

    expect(array_keys($Routes->registrars()))->toBe(['addRoute', 'group', 'resource', 'view', 'redirect', 'permanentRedirect'])
        ->and($Routes->addRoute[0])->toBeInstanceOf(Route::class)
        ->and($Routes->group[0])->toBeInstanceOf(RouteGroup::class)
        ->and($Routes->group[0]->attributes())->toBe([
            'prefix' => 'admin',
            'as' => 'admin.',
            'namespace' => 'App\\Http\\Controllers\\Admin',
            'domain' => '{account}.example.com',
            'controller' => 'Controller',
            'middleware' => ['web'],
            'where' => ['id' => '[0-9]+'],
            'metadata' => ['area' => 'admin'],
        ])
        ->and($Routes->group[0]->routes->addRoute[0]->uri)->toBe('dashboard')
        ->and($Routes->resource[0])->toBeInstanceOf(RouteResource::class)
        ->and($Routes->resource[0]->options)->toBe(['only' => ['index']])
        ->and($Routes->view[0]->arguments())->toBe(['about', 'pages.about', ['title' => 'About'], 200, ['X-Frame-Options' => 'DENY']])
        ->and($Routes->view[0]->builders)->toBeEmpty()
        ->and($Routes->redirect[0]->arguments())->toBe(['old', 'new', 301])
        ->and($Routes->permanentRedirect[0]->arguments())->toBe(['legacy', '/']);
});

it('defaults the group attributes to empty and the routes to empty Routes', function (): void {
    $Group = RouteGroup::from([]);

    expect($Group->attributes())->toBeEmpty()
        ->and($Group->routes->addRoute)->toBeEmpty()
        ->and(RouteResource::from(['name' => 'photos', 'controller' => 'PhotoController'])->options)->toBeEmpty();
});

it('treats a non-list registrar value as empty', function (): void {
    expect(Routes::from(['addRoute' => 'placeholder'])->addRoute)->toBeEmpty()
        ->and(Manifest::from(['routes' => 'placeholder'])->routes->addRoute)->toBeEmpty();
});

it('requires a resource name and controller', function (): void {
    RouteResource::from(['name' => 'photos']);
})->throws(PropertyRequiredException::class);

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
