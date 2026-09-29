<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use ZeroToProd\LaravelDeclaration\LaravelDeclarationProvider;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Providers\AppDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\KernelDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\ProvidersDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\RouterDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\ViewDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Plugins\CustomRouterServiceProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Plugins\SitemapServiceProvider;

it('defaults extra to empty array when absent', function (): void {
    $manifest = Manifest::from([]);

    expect($manifest->extra)->toBeEmpty();
});

it('populates extra from manifest', function (): void {
    $manifest = Manifest::from([
        'extra' => [
            'sitemap' => ['path' => '/sitemap.xml'],
            'custom' => ['enabled' => true, 'count' => 5],
        ],
    ]);

    expect($manifest->extra)->toBe([
        'sitemap' => ['path' => '/sitemap.xml'],
        'custom' => ['enabled' => true, 'count' => 5],
    ]);
});

it('allows extension via custom ServiceProvider consuming extra', function (): void {
    /** @var list<class-string> $defaultProviders */
    $defaultProviders = config('laravel-declaration.providers');

    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/extra.yml',
        'laravel-declaration.providers' => [
            ...$defaultProviders,
            SitemapServiceProvider::class,
        ],
    ]);

    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertSee('<urlset></urlset>', false);
});

it('returns early in custom ServiceProvider if extra key is absent', function (): void {
    /** @var list<class-string> $defaultProviders */
    $defaultProviders = config('laravel-declaration.providers');

    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/router.yml',
        'laravel-declaration.providers' => [
            ...$defaultProviders,
            SitemapServiceProvider::class,
        ],
    ]);

    $this->get('/sitemap.xml')->assertNotFound();
});

it('allows replacing a concern provider in configuration', function (): void {
    /** @var list<class-string> $defaultProviders */
    $defaultProviders = config('laravel-declaration.providers');

    $providers = array_values(array_filter(
        $defaultProviders,
        static fn (string $provider): bool => $provider !== RouterDeclarationServiceProvider::class
    ));
    $providers[] = CustomRouterServiceProvider::class;

    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/router.yml',
        'laravel-declaration.providers' => $providers,
    ]);

    $router = app(Router::class);
    expect($router->getPatterns())->toHaveKey('custom_id', '[0-9]{4}')
        ->and($router->getPatterns())->not->toHaveKey('id');
});

it('disables a concern by omitting its provider from configuration', function (): void {
    /** @var list<class-string> $defaultProviders */
    $defaultProviders = config('laravel-declaration.providers');

    $providers = array_values(array_filter(
        $defaultProviders,
        static fn (string $provider): bool => $provider !== RouterDeclarationServiceProvider::class
    ));

    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/router.yml',
        'laravel-declaration.providers' => $providers,
    ]);

    expect(app(Router::class)->getPatterns())->toBeEmpty();
});

it('handles null manifest sections gracefully across all providers', function (): void {
    $manifest = Manifest::from([]);
    app()->instance(Manifest::class, $manifest);

    $appProvider = new AppDeclarationServiceProvider(app());
    $appProvider->register();

    $routerProvider = new RouterDeclarationServiceProvider(app());
    $routerProvider->boot($manifest, app(Router::class));

    $viewProvider = new ViewDeclarationServiceProvider(app());
    $viewProvider->boot($manifest);

    $kernelProvider = new KernelDeclarationServiceProvider(app());
    $kernelProvider->boot($manifest);

    $providersProvider = new ProvidersDeclarationServiceProvider(app());
    $providersProvider->boot($manifest);

    expect(true)->toBeTrue();
});

it('rejects a .php duration reference that does not return a Closure', function (): void {
    $php = tempnam(sys_get_temp_dir(), 'reference-').'.php';
    file_put_contents($php, '<?php return 42;');

    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, <<<YAML
        kernel:
          whenRequestLifecycleIsLongerThan:
            0ms: {$php}
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $file]);
        app(Kernel::class);
    } finally {
        unlink($php);
        unlink($file);
    }
})->throws(LogicException::class, 'must return a Closure, int returned');

it('allows legacy LaravelDeclarationProvider to be used', function (): void {
    $legacyProvider = new LaravelDeclarationProvider(app());
    expect($legacyProvider)->toBeInstanceOf(LaravelDeclarationProvider::class);
});
