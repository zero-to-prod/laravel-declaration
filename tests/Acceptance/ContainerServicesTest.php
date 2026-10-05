<?php

declare(strict_types=1);

use Illuminate\Cache\Repository;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Contracts\Filesystem;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\PhotoController;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\UploadController;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\UserController;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\CpuReport;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\DecoratedStore;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\Firewall;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\LocalDisk;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\MemoryReport;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\NullFilter;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\ProfanityFilter;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\S3Disk;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\StoreConsumer;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\Transistor;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\TransistorConsumer;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\HookLog;

beforeEach(function (): void {
    HookLog::reset();
});

// Section 3 of docs/declarative-application-acceptance-test-plan.md — service container
// extension, tagging, contextual bindings and events, sourced from
// docs/repos/laravel/docs/container.md.

// AT-10 — container.md — Extending Bindings. The consumer is resolved by the
// container, per the plan's When.
it('extends a resolved service with the declared extender', function (): void {
    $file = $this->manifest(<<<'YAML'
        registered:
          extend:
            cache.store: app/acceptance/decorate-store.php
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $decorated = app('cache.store');

    expect($decorated)->toBeInstanceOf(DecoratedStore::class)
        ->and($decorated->service)->toBeInstanceOf(Repository::class)
        ->and($decorated->container)->toBe(app())
        ->and(app(StoreConsumer::class)->store())->toBeInstanceOf(DecoratedStore::class);
});

// AT-11 — container.md — Tagging. The explicit self-concretes keep the test on the
// documented surface.
it('resolves tagged bindings together through tagged', function (): void {
    $file = $this->manifest(<<<'YAML'
        registered:
          bind:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\CpuReport: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\CpuReport
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\MemoryReport: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\MemoryReport
          tag:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\CpuReport: reports
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\MemoryReport: reports
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $reports = iterator_to_array(app()->tagged('reports'), false);

    expect($reports)->toHaveCount(2)
        ->and($reports[0])->toBeInstanceOf(CpuReport::class)
        ->and($reports[1])->toBeInstanceOf(MemoryReport::class);
});

// AT-12 — container.md — Contextual Binding.
it('injects a different implementation into each class that needs the same interface', function (): void {
    $file = $this->manifest(<<<'YAML'
        registered:
          when:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\PhotoController:
              needs: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Contracts\Filesystem
              give: app/acceptance/local-disk.php
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\UploadController:
              needs: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Contracts\Filesystem
              give: app/acceptance/s3-disk.php
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(app(PhotoController::class)->filesystem)->toBeInstanceOf(LocalDisk::class)
        ->and(app(UploadController::class)->filesystem)->toBeInstanceOf(S3Disk::class)
        ->and(app(PhotoController::class)->filesystem)->toBeInstanceOf(Filesystem::class);
});

// AT-13 — container.md — Binding Primitives. The bare integer is delivered through a .php
// reference returning a Closure, which the container unwraps and injects.
it('injects a primitive from the contextual give closure', function (): void {
    $file = $this->manifest(<<<'YAML'
        registered:
          when:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\UserController:
              needs: '$userId'
              give: app/acceptance/user-id.php
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(app(UserController::class)->userId)->toBe(7);
});

// AT-14 — container.md — Binding Typed Variadics.
it('resolves typed variadics from an array of declared class names', function (): void {
    $file = $this->manifest(<<<'YAML'
        registered:
          when:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\Firewall:
              needs: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Contracts\Filter
              give:
                - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\NullFilter
                - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\ProfanityFilter
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $firewall = app(Firewall::class);

    expect($firewall->filters)->toHaveCount(2)
        ->and($firewall->filters[0])->toBeInstanceOf(NullFilter::class)
        ->and($firewall->filters[1])->toBeInstanceOf(ProfanityFilter::class);
});

// AT-15 — container.md — Container Events.
it('fires the resolving event for each resolution before the object reaches its consumer', function (): void {
    $file = $this->manifest(<<<'YAML'
        registered:
          resolving:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\Transistor: app/acceptance/resolve-listener.php
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $first = app()->make(Transistor::class);
    $consumer = app(TransistorConsumer::class);

    expect(HookLog::entries())->toBe(['Transistor:resolving', 'Transistor:resolving'])
        ->and($first->warmed)->toBeTrue()
        ->and($consumer->transistor->warmed)->toBeTrue();
});
