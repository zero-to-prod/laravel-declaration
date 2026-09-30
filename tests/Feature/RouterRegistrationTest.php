<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use ZeroToProd\LaravelDeclaration\Attributes\Append;
use ZeroToProd\LaravelDeclaration\Attributes\AppendTo;
use ZeroToProd\LaravelDeclaration\Attributes\Binding;
use ZeroToProd\LaravelDeclaration\Attributes\PrependTo;
use ZeroToProd\LaravelDeclaration\Attributes\Setter;
use ZeroToProd\LaravelDeclaration\Router as RouterDeclaration;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\EnsureUserIsSubscribed;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\GroupPrependedFirst;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\GroupPushed;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\MiddlewareLog;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\TenantIdentified;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockController;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\InvokableMatchedListener;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\MatchedListener;

$manifest = __DIR__.'/../Fixtures/manifest/router.yml';

it('sets every declared pattern on the router', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    expect(app(Router::class)->getPatterns())->toBe(['id' => '[0-9]+', 'account' => '[a-z]+']);
});

it('constrains the parameter on every route created afterwards', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->get('/posts/5')->assertOk();
    $this->get('/posts/abc')->assertNotFound();
});

it('lets a route where override the pattern', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->get('/slugs/abc')->assertOk();
    $this->get('/slugs/5')->assertNotFound();
});

it('constrains domain parameters', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->get('https://acme.example.com/tenants')->assertOk();
    $this->get('https://acme1.example.com/tenants')->assertNotFound();
});

it('binds a model to a parameter the action does not type-hint', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->get('/users/7')->assertExactJson(['user' => ['id' => 7]]);
    $this->get('/users/none')->assertNotFound();
});

it('lets a route missing handle a failed model binding', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->get('/missing/none')->assertNotFound()->assertExactJson(['missing' => true]);
});

it('leaves the parameter raw without SubstituteBindings', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->get('/raw/7')->assertExactJson(['user' => '7']);
});

it('forwards a class binder to its bind method', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->get('/bound/5')->assertExactJson(['post' => 'post-5']);
});

it('forwards a Class@method binder the value and the route', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->get('/teams/acme')->assertExactJson(['team' => 'acme@teams/{team}']);
});

it('sets no pattern or binder without a router block', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/requests.yml']);

    expect(app(Router::class)->getPatterns())->toBeEmpty()
        ->and(app(Router::class)->getBindingCallback('user'))->toBeNull();
});

it('ignores unknown router keys', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, <<<'YAML'
        router:
          patterns:
            id: '[0-9]+'
        YAML);

    expect($this->withConfig(['laravel-declaration.manifest' => $file]))->not->toBeNull()
        ->and(app(Router::class)->getPatterns())->toBeEmpty();
});

it('selects declaration properties via attributes', function (): void {
    expect(RouterDeclaration::selected(Binding::class))->toBe([
        RouterDeclaration::pattern,
        RouterDeclaration::model,
        RouterDeclaration::bind,
        RouterDeclaration::middlewareGroup,
        RouterDeclaration::aliasMiddleware,
    ])->and(RouterDeclaration::selected(Setter::class))->toBe([
        RouterDeclaration::singularResourceParameters,
        RouterDeclaration::resourceParameters,
        RouterDeclaration::resourceVerbs,
    ])->and(RouterDeclaration::selected(PrependTo::class))->toBe([
        RouterDeclaration::prependMiddlewareToGroup,
    ])->and(RouterDeclaration::selected(AppendTo::class))->toBe([
        RouterDeclaration::pushMiddlewareToGroup,
        RouterDeclaration::removeMiddlewareFromGroup,
    ])->and(RouterDeclaration::selected(Append::class))->toBe([
        RouterDeclaration::matched,
    ]);
});

it('registers a middleware group, its mutations and an alias on the router', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    expect(array_values(app(Router::class)->getMiddlewareGroups()['tenant']))->toBe([
        GroupPrependedFirst::class,
        TenantIdentified::class,
        GroupPushed::class,
    ])->and(app(Router::class)->getMiddleware()['subscribed'])->toBe(EnsureUserIsSubscribed::class);
});

it('expands a declared group and alias at dispatch in declared order', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    MiddlewareLog::reset();

    $this->get('/tenant', ['X-Subscribed' => '1'])->assertOk();

    expect(MiddlewareLog::entries())->toBe([
        MatchedListener::class.'@tenant',
        InvokableMatchedListener::class.'@tenant',
        GroupPrependedFirst::class,
        TenantIdentified::class,
        GroupPushed::class,
        EnsureUserIsSubscribed::class,
    ]);
});

it('enforces a declared alias without its header', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->get('/unsubscribed')->assertForbidden();
});

it('runs matched listeners before route middleware with raw parameters', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    MiddlewareLog::reset();

    $this->get('/posts/5')->assertOk();

    expect(MiddlewareLog::entries())->toContain(
        MatchedListener::class.'@posts/{id}',
        InvokableMatchedListener::class.'@posts/{id}',
    );
});

it('pluralizes, renames and localizes resource routes', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $Router = app(Router::class);
    $Router->resource('categories', MockController::class);
    $Router->resource('posts', MockController::class);

    expect($Router->getRoutes()->getByName('categories.show')->uri())->toBe('categories/{categories}')
        ->and($Router->getRoutes()->getByName('posts.show')->uri())->toBe('posts/{item}')
        ->and($Router->getRoutes()->getByName('posts.create')->uri())->toBe('posts/nuevo');
});
