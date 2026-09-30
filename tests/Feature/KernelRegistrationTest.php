<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpFoundation\Response;
use ZeroToProd\LaravelDeclaration\Attributes\Append;
use ZeroToProd\LaravelDeclaration\Attributes\AppendTo;
use ZeroToProd\LaravelDeclaration\Attributes\Prepend;
use ZeroToProd\LaravelDeclaration\Attributes\PrependTo;
use ZeroToProd\LaravelDeclaration\Attributes\Setter;
use ZeroToProd\LaravelDeclaration\Kernel;
use ZeroToProd\LaravelDeclaration\Providers\KernelDeclarationServiceProvider;
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

it('supports interval string and php file references in duration handlers', function (): void {
    $reporterPath = realpath(__DIR__.'/../Fixtures/App/Middleware/slow-reporter.php');
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, <<<YAML
        kernel:
          whenRequestLifecycleIsLongerThan:
            0ms: $reporterPath
        routes:
          addRoute:
            - methods: get
              uri: /interval-test
              action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\UserController@show
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $this->get('/interval-test')->assertOk();

    expect(MiddlewareLog::entries())->toContain('slow-reporter-closure');
});

it('replaces global middleware, middleware groups, and middleware priority wholesale', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, <<<'YAML'
        kernel:
          setGlobalMiddleware:
            - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\GlobalFirstMiddleware
          setMiddlewareGroups:
            custom_group:
              - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\GlobalLastMiddleware
          setMiddlewarePriority:
            - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\UltraHighPriorityMiddleware
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
        kernel:
          appendMiddlewareToGroup:
            web: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\TrackWebActivity
          prependMiddlewareToGroup:
            web: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\WebMaintenanceBypass
          addToMiddlewarePriorityBefore:
            Illuminate\Routing\Middleware\SubstituteBindings: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\PreSubstituteBindingsMiddleware
          addToMiddlewarePriorityAfter:
            Illuminate\Routing\Middleware\SubstituteBindings: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\PostSubstituteBindingsMiddleware
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

it('ignores kernels that do not extend HttpKernel', function (): void {
    $dummy = new class implements KernelContract
    {
        public function bootstrap(): void {}

        public function handle($request): Response
        {
            return new Response;
        }

        public function terminate($request, $response): void {}

        public function getApplication(): Application
        {
            return app();
        }
    };

    $provider = new KernelDeclarationServiceProvider(app());
    $reflection = new ReflectionMethod($provider, 'registerKernel');

    $kernelModel = Kernel::from([]);
    $reflection->invoke($provider, $kernelModel, $dummy);

    expect(true)->toBeTrue();
});

it('ignores unknown kernel keys', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, <<<'YAML'
        kernel:
          unknownKey: []
        YAML);

    expect($this->withConfig(['laravel-declaration.manifest' => $file]))->not->toBeNull();
});

it('applies nothing without a kernel block', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/requests.yml']);

    /** @var HttpKernel $kernel */
    $kernel = app(KernelContract::class);

    expect($kernel->hasMiddleware(GlobalFirstMiddleware::class))->toBeFalse();
});

it('laravel-declaration:validate accepts the kernel block', function () use ($manifest): void {
    $this->artisan('laravel-declaration:validate', ['--manifest' => $manifest])
        ->assertSuccessful();
});

it('selects setter, append, prepend, appendTo, and prependTo properties via attributes', function (): void {
    expect(Kernel::selected(Setter::class))->toBe([
        Kernel::setGlobalMiddleware,
        Kernel::setMiddlewareGroups,
        Kernel::setMiddlewareAliases,
        Kernel::setMiddlewarePriority,
    ])->and(Kernel::selected(Append::class))->toBe([
        Kernel::pushMiddleware,
        Kernel::appendToMiddlewarePriority,
    ])->and(Kernel::selected(Prepend::class))->toBe([
        Kernel::prependMiddleware,
        Kernel::prependToMiddlewarePriority,
    ])->and(Kernel::selected(AppendTo::class))->toBe([
        Kernel::appendMiddlewareToGroup,
        Kernel::addToMiddlewarePriorityBefore,
    ])->and(Kernel::selected(PrependTo::class))->toBe([
        Kernel::prependMiddlewareToGroup,
        Kernel::addToMiddlewarePriorityAfter,
    ]);
});
