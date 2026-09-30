<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\PodcastParser;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\PushConsumer;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\RedisEventPusher;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\Transistor;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts\Clock;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts\Pdf;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\HookLog;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\DomPdf;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\RequestLog;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\TenantContext;

beforeEach(function (): void {
    HookLog::reset();
});

// Section 2 of docs/declarative-application-acceptance-test-plan.md — service container
// bindings, sourced from docs/repos/laravel/docs/container.md.

// AT-01 — container.md — Binding Interfaces to Implementations.
it('binds the declared implementation where the interface is type-hinted', function (): void {
    $file = $this->manifest(<<<'YAML'
        app:
          bind:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Contracts\EventPusher: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\RedisEventPusher
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(app(PushConsumer::class)->pusher)->toBeInstanceOf(RedisEventPusher::class);
});

// AT-02 — container.md — Simple Bindings.
it('binds through a closure resolver that receives the container and resolves sub-dependencies', function (): void {
    $file = $this->manifest(<<<'YAML'
        app:
          bind:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\Transistor: app/acceptance/transistor.php
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $transistor = app()->make(Transistor::class);

    expect($transistor)->toBeInstanceOf(Transistor::class)
        ->and($transistor->parser)->toBeInstanceOf(PodcastParser::class);
});

// AT-03 — container.md — Simple Bindings.
it('binds the abstract inferred from the closure return type', function (): void {
    $file = $this->manifest(<<<'YAML'
        app:
          bind:
            - app/acceptance/inferred.php
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(app()->make(Transistor::class)->declared)->toBeTrue();
});

// AT-04 — container.md — Simple Bindings.
it('skips bindIf when a binding already exists', function (): void {
    $file = $this->manifest(<<<'YAML'
        app:
          bind:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts\Pdf: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\DomPdf
          bindIf:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts\Pdf: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\RedisCache
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(app(Pdf::class))->toBeInstanceOf(DomPdf::class);
});

// AT-05 — container.md — Binding A Singleton.
it('resolves a singleton one time and shares the instance', function (): void {
    $file = $this->manifest(<<<'YAML'
        app:
          singleton:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\TenantContext: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\TenantContext
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(app(TenantContext::class))->toBe(app(TenantContext::class));
});

// AT-06 — container.md — Binding A Singleton.
it('skips singletonIf when a binding already exists', function (): void {
    $file = $this->manifest(<<<'YAML'
        app:
          singleton:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\TenantContext: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\TenantContext
          singletonIf:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\TenantContext: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\RedisCache
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(app(TenantContext::class))->toBeInstanceOf(TenantContext::class)
        ->and(app(TenantContext::class))->toBe(app(TenantContext::class));
});

// AT-07 — container.md — Binding Scoped Singletons. The flush mechanism Octane/queue
// workers invoke on a new lifecycle is forgetScopedInstances().
it('shares a scoped instance within one lifecycle and flushes it at a new one', function (): void {
    $file = $this->manifest(<<<'YAML'
        app:
          scoped:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\RequestLog: ~
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $first = app(RequestLog::class);

    expect($first)->toBe(app(RequestLog::class));

    app()->forgetScopedInstances();

    expect(app(RequestLog::class))->not->toBe($first);
});

// AT-08 — container.md — Binding Scoped Singletons.
it('skips scopedIf when a binding already exists', function (): void {
    $file = $this->manifest(<<<'YAML'
        app:
          scoped:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\RequestLog: ~
          scopedIf:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\RequestLog: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\RedisCache
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(app(RequestLog::class))->toBeInstanceOf(RequestLog::class)
        ->and(app(RequestLog::class))->toBe(app(RequestLog::class));
});

// AT-09 — container.md — Binding Instances. The class-string value is make()d eagerly
// before binding, which the Clock fixture records.
it('binds an existing instance and always returns it', function (): void {
    $file = $this->manifest(<<<'YAML'
        app:
          instance:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts\Clock: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\Clock
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $clock = app(Clock::class);

    expect($clock)->toBe(app(Clock::class))
        ->and($clock)->toBe(app()->make(Clock::class))
        ->and(HookLog::entries())->toContain('Clock');
});
