<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ZeroToProd\LaravelDeclaration\LaravelDeclarationProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\EventSpyProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\ViewWarmProvider;

use function Orchestra\Testbench\default_skeleton_path;

abstract class TestCase extends Orchestra
{
    /** @var array<string, mixed> */
    protected array $environmentConfig = [];

    /** @var list<string> */
    private array $tempManifests = [];

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            // Real applications discover this from laravel/mcp's composer
            // manifest. Testbench does not, so it is listed explicitly.
            McpServiceProvider::class,
            // Warms `view` only when config('laravel-declaration.warm-views') is set, so the
            // declaration block's epilogue flushes genuinely stale state (§1.1.2's immediate run).
            ViewWarmProvider::class,
            LaravelDeclarationProvider::class,
            // Its register() runs before the `app:` block is applied in the
            // registered() pass, so its LocaleUpdated listener observes the
            // dispatch setLocale() makes there.
            EventSpyProvider::class,
        ];
    }

    protected function setUp(): void
    {
        $this->copyApplicationFiles();

        parent::setUp();
    }

    /**
     * Applied right after the config files load, before any provider
     * registers — where a host's own config/*.php values sit.
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        $config = $app->make(Repository::class);

        foreach ($this->environmentConfig as $key => $value) {
            $config->set($key, $value);
        }
    }

    /** @param  array<string, mixed>  $config */
    protected function withConfig(array $config): static
    {
        $this->environmentConfig = $config;

        $this->refreshApplication();

        return $this;
    }

    /**
     * Writes a temp manifest file for `laravel-declaration.manifest`. Deleted in
     * tearDown() — the app reads it once, during the boot `withConfig()` triggers.
     */
    protected function manifest(string $yaml): string
    {
        $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
        file_put_contents($file, $yaml);

        return $this->tempManifests[] = $file;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempManifests as $file) {
            @unlink($file);
        }

        $this->tempManifests = [];

        parent::tearDown();
    }

    /**
     * Copies the `.php` files the manifest's `app:` block references, and the view
     * directories the `view:` block declares, into Testbench's skeleton: relative
     * references resolve under basePath(), which is that skeleton, not this
     * repository.
     */
    private function copyApplicationFiles(): void
    {
        $skeleton = default_skeleton_path();

        foreach ([
            'app/binders/slugger.php' => 'Binders/slugger.php',
            'app/extensions/store-cache.php' => 'Extensions/store-cache.php',
            'app/instances/limiter.php' => 'Instances/limiter.php',
            'app/hooks/registered.php' => 'Hooks/registered.php',
            'app/hooks/booted.php' => 'Hooks/booted.php',
        ] as $relative => $fixture) {
            $target = $skeleton.'/'.$relative;

            if (! is_dir($directory = dirname($target))) {
                mkdir($directory, recursive: true);
            }

            copy(__DIR__.'/Fixtures/App/Application/'.$fixture, $target);
        }

        (new Filesystem)->copyDirectory(__DIR__.'/Fixtures/App/View/views', $skeleton.'/resources/declared-views');
    }
}
