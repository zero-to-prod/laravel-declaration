<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Override;
use Symfony\Component\Yaml\Yaml;
use ZeroToProd\LaravelDeclaration\Internal\Commands\GenerateManifestSchemaCommand;
use ZeroToProd\LaravelDeclaration\Internal\Commands\InstallCommand;
use ZeroToProd\LaravelDeclaration\Internal\Commands\MigrateCommand;
use ZeroToProd\LaravelDeclaration\Internal\Commands\ValidateCommand;
use ZeroToProd\LaravelDeclaration\Internal\Mcp\Server;

/** @internal */
class LaravelDeclarationProvider extends ServiceProvider
{
    public static function defaultProviders(): DefaultProviders
    {
        return new DefaultProviders;
    }

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-declaration.php', 'laravel-declaration');

        $manifest = $this->resolveManifest(Config::string('laravel-declaration.manifest', 'manifest/app.yml'));

        $this->app->instance(Manifest::class, $manifest);

        $defaultProviders = self::defaultProviders();

        /** @var list<class-string<ServiceProvider>> $providers */
        $providers = Config::get('laravel-declaration.providers') ?? $defaultProviders->providers;

        foreach ($providers as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                ValidateCommand::class,
                MigrateCommand::class,
                GenerateManifestSchemaCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/laravel-declaration.php' => config_path('laravel-declaration.php'),
            ], 'laravel-declaration-config');
        }

        if (! $this->app->environment('production')) {
            $this->registerMcpServer();
        }
    }

    private function resolveManifest(string $filename): Manifest
    {
        if (! is_file($filename)) {
            return Manifest::from();
        }

        /** @var array<string, mixed> $manifest */
        $manifest = Yaml::parseFile($filename) ?? [];

        return Manifest::from($manifest);
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
