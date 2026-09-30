<?php

declare(strict_types=1);

use BadMethodCallException;

it('renders a file outside the finder with the data pool, status and headers', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        routes:
          addRoute:
            - uri: legal/terms
              methods: GET
              action: ZeroToProd\LaravelDeclaration\DeclaredView
              setDefaults:
                status: 200
                headers:
                  Cache-Control: private
                data:
                  word: From Pool
                factory:
                  file: resources/declared-views/factory-standalone.php
        YAML)]);

    $this->get('/legal/terms')
        ->assertOk()
        ->assertHeader('Cache-Control', 'private')
        ->assertSeeText('From Pool');
});

it('renders a partial per item through a resolved reference', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        view:
          addLocation: [resources/declared-views]

        routes:
          addRoute:
            - uri: posts/cards
              methods: GET
              action: ZeroToProd\LaravelDeclaration\DeclaredView
              setDefaults:
                factory:
                  renderEach: [factory-row, ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Data\Posts, post]
        YAML)]);

    $this->get('/posts/cards')
        ->assertOk()
        ->assertSee('row:0:alpha')
        ->assertSee('row:1:beta');
});

it('renders the raw empty branch when the items resolve to an empty list', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        view:
          addLocation: [resources/declared-views]

        routes:
          addRoute:
            - uri: posts/empty
              methods: GET
              action: ZeroToProd\LaravelDeclaration\DeclaredView
              setDefaults:
                factory:
                  renderEach: [factory-row, ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Data\Posts@empty, post, 'raw|No rows']
        YAML)]);

    $this->get('/posts/empty')->assertOk()->assertSeeText('No rows');
});

it('renders conditionally through renderWhen with the data pool', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        view:
          addLocation: [resources/declared-views]

        routes:
          addRoute:
            - uri: when
              methods: GET
              action: ZeroToProd\LaravelDeclaration\DeclaredView
              setDefaults:
                data:
                  word: hi
                factory:
                  renderWhen: [true, factory-echo]
        YAML)]);

    $this->get('/when')->assertOk()->assertSeeText('echo:hi');
});

it('renders an empty body when the renderUnless condition holds', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        view:
          addLocation: [resources/declared-views]

        routes:
          addRoute:
            - uri: unless
              methods: GET
              action: ZeroToProd\LaravelDeclaration\DeclaredView
              setDefaults:
                factory:
                  renderUnless: [true, factory-echo]
        YAML)]);

    $this->get('/unless')->assertOk()->assertContent('');
});

it('renders the first existing view of a chain and fails with Laravel\'s own exception', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        view:
          addLocation: [resources/declared-views]

        routes:
          addRoute:
            - uri: first
              methods: GET
              action: ZeroToProd\LaravelDeclaration\DeclaredView
              setDefaults:
                data:
                  word: chained
                factory:
                  first: [[pages.missing, factory-echo]]
        YAML)]);

    $this->get('/first')->assertOk()->assertSeeText('echo:chained');
});

it('makes a view explicitly, identical to the view key', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        view:
          addLocation: [resources/declared-views]

        routes:
          addRoute:
            - uri: made
              methods: GET
              action: ZeroToProd\LaravelDeclaration\DeclaredView
              setDefaults:
                data:
                  word: made
                factory:
                  make: factory-echo
        YAML)]);

    $this->get('/made')->assertOk()->assertSeeText('echo:made');
});

it('fails an unknown Factory method with BadMethodCallException', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        routes:
          addRoute:
            - uri: typo
              methods: GET
              action: ZeroToProd\LaravelDeclaration\DeclaredView
              setDefaults:
                factory:
                  mak: factory-echo
        YAML)]);

    $this->withoutExceptionHandling();
    $this->get('/typo');
})->throws(BadMethodCallException::class, 'Method Illuminate\View\Factory::mak does not exist.');

it('fails a bool-returning dispatch loudly instead of rendering nothing', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        view:
          addLocation: [resources/declared-views]

        routes:
          addRoute:
            - uri: exists
              methods: GET
              action: ZeroToProd\LaravelDeclaration\DeclaredView
              setDefaults:
                factory:
                  exists: factory-echo
        YAML)]);

    $this->withoutExceptionHandling();
    $this->get('/exists');
})->throws(LogicException::class, 'Factory::exists() returned bool; the `factory` dispatch renders a View or a string.');

it('dispatches a registered macro with its declared arguments verbatim, without pool injection', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        routes:
          addRoute:
            - uri: macro
              methods: GET
              action: ZeroToProd\LaravelDeclaration\DeclaredView
              setDefaults:
                factory:
                  shout: hello
        YAML)]);

    app('view')->macro('shout', fn (string $word): string => "$word!");

    // A named `data` pool injection would error on a Closure without a $data parameter.
    $this->get('/macro')->assertOk()->assertSeeText('hello!');
});

it('dispatches a no-argument call for the true form', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        routes:
          addRoute:
            - uri: bare
              methods: GET
              action: ZeroToProd\LaravelDeclaration\DeclaredView
              setDefaults:
                factory:
                  ping: true
        YAML)]);

    app('view')->macro('ping', fn (): string => 'pong');

    $this->get('/bare')->assertOk()->assertSeeText('pong');
});

it('fails a factory declared as a scalar instead of a method map', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        routes:
          addRoute:
            - uri: scalar
              methods: GET
              action: ZeroToProd\LaravelDeclaration\DeclaredView
              setDefaults:
                factory: true
        YAML)]);

    $this->withoutExceptionHandling();
    $this->get('/scalar');
})->throws(LogicException::class, 'The `factory` dispatch must map a Factory method name to its arguments.');

it('fails a multi-entry factory map', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        routes:
          addRoute:
            - uri: twice
              methods: GET
              action: ZeroToProd\LaravelDeclaration\DeclaredView
              setDefaults:
                factory:
                  exists: factory-echo
                  make: factory-echo
        YAML)]);

    $this->withoutExceptionHandling();
    $this->get('/twice');
})->throws(LogicException::class, 'The `factory` dispatch accepts exactly one Factory method per render.');

it('fails a factory declared beside another render source', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        routes:
          addRoute:
            - uri: both
              methods: GET
              action: ZeroToProd\LaravelDeclaration\DeclaredView
              setDefaults:
                view: factory-echo
                factory:
                  make: factory-echo
        YAML)]);

    $this->withoutExceptionHandling();
    $this->get('/both');
})->throws(LogicException::class, "DeclaredView accepts one render source: 'template', 'view' or 'factory'.");
