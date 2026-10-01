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
            McpServiceProvider::class,
            ViewWarmProvider::class,
            LaravelDeclarationProvider::class,
            EventSpyProvider::class,
        ];
    }

    protected function setUp(): void
    {
        $this->copyApplicationFiles();

        parent::setUp();
    }

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
            $this->copyFixtureIntoSkeleton($skeleton, $relative, __DIR__.'/Fixtures/App/Application/'.$fixture);
        }

        foreach ([
            'app/acceptance/transistor.php' => 'References/transistor.php',
            'app/acceptance/inferred.php' => 'References/inferred.php',
            'app/acceptance/decorate-store.php' => 'References/decorate-store.php',
            'app/acceptance/local-disk.php' => 'References/local-disk.php',
            'app/acceptance/s3-disk.php' => 'References/s3-disk.php',
            'app/acceptance/user-id.php' => 'References/user-id.php',
            'app/acceptance/resolve-listener.php' => 'References/resolve-listener.php',
            'app/acceptance/cache-extend.php' => 'References/cache-extend.php',
            'app/acceptance/registered.php' => 'References/registered.php',
        ] as $relative => $fixture) {
            $this->copyFixtureIntoSkeleton($skeleton, $relative, __DIR__.'/Fixtures/App/Acceptance/'.$fixture);
        }

        (new Filesystem)->copyDirectory(__DIR__.'/Fixtures/App/View/views', $skeleton.'/resources/declared-views');

        (new Filesystem)->copyDirectory(__DIR__.'/Fixtures/App/Acceptance/View/package', $skeleton.'/resources/package-views');

        (new Filesystem)->copyDirectory(__DIR__.'/Fixtures/App/Acceptance/View/vendor', $skeleton.'/resources/views/vendor');

        (new Filesystem)->copyDirectory(__DIR__.'/Fixtures/App/Acceptance/Blade/anonymous-components', $skeleton.'/resources/acceptance-components');

        (new Filesystem)->copyDirectory(__DIR__.'/Fixtures/App/Acceptance/Blade/default-components', $skeleton.'/resources/views/components');

        (new Filesystem)->copyDirectory(__DIR__.'/Fixtures/App/Acceptance/Lang', $skeleton.'/lang');
    }

    private function copyFixtureIntoSkeleton(string $skeleton, string $relative, string $fixture): void
    {
        $target = $skeleton.'/'.$relative;

        if (! is_dir($directory = dirname($target))) {
            mkdir($directory, recursive: true);
        }

        copy($fixture, $target);
    }
}
