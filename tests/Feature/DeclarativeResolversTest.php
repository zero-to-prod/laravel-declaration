<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use ZeroToProd\LaravelDeclaration\Internal\ManifestStore;

it('stores the extra data key as YAML decoded it', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        extra:
          sitemap:
            path: /sitemap.xml
          custom:
            enabled: true
            count: 5
        YAML)]);

    expect(app(ManifestStore::class)->block('extra'))->toBe([
        'sitemap' => ['path' => '/sitemap.xml'],
        'custom' => ['enabled' => true, 'count' => 5],
    ]);
});

it('stores no extra key when the manifest declares none', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/router.yml']);

    expect(app(ManifestStore::class)->block('extra'))->toBeNull();
});

it('allows extension via a ServiceProvider the manifest registers, consuming extra', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/extra.yml']);

    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertSee('<urlset></urlset>', false);
});

it('returns early in the registered ServiceProvider if the extra key is absent', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        register:
          - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Plugins\SitemapServiceProvider
        YAML)]);

    $this->get('/sitemap.xml')->assertNotFound();
});

it('rejects a .php duration reference that does not return a Closure', function (): void {
    $php = tempnam(sys_get_temp_dir(), 'reference-').'.php';
    file_put_contents($php, '<?php return 42;');

    $file = $this->manifest(<<<YAML
        afterResolving:
          Illuminate\\Foundation\\Http\\Kernel:
            whenRequestLifecycleIsLongerThan:
              1: {$php}
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $file]);
        app(Kernel::class);
    } finally {
        unlink($php);
    }
})->throws(LogicException::class, 'must return a Closure, int returned');
