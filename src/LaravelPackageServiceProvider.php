<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelPackage;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Override;
use ZeroToProd\LaravelPackage\Internal\Commands\InstallCommand;
use ZeroToProd\LaravelPackage\Internal\Mcp\Server;

/** @internal */
class LaravelPackageServiceProvider extends ServiceProvider
{
    /** @internal */
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-package.php', 'laravel-package');
    }

    /** @internal */
    public function boot(): void
    {
        $this->registerMcpServer();

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/laravel-package.php' => config_path('laravel-package.php'),
            ], 'laravel-package-config');
        }
    }

    private function registerMcpServer(): void
    {
        // @codeCoverageIgnoreStart
        if (! class_exists(Mcp::class)) {
            return;
        }
        // @codeCoverageIgnoreEnd

        if (! Config::boolean('laravel-package.mcp.enabled', true)) {
            return;
        }

        Mcp::local(Config::string('laravel-package.mcp.handle', 'laravel-package'), Server::class);
    }
}
