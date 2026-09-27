<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
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

        /** @var array<string, mixed> $manifest */
        $manifest = Yaml::parseFile($file) ?? [];
        $Manifest = Manifest::from($manifest);

        foreach ($Manifest->app->providers as $Provider) {
            $this->app->register($Provider->class);
        }

        $this->registerManifestRoutes($Manifest->app->routes);
    }

    /**
     * @param  Collection<int, Route>  $routes
     * @throws BindingResolutionException
     */
    private function registerManifestRoutes(Collection $routes): void
    {
        $Router = $this->app->make(Router::class);

        foreach ($routes as $Route) {
            $route = $Router->addRoute(
                strtoupper($Route->methods), $Route->path, $Route->action,
            );

            foreach ($Route->builders() as $method => $value) {
                match ($method) {
                    Route::fallback,
                    Route::scopeBindings,
                    Route::withoutScopedBindings,
                    Route::withoutBlocking => $value ? $route->{$method}() : null,
                    Route::can => $route->can($Route->can['ability'], $Route->can['models'] ?? []),
                    Route::block => $route->block(
                        $Route->block['lockSeconds'] ?? null, $Route->block['waitSeconds'] ?? null,
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
