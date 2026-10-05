<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\GlobalFirstMiddleware;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\GlobalLastMiddleware;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\MiddlewareLog;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\PostSubstituteBindingsMiddleware;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\PreSubstituteBindingsMiddleware;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\SlowRequestReporter;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\TrackWebActivity;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\UltraHighPriorityMiddleware;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\UltraLowPriorityMiddleware;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\WebMaintenanceBypass;

$manifest = __DIR__.'/../Fixtures/manifest/kernel.yml';

beforeEach(function (): void {
    MiddlewareLog::reset();
});

it('prepends and pushes global middleware in declared order', function () use ($manifest): void {
    $this->withConfig([
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
        'laravel-declaration.manifest' => $manifest,
    ]);

    $response = $this->get('/kernel-test', ['X-Subscribed' => '1']);

    $response->assertOk()
        ->assertHeader('X-Global-First', '1')
        ->assertHeader('X-Global-Last', '1');

    $entries = MiddlewareLog::entries();
    $firstIndex = array_search(GlobalFirstMiddleware::class, $entries, true);
    $lastIndex = array_search(GlobalLastMiddleware::class, $entries, true);

    expect($firstIndex)->not->toBeFalse()
        ->and($lastIndex)->not->toBeFalse()
        ->and($firstIndex)->toBeLessThan($lastIndex);
});

it('prepends and appends middleware to route groups and fires terminable middleware', function () use ($manifest): void {
    $this->withConfig([
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
        'laravel-declaration.manifest' => $manifest,
    ]);

    $this->get('/kernel-test', ['X-Subscribed' => '1'])->assertOk();

    $entries = MiddlewareLog::entries();

    expect($entries)->toContain(WebMaintenanceBypass::class)
        ->and($entries)->toContain(TrackWebActivity::class)
        ->and($entries)->toContain(TrackWebActivity::class.'::terminate');
});

it('registers and enforces middleware aliases on routes', function () use ($manifest): void {
    $this->withConfig([
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
        'laravel-declaration.manifest' => $manifest,
    ]);

    $this->get('/token-test')->assertStatus(401);
    $this->get('/token-test?token=valid-token')->assertOk();

    $this->get('/kernel-test')->assertStatus(403);
    $this->get('/kernel-test', ['X-Subscribed' => '1'])->assertOk();
});

it('sorts route middleware according to declared priority order', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->get('/priority-test')->assertOk();

    $entries = MiddlewareLog::entries();
    $ultraHigh = array_search(UltraHighPriorityMiddleware::class, $entries, true);
    $preSub = array_search(PreSubstituteBindingsMiddleware::class, $entries, true);
    $postSub = array_search(PostSubstituteBindingsMiddleware::class, $entries, true);
    $ultraLow = array_search(UltraLowPriorityMiddleware::class, $entries, true);

    expect($ultraHigh)->not->toBeFalse()
        ->and($preSub)->not->toBeFalse()
        ->and($postSub)->not->toBeFalse()
        ->and($ultraLow)->not->toBeFalse()
        ->and($ultraHigh)->toBeLessThan($preSub)
        ->and($preSub)->toBeLessThan($postSub)
        ->and($postSub)->toBeLessThan($ultraLow);
});

it('invokes lifecycle duration handlers when duration exceeds threshold', function () use ($manifest): void {
    $this->withConfig([
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
        'laravel-declaration.manifest' => $manifest,
    ]);

    $this->get('/kernel-test', ['X-Subscribed' => '1'])->assertOk();
    expect(MiddlewareLog::entries())->not->toContain(SlowRequestReporter::class);

    $this->get('/slow-request')->assertOk();
    expect(MiddlewareLog::entries())->toContain(SlowRequestReporter::class);
});

it('supports a numeric threshold and a php file reference in duration handlers', function (): void {
    $reporterPath = realpath(__DIR__.'/../Fixtures/App/Middleware/slow-reporter.php');
    $file = $this->manifest(<<<YAML
        calls:
          - method: afterResolving
            args: [Illuminate\Foundation\Http\Kernel, [{method: whenRequestLifecycleIsLongerThan, args: [1, $reporterPath]}]]
          - method: afterResolving
            args:
              - Illuminate\Routing\Router
              - - method: addRoute
                  args:
                    methods: GET
                    uri: /interval-test
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\UserController@slow
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $this->get('/interval-test')->assertOk();

    expect(MiddlewareLog::entries())->toContain('slow-reporter-closure');
});

it('replaces global middleware, middleware groups, and middleware priority wholesale', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, <<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - method: setGlobalMiddleware
                  args: [[ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\GlobalFirstMiddleware]]
                - method: setMiddlewareGroups
                  args: [{custom_group: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\GlobalLastMiddleware]}]
                - method: setMiddlewarePriority
                  args:
                    - - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\UltraHighPriorityMiddleware
                      - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\UltraLowPriorityMiddleware
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    /** @var HttpKernel $kernel */
    $kernel = app(KernelContract::class);

    expect($kernel->getGlobalMiddleware())->toBe([GlobalFirstMiddleware::class])
        ->and($kernel->getMiddlewareGroups())->toMatchArray([
            'custom_group' => [GlobalLastMiddleware::class],
        ])
        ->and($kernel->getMiddlewarePriority())->toBe([
            UltraHighPriorityMiddleware::class,
            UltraLowPriorityMiddleware::class,
        ]);
});

it('supports single-string values for group and priority mutators', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, <<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - method: appendMiddlewareToGroup
                  args: [web, ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\TrackWebActivity]
                - method: prependMiddlewareToGroup
                  args: [web, ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\WebMaintenanceBypass]
                - method: addToMiddlewarePriorityBefore
                  args:
                    - Illuminate\Routing\Middleware\SubstituteBindings
                    - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\PreSubstituteBindingsMiddleware
                - method: addToMiddlewarePriorityAfter
                  args:
                    - Illuminate\Routing\Middleware\SubstituteBindings
                    - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\PostSubstituteBindingsMiddleware
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    /** @var HttpKernel $kernel */
    $kernel = app(KernelContract::class);

    $webGroup = $kernel->getMiddlewareGroups()['web'];
    expect($webGroup[0])->toBe(WebMaintenanceBypass::class)
        ->and(end($webGroup))->toBe(TrackWebActivity::class);

    $priority = $kernel->getMiddlewarePriority();
    $subIndex = array_search(SubstituteBindings::class, $priority, true);
    expect($priority[$subIndex - 1])->toBe(PreSubstituteBindingsMiddleware::class)
        ->and($priority[$subIndex + 1])->toBe(PostSubstituteBindingsMiddleware::class);
});

it('applies nothing without a kernel body', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/requests.yml']);

    /** @var HttpKernel $kernel */
    $kernel = app(KernelContract::class);

    expect($kernel->hasMiddleware(GlobalFirstMiddleware::class))->toBeFalse();
});
