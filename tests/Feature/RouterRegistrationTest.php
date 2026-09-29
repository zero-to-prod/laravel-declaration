<?php

declare(strict_types=1);

use Illuminate\Routing\Router;

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
