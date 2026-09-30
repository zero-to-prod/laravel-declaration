<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Data\UserPosts;

$manifest = __DIR__.'/../Fixtures/manifest/view-data.yml';

it('renders literals, references and route parameters with the declared status and headers', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->get('/users/7/posts?sort=title')
        ->assertOk()
        ->assertHeader('Cache-Control', 'private')
        ->assertSeeText('Tenant Console|Posts|7:title|70|7');
});

it('validates the declared request before any data resolves', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    app()->bind(UserPosts::class, fn () => throw new LogicException('resolved'));

    $this->getJson('/users/7/posts?sort=bogus')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sort');
});

it('binds a model the action never type-hints', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->get('/users/none/posts')->assertNotFound();
});

it('passes route parameters by name and the base request without metadata.request', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->get('/greet/ada')
        ->assertOk()
        ->assertSeeText('hello ada at greet/ada|ada|ada@example.com');
});

it('defaults data, status and headers as Router::view() does', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->get('/created')->assertCreated()->assertHeader('X-Declared', 'yes')->assertSeeText('base');
});

it('renders inline template using Blade::render with composing event', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-view-tpl-').'.yml';
    file_put_contents($file, <<<'YAML'
        routes:
          addRoute:
            - uri: "inline-template"
              methods: GET
              action: ZeroToProd\LaravelDeclaration\DeclaredView
              name: inline.template
              setDefaults:
                template: "Hello {{ $name }}"
                data:
                  name: World
                status: 201
                headers:
                  X-Custom: inline
                deleteCachedView: false
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $file]);

        $this->get('/inline-template')
            ->assertStatus(201)
            ->assertHeader('X-Custom', 'inline')
            ->assertSeeText('Hello World');
    } finally {
        unlink($file);
    }
});

it('throws LogicException when neither template nor view is specified', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-view-none-').'.yml';
    file_put_contents($file, <<<'YAML'
        routes:
          addRoute:
            - uri: "no-view"
              methods: GET
              action: ZeroToProd\LaravelDeclaration\DeclaredView
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $file]);
        $this->withoutExceptionHandling();
        $this->get('/no-view');
    } finally {
        unlink($file);
    }
})->throws(LogicException::class, "DeclaredView requires either 'template' or 'view' to be specified in setDefaults.");
