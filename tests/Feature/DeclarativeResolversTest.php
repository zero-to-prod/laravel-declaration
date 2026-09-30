<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use ZeroToProd\LaravelDeclaration\DefaultProviders;
use ZeroToProd\LaravelDeclaration\LaravelDeclarationProvider;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Providers\AppDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\KernelDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\ProvidersDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\RouterDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\RoutesDeclarationServiceProvider;
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

it('boots the routes and providers subsystems without a bound Manifest', function (): void {
    // The guard early-returns of gap inventory §5.6 item 6: without a bound
    // Manifest the `providers:` and `routes:` subsystems must do nothing.
    new ProvidersDeclarationServiceProvider(app())->boot();
    new RoutesDeclarationServiceProvider(app())->boot();

    expect(app(Router::class)->getRoutes()->count())->toBe(0);
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

it('allows replacing a concern provider in configuration using DefaultProviders replace', function (): void {
    $providers = LaravelDeclarationProvider::defaultProviders()
        ->replace([RouterDeclarationServiceProvider::class => CustomRouterServiceProvider::class])
        ->toArray();

    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/router.yml',
        'laravel-declaration.providers' => $providers,
    ]);

    $router = app(Router::class);
    expect($router->getPatterns())->toHaveKey('custom_id', '[0-9]{4}')
        ->and($router->getPatterns())->not->toHaveKey('id');
});

it('disables a concern by omitting its provider using DefaultProviders except', function (): void {
    $providers = LaravelDeclarationProvider::defaultProviders()
        ->except([RouterDeclarationServiceProvider::class])
        ->toArray();

    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/router.yml',
        'laravel-declaration.providers' => $providers,
    ]);

    expect(app(Router::class)->getPatterns())->toBeEmpty();
});

it('allows merging providers into DefaultProviders', function (): void {
    $providers = LaravelDeclarationProvider::defaultProviders()
        ->merge([SitemapServiceProvider::class, SitemapServiceProvider::class])
        ->toArray();

    expect($providers)->toContain(SitemapServiceProvider::class);

    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/extra.yml',
        'laravel-declaration.providers' => $providers,
    ]);

    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertSee('<urlset></urlset>', false);
});

it('handles DefaultProviders instantiation with explicit array and non-matching replacement', function (): void {
    $custom = new DefaultProviders([RouterDeclarationServiceProvider::class]);
    expect($custom->toArray())->toBe([RouterDeclarationServiceProvider::class]);

    $unchanged = $custom->replace(['NonExistentProvider' => CustomRouterServiceProvider::class]);
    expect($unchanged->toArray())->toBe([RouterDeclarationServiceProvider::class]);
});

it('falls back to defaultProviders when config providers key is null', function (): void {
    $this->withConfig([
        'laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/router.yml',
        'laravel-declaration.providers' => null,
    ]);

    $router = app(Router::class);
    expect($router->getPatterns())->toHaveKey('id', '[0-9]+');
});
