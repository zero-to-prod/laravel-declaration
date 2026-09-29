<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\View\Factory;
use Laravel\Mcp\Facades\Mcp;
use LogicException;
use Override;
use Symfony\Component\Yaml\Yaml;
use ZeroToProd\LaravelDeclaration\Internal\Commands\InstallCommand;
use ZeroToProd\LaravelDeclaration\Internal\Commands\ValidateCommand;
use ZeroToProd\LaravelDeclaration\Internal\Mcp\Server;

/** @internal */
class LaravelDeclarationProvider extends ServiceProvider
{
    /** @internal */
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-declaration.php', 'laravel-declaration');

        $Manifest = $this->resolveManifest(Config::string('laravel-declaration.manifest', 'manifest/app.yml'));

        $this->app->instance(Manifest::class, $Manifest);

        $this->registerConfig($Manifest);

        $Application = $this->app;

        assert($Application instanceof Application);

        $Application->registered(fn (Application $app) => $this->registerApplication($Manifest->app ?? App::from(), $app));
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
                ValidateCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/laravel-declaration.php' => config_path('laravel-declaration.php'),
            ], 'laravel-declaration-config');
        }
        if (! $this->app->environment('production')) {
            $this->registerMcpServer();
        }

        $Manifest = $this->app->make(Manifest::class);
        $Router = $this->app->make(Router::class);

        $this->registerRouter($Manifest, $Router);
        $this->callAfterResolving('view', fn (Factory $Factory) => $this->registerView($Manifest, $Factory));
        if ($Manifest->kernel !== null) {
            $this->callAfterResolving(
                KernelContract::class,
                fn (KernelContract $kernel) => $this->registerKernel($Manifest->kernel, $kernel)
            );
        }
        $this->registerProviders($Manifest);
        $this->registerRoutes($Manifest, $Router);
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

    private function registerConfig(Manifest $Manifest): void
    {
        foreach ($Manifest->config as $file => $values) {
            if (! is_array($values)) {
                throw new LogicException("The `config.$file` entry must be a map of config keys.");
            }

            Config::set(Arr::prependKeysWith($values, "$file."));
        }
    }

    private function registerRouter(Manifest $Manifest, Router $Router): void
    {
        foreach ($Manifest->router->pattern ?? [] as $key => $pattern) {
            $Router->pattern($key, $pattern);
        }

        foreach ($Manifest->router->model ?? [] as $key => $class) {
            $Router->model($key, $class);
        }

        foreach ($Manifest->router->bind ?? [] as $key => $binder) {
            $Router->bind($key, $binder);
        }
    }

    private function registerKernel(Kernel $Kernel, KernelContract $kernel): void
    {
        if (! $kernel instanceof HttpKernel) {
            return;
        }

        foreach ([Kernel::setGlobalMiddleware, Kernel::setMiddlewareGroups, Kernel::setMiddlewareAliases, Kernel::setMiddlewarePriority] as $method) {
            if ($Kernel->{$method} !== null) {
                $kernel->{$method}($Kernel->{$method});
            }
        }

        foreach ([Kernel::pushMiddleware, Kernel::appendToMiddlewarePriority] as $method) {
            foreach ($Kernel->{$method} as $middleware) {
                $kernel->{$method}($middleware);
            }
        }

        foreach ([Kernel::prependMiddleware, Kernel::prependToMiddlewarePriority] as $method) {
            foreach (array_reverse($Kernel->{$method}) as $middleware) {
                $kernel->{$method}($middleware);
            }
        }

        foreach ($Kernel->appendMiddlewareToGroup as $group => $middlewares) {
            foreach ((array) $middlewares as $middleware) {
                $kernel->appendMiddlewareToGroup($group, $middleware);
            }
        }

        foreach ($Kernel->prependMiddlewareToGroup as $group => $middlewares) {
            foreach (array_reverse((array) $middlewares) as $middleware) {
                $kernel->prependMiddlewareToGroup($group, $middleware);
            }
        }

        foreach ($Kernel->addToMiddlewarePriorityBefore as $before => $middlewares) {
            foreach ((array) $middlewares as $middleware) {
                $kernel->addToMiddlewarePriorityBefore($before, $middleware);
            }
        }

        foreach ($Kernel->addToMiddlewarePriorityAfter as $after => $middlewares) {
            foreach (array_reverse((array) $middlewares) as $middleware) {
                $kernel->addToMiddlewarePriorityAfter($after, $middleware);
            }
        }

        foreach ($Kernel->whenRequestLifecycleIsLongerThan as $threshold => $handler) {
            $interval = is_numeric($threshold) ? +$threshold : CarbonInterval::make($threshold);

            if ($interval === null) {
                throw new LogicException("The `kernel.whenRequestLifecycleIsLongerThan` threshold [{$threshold}] must be numeric or a parsable interval string.");
            }

            $kernel->whenRequestLifecycleIsLongerThan($interval, $this->wrapDurationHandler($handler));
        }
    }

    private function wrapDurationHandler(string $handler): Closure
    {
        $callback = $this->reference($handler);

        return fn ($startedAt, $request, $response): mixed => $this->app->call($callback, [
            'startedAt' => $startedAt,
            'request' => $request,
            'response' => $response,
        ]);
    }

    private function registerView(Manifest $Manifest, Factory $Factory): void
    {
        foreach ($Manifest->view->addLocation ?? [] as $location) {
            $Factory->addLocation($this->absolute($location));
        }

        foreach ($Manifest->view->prependLocation ?? [] as $location) {
            $Factory->prependLocation($this->absolute($location));
        }

        foreach ($Manifest->view->addNamespace ?? [] as $namespace => $hints) {
            $Factory->addNamespace($namespace, array_map($this->absolute(...), (array) $hints));
        }

        foreach ($Manifest->view->prependNamespace ?? [] as $namespace => $hints) {
            $Factory->prependNamespace($namespace, array_map($this->absolute(...), (array) $hints));
        }

        foreach ($Manifest->view->replaceNamespace ?? [] as $namespace => $hints) {
            $Factory->replaceNamespace($namespace, array_map($this->absolute(...), (array) $hints));
        }

        foreach ($Manifest->view->addExtension ?? [] as $extension => $engine) {
            $Factory->addExtension($extension, $engine);
        }

        $Factory->share($Manifest->view->share ?? []);

        foreach ($Manifest->view->composer ?? [] as $callback => $views) {
            $Factory->composer($views, $callback);
        }

        foreach ($Manifest->view->creator ?? [] as $callback => $views) {
            $Factory->creator($views, $callback);
        }
    }

    private function registerProviders(Manifest $Manifest): void
    {
        foreach ($Manifest->providers as $Provider) {
            $this->app->register($Provider->class);
        }
    }

    private function registerApplication(App $App, Application $app): void
    {
        foreach ([App::bind, App::bindIf, App::singleton, App::singletonIf, App::scoped, App::scopedIf] as $method) {
            foreach ($App->{$method} as $abstract => $concrete) {
                is_string($abstract)
                    ? $app->{$method}($abstract, $this->concrete($concrete))
                    : $app->{$method}($this->concrete($concrete) ?? throw new LogicException("The `app.$method` list declares a null item; every list item is an abstract."));
            }
        }

        foreach ($App->instance as $abstract => $value) {
            $app->instance($abstract, $this->instantiate($value));
        }

        foreach ($App->alias as $abstract => $alias) {
            $app->alias($abstract, $alias);
        }

        foreach ($App->extend as $abstract => $reference) {
            $app->extend($abstract, $this->wrapExtender($reference));
        }

        foreach ([App::useAppPath, App::useDatabasePath, App::useLangPath, App::usePublicPath, App::useStoragePath] as $method) {
            if (($path = $App->{$method}) !== null) {
                $app->{$method}($this->absolute($path));
            }
        }

        if ($App->setLocale !== null) {
            $app->setLocale($App->setLocale);
        }

        if ($App->setFallbackLocale !== null) {
            $app->setFallbackLocale($App->setFallbackLocale);
        }

        foreach ($App->registered as $reference) {
            $app->registered($this->wrapCallback($reference));
        }
        foreach ($App->booting as $reference) {
            $app->booting($this->wrapCallback($reference));
        }
        foreach ($App->booted as $reference) {
            $app->booted($this->wrapCallback($reference));
        }
        foreach ($App->terminating as $reference) {
            $app->terminating($this->reference($reference));
        }
    }

    private function concrete(?string $reference): Closure|string|null
    {
        return $reference === null ? null : $this->reference($reference);
    }

    private function reference(string $reference): Closure|string
    {
        if (! str_ends_with($reference, '.php')) {
            return $reference;
        }

        $value = $this->fileValue($reference);

        if (! $value instanceof Closure) {
            throw new LogicException("The `app` reference [$reference] must return a Closure, ".get_debug_type($value).' returned.');
        }

        return $value;
    }

    private function instantiate(mixed $value): mixed
    {
        if (is_string($value) && str_ends_with($value, '.php')) {
            return $this->fileValue($value);
        }

        return is_string($value) && (class_exists($value) || interface_exists($value)) ? $this->app->make($value) : $value;
    }

    private function wrapExtender(string $reference): Closure
    {
        $value = $this->reference($reference);

        return static fn (mixed $instance, Application $app): mixed => $app->call($value, ['instance' => $instance, 'app' => $app]);
    }

    private function wrapCallback(string $reference): Closure
    {
        $value = $this->reference($reference);

        return static fn (Application $app): mixed => $app->call($value, ['app' => $app]);
    }

    private function absolute(string $path): string
    {
        return Str::startsWith($path, ['/', '\\']) ? $path : $this->app->basePath($path);
    }

    private function fileValue(string $file): mixed
    {
        static $loaded = [];

        $path = $this->absolute($file);

        return $loaded[$path] ??= require $path;
    }

    private function registerRoutes(Manifest $Manifest, Router $Router): void
    {
        foreach ($Manifest->routes as $Route) {
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
