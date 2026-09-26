<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Override;
use Symfony\Component\Yaml\Yaml;
use ZeroToProd\LaravelDeclaration\Internal\Commands\InstallCommand;
use ZeroToProd\LaravelDeclaration\Internal\Mcp\Server;

/** @internal */
class LaravelDeclarationProvider extends ServiceProvider
{
    /** @internal */
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-declaration.php', 'laravel-declaration');
    }

    /** @internal */
    public function boot(): void
    {
        $this->registerManifestProviders();
        $this->registerMcpServer();

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/laravel-declaration.php' => config_path('laravel-declaration.php'),
            ], 'laravel-declaration-config');
        }
    }

    private function registerManifestProviders(): void
    {
        $file = Config::string('laravel-declaration.manifest', 'manifest/app.yml');

        if (! is_file($file)) {
            return;
        }

        /** @var array<string> $manifest */
        $manifest = Yaml::parseFile($file) ?? [];
        foreach (Manifest::from($manifest)->app->providers as $Provider) {
            $this->app->register($Provider->class);
        }
    }

    private function registerMcpServer(): void
    {
        // @codeCoverageIgnoreStart
        if (! class_exists(Mcp::class)) {
            return;
        }
        // @codeCoverageIgnoreEnd

        if (! Config::boolean('laravel-declaration.mcp.enabled', true)) {
            return;
        }

        Mcp::local(Config::string('laravel-declaration.mcp.handle', 'laravel-declaration'), Server::class);
    }
}
