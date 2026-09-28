<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use LogicException;
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

    /**
     * @throws BindingResolutionException
     *
     * @internal
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/laravel-declaration.php' => config_path('laravel-declaration.php'),
            ], 'laravel-declaration-config');
        }
        if (! App::isProduction()) {
            $this->registerMcpServer();
        }

        $Manifest = $this->resolveManifest(Config::string('laravel-declaration.manifest', 'manifest/app.yml'));

        $this->app->instance(Manifest::class, $Manifest ?? Manifest::from([Manifest::app => []]));

        if (! $Manifest instanceof Manifest) {
            return;
        }

        $this->registerProviders($Manifest);
        $this->registerRoutes($Manifest, $this->app->make(Router::class));
    }

    private function resolveManifest(string $filename): ?Manifest
    {
        if (! is_file($filename)) {
            return null;
        }

        /** @var array<string, mixed> $manifest */
        $manifest = Yaml::parseFile($filename) ?? [];

        return Manifest::from($manifest);
    }

    private function registerProviders(Manifest $Manifest): void
    {
        foreach ($Manifest->app->providers as $Provider) {
            $this->app->register($Provider->class);
        }
    }

    private function registerRoutes(Manifest $Manifest, Router $Router): void
    {
        foreach ($Manifest->app->routes as $Route) {
            $route = $Router->addRoute(
                strtoupper($Route->methods),
                $Route->path,
                $Route->action,
            );

            foreach ($Route->builders() as $method => $value) {
                match ($method) {
                    Route::fallback,
                    Route::scopeBindings,
                    Route::withoutScopedBindings,
                    Route::withoutBlocking => $value ? $route->{$method}() : null,
                    Route::can => $route->can($Route->can['ability'], $Route->can['models'] ?? []),
                    Route::block => $route->block(
                        $Route->block['lockSeconds'] ?? null,
                        $Route->block['waitSeconds'] ?? null,
                    ),
                    Route::missing => $route->missing($this->wrapMissingHandler($Route->missingHandler())),
                    default => $route->{$method}($value),
                };
            }
        }
    }

    /** @param  class-string  $handler */
    private function wrapMissingHandler(string $handler): Closure
    {
        return static function ($request, $e) use ($handler): mixed {
            $Handler = app($handler);

            if (! is_callable($Handler)) {
                throw new LogicException("The `missing` handler [{$handler}] must be invokable.");
            }

            return $Handler($request, $e);
        };
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
