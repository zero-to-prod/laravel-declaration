<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\ComposerServiceProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\Contracts\DowntimeNotifier;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\RiakServiceProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\ServerProviderConsumer;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\Services\DigitalOceanServerProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\Services\Riak\Connection;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\PhaseLog;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\RedisEventPusher;

beforeEach(function (): void {
    PhaseLog::reset();
});

// docs/declarative-providers-acceptance-test-plan.md — the manifest's `providers:` list,
// sourced from docs/repos/laravel/docs/providers.md.

// AT-01 — providers.md — Registering Providers. register/boot are the documented load
// steps observed: a provider was loaded when both ran during the boot sequence.
it('loads the declared provider — its register() and boot() both ran during the boot sequence', function (): void {
    $file = $this->manifest(<<<'YAML'
        register:
          - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\RecordingProvider
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(PhaseLog::entries())->toBe(['register', 'boot']);
});

// AT-02 — providers.md — The Register Method. The doc's RiakServiceProvider defines an
// implementation of Connection::class in the container via $this->app->singleton(...).
it('binds Connection::class through $this->app in register() and resolves the registered implementation', function (): void {
    $file = $this->manifest(<<<'YAML'
        register:
          - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\RiakServiceProvider
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $Connection = app(Connection::class);

    expect($Connection)->toBeInstanceOf(Connection::class)
        ->and($Connection->config['host'])->toBe('riak.local');
});

// AT-03 — providers.md — The `bindings` and `singletons` Properties. The provider is
// loaded with no explicit registration code; the framework checks `$bindings` and
// registers them. Injection semantics: container.md — Binding Interfaces To Implementations.
it('registers the $bindings property automatically and injects the declared implementation where the interface is type-hinted', function (): void {
    $file = $this->manifest(<<<'YAML'
        register:
          - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\BindingsPropertyProvider
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(app(ServerProviderConsumer::class)->provider)->toBeInstanceOf(DigitalOceanServerProvider::class);
});

// AT-04 — providers.md — The `bindings` and `singletons` Properties; the shared-instance
// semantics: container.md — Binding A Singleton.
it('registers the $singletons property automatically and resolves one shared instance', function (): void {
    $file = $this->manifest(<<<'YAML'
        register:
          - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\SingletonsPropertyProvider
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(app(DowntimeNotifier::class))->toBeInstanceOf(DowntimeNotifier::class)
        ->and(app(DowntimeNotifier::class))->toBe(app(DowntimeNotifier::class));
});

// AT-05 — providers.md — The Boot Method; the instantiate → all register() → all boot()
// ordering: lifecycle.md — Service Providers. The second provider's boot() resolves what
// the first provider's register() bound, and the log shows every register() before any boot().
it('calls boot() after all other providers are registered, so every binding is available', function (): void {
    $file = $this->manifest(<<<'YAML'
        register:
          - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\FirstRegisteringProvider
          - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\SecondBootingProvider
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(PhaseLog::entries())->toBe([
        'register:first',
        'register:second',
        'boot:first',
        'boot:second:'.RedisEventPusher::class,
    ]);
});

// AT-06 — providers.md — The Boot Method / Introduction. The doc's ComposerServiceProvider
// registers a view composer in boot(); the functionality is live when the view renders.
// The view surface's composer mechanism is declarative-view-acceptance-test-plan.md AT-02's
// domain — here it only evidences that boot() registrations take effect.
it('registers a view composer from boot() that runs when the view renders', function (): void {
    $file = $this->manifest(<<<'YAML'
        register:
          - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\ComposerServiceProvider
        afterResolving:
          Illuminate\View\Factory:
            addLocation:
              - resources/declared-views
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(view('profile')->render())->toBe('42');
});

// AT-07 — providers.md — Boot Method Dependency Injection. The container injects the
// type-hinted ResponseFactory into boot(); the `serialized` macro is then callable.
it('injects a type-hinted dependency into boot() — the registered macro is callable', function (): void {
    $file = $this->manifest(<<<'YAML'
        register:
          - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\ResponseMacroProvider
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(response()->serialized(['a' => 'b']))->toBe(serialize(['a' => 'b']));
});
