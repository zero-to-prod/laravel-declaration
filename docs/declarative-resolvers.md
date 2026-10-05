# Plain Provider Architecture for Declarative Resolvers & Extensions

> Manifest forms in this document are the pre-engine block shapes; see docs/general-purpose-migration-plan.md §2.1 and README for the current forms.

Source of truth: `vendor/laravel/framework/src/Illuminate/Foundation/Application.php` (`bootProvider()`), `vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php`, `src/Manifest.php`.

Goal: eliminate custom resolver contracts and custom middleware pipelines by using plain Laravel **Service Providers** (`Illuminate\Support\ServiceProvider`). The root **ManifestServiceProvider** binds `Manifest`, and discrete concern providers consume it via container method injection in `boot(Manifest $manifest)`.

---

## 1. Architecture Overview

### 1.1 Core Principles

1. **Zero Custom Contracts**: No `SectionResolver`, no `ManifestMiddleware`, and no custom pipeline runner classes.
2. **Plain Service Providers**: Every concern is a standard `Illuminate\Support\ServiceProvider`.
3. **Container Method Injection**: Laravel's `Application::bootProvider()` invokes `boot()` via `$this->call([$provider, 'boot'])`, automatically injecting `Manifest` and any needed framework services (`Router`, `Factory`, etc.).
4. **Pluggable & Zero-Breakage**:
   - If a provider is not registered $\rightarrow$ that concern is completely absent with zero overhead.
   - If a manifest section is absent/null $\rightarrow$ the provider returns early without error.
   - To replace a resolver $\rightarrow$ remove the default provider from configuration and register a custom provider.
   - To add a plugin $\rightarrow$ read `$manifest->extra['key']` in a custom `ServiceProvider`.

### 1.2 Lifecycle Execution Flow

```
Laravel Application Bootstrap
  │
  ├── 1. ManifestServiceProvider::register()
  │     ├── Loads manifest/app.yml -> Manifest::from(...)
  │     ├── Binds Manifest instance: $app->instance(Manifest::class, $manifest)
  │     └── Registers configured Concern Providers
  │
  ├── 2. Register Phase (Early Services)
  │     ├── ConfigDeclarationServiceProvider::register() -> Config::set()
  │     └── AppDeclarationServiceProvider::register()    -> $app->registered(...) container bindings
  │
  └── 3. Boot Phase (Booted Services via Method Injection)
        ├── RouterDeclarationServiceProvider::boot(Manifest $manifest, Router $router)
        ├── ViewDeclarationServiceProvider::boot(Manifest $manifest, Factory $view)
        ├── KernelDeclarationServiceProvider::boot(Manifest $manifest, KernelContract $kernel)
        ├── ProvidersDeclarationServiceProvider::boot(Manifest $manifest)
        ├── RoutesDeclarationServiceProvider::boot(Manifest $manifest, Router $router)
        └── [Custom Plugin]ServiceProvider::boot(Manifest $manifest, ...)
```

---

## 2. Manifest Schema Extension

Add `public array $extra = [];` to `src/Manifest.php`.

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Support\Collection;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

readonly class Manifest
{
    use DataModel;

    public const string config = 'config';

    /** @var array<string, mixed> */
    #[Describe([Describe::default => []])]
    public array $config;

    public const string app = 'app';

    #[Describe([Describe::nullable => true])]
    public ?App $app;

    public const string router = 'router';

    #[Describe([Describe::nullable => true])]
    public ?Router $router;

    public const string view = 'view';

    #[Describe([Describe::nullable => true])]
    public ?View $view;

    public const string kernel = 'kernel';

    #[Describe([Describe::nullable => true])]
    public ?Kernel $kernel;

    /** @var Collection<string, Provider> */
    #[Describe([
        Describe::cast => [self::class, 'mapOf'],
        'type' => Provider::class,
        'key_by' => Provider::name,
    ])]
    public Collection $providers;

    /** @var Collection<string, Request> */
    #[Describe([
        Describe::cast => [self::class, 'mapOf'],
        'type' => Request::class,
        'key_by' => Request::name,
    ])]
    public Collection $requests;

    /** @var Collection<string, Model> */
    #[Describe([
        Describe::cast => [self::class, 'mapOf'],
        'type' => Model::class,
        'key_by' => 'class',
    ])]
    public Collection $models;

    /** @var Collection<int, Route> */
    #[Describe([
        Describe::cast => [self::class, 'mapOf'],
        'type' => Route::class,
    ])]
    public Collection $routes;

    public const string queries = 'queries';

    /** @var Collection<string, Query> */
    #[Describe([
        Describe::cast => [self::class, 'mapOf'],
        'type' => Query::class,
        'key_by' => Query::name,
    ])]
    public Collection $queries;

    public const string extra = 'extra';

    /** @var array<string, mixed> */
    #[Describe([Describe::default => []])]
    public array $extra;
}
```

---

## 3. Root Provider: `LaravelDeclarationProvider`

`LaravelDeclarationProvider` resolves the YAML manifest file, binds `Manifest` into the container, and registers each concern provider.

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Override;
use Symfony\Component\Yaml\Yaml;
use ZeroToProd\LaravelDeclaration\Internal\Commands\InstallCommand;
use ZeroToProd\LaravelDeclaration\Internal\Commands\ValidateCommand;
use ZeroToProd\LaravelDeclaration\Internal\Mcp\Server;
use ZeroToProd\LaravelDeclaration\Providers\AppDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\ConfigDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\KernelDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\ProvidersDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\RouterDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\RoutesDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\ViewDeclarationServiceProvider;

class ManifestServiceProvider extends ServiceProvider
{
    /** @var list<class-string<ServiceProvider>> */
    protected array $providers = [
        ConfigDeclarationServiceProvider::class,
        AppDeclarationServiceProvider::class,
        RouterDeclarationServiceProvider::class,
        ViewDeclarationServiceProvider::class,
        KernelDeclarationServiceProvider::class,
        ProvidersDeclarationServiceProvider::class,
        RoutesDeclarationServiceProvider::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-declaration.php', 'laravel-declaration');

        $manifest = $this->resolveManifest(Config::string('laravel-declaration.manifest', 'manifest/app.yml'));

        // 1. Bind Manifest instance into the IoC container
        $this->app->instance(Manifest::class, $manifest);

        // 2. Register configured concern providers
        /** @var list<class-string<ServiceProvider>> $providers */
        $providers = Config::get('laravel-declaration.providers', $this->providers);

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
        if (! class_exists(Mcp::class) || ! Config::boolean('laravel-declaration.mcp.enabled', true)) {
            return;
        }

        Mcp::local(Config::string('laravel-declaration.mcp.handle', 'laravel-declaration'), Server::class);
    }
}
```

---

## 4. Discrete Concern Service Providers

Each concern provider is a plain `ServiceProvider` receiving dependencies via container method injection.

### 4.1 Router Concern: `RouterDeclarationServiceProvider`

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Manifest;

class RouterDeclarationServiceProvider extends ServiceProvider
{
    public function boot(Manifest $manifest, Router $router): void
    {
        if ($manifest->router === null) {
            return;
        }

        foreach ($manifest->router->pattern as $key => $pattern) {
            $router->pattern($key, $pattern);
        }

        foreach ($manifest->router->model as $key => $class) {
            $router->model($key, $class);
        }

        foreach ($manifest->router->bind as $key => $binder) {
            $router->bind($key, $binder);
        }
    }
}
```

### 4.2 View Concern: `ViewDeclarationServiceProvider`

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\View\Factory;
use ZeroToProd\LaravelDeclaration\Manifest;

class ViewDeclarationServiceProvider extends ServiceProvider
{
    public function boot(Manifest $manifest): void
    {
        if ($manifest->view === null) {
            return;
        }

        $view = $manifest->view;

        $this->callAfterResolving('view', function (Factory $factory) use ($view): void {
            foreach ($view->addLocation as $location) {
                $factory->addLocation($this->absolute($location));
            }

            foreach ($view->prependLocation as $location) {
                $factory->prependLocation($this->absolute($location));
            }

            foreach ($view->addNamespace as $namespace => $hints) {
                $factory->addNamespace($namespace, array_map($this->absolute(...), (array) $hints));
            }

            foreach ($view->prependNamespace as $namespace => $hints) {
                $factory->prependNamespace($namespace, array_map($this->absolute(...), (array) $hints));
            }

            foreach ($view->replaceNamespace as $namespace => $hints) {
                $factory->replaceNamespace($namespace, array_map($this->absolute(...), (array) $hints));
            }

            foreach ($view->addExtension as $extension => $engine) {
                $factory->addExtension($extension, $engine);
            }

            $factory->share($view->share);

            foreach ($view->composer as $callback => $views) {
                $factory->composer($views, $callback);
            }

            foreach ($view->creator as $callback => $views) {
                $factory->creator($views, $callback);
            }
        });
    }

    private function absolute(string $path): string
    {
        return Str::startsWith($path, ['/', '\\']) ? $path : $this->app->basePath($path);
    }
}
```

### 4.3 Routes Concern: `RoutesDeclarationServiceProvider`

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Closure;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use LogicException;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Route;

class RoutesDeclarationServiceProvider extends ServiceProvider
{
    public function boot(Manifest $manifest, Router $router): void
    {
        foreach ($manifest->routes as $Route) {
            $route = $router->addRoute(
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

    private function wrapMissingHandler(string $handler): Closure
    {
        return function ($request, $e) use ($handler): mixed {
            $handlerInstance = $this->app->make($handler);

            if (! is_callable($handlerInstance)) {
                throw new LogicException("The `missing` handler [{$handler}] must be invokable.");
            }

            return $handlerInstance($request, $e);
        };
    }
}
```

### 4.4 Kernel Concern: `KernelDeclarationServiceProvider`

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use LogicException;
use ZeroToProd\LaravelDeclaration\Kernel;
use ZeroToProd\LaravelDeclaration\Manifest;

class KernelDeclarationServiceProvider extends ServiceProvider
{
    public function boot(Manifest $manifest): void
    {
        if ($manifest->kernel === null) {
            return;
        }

        $Kernel = $manifest->kernel;

        $this->callAfterResolving(KernelContract::class, function (KernelContract $kernel) use ($Kernel): void {
            if (! $kernel instanceof HttpKernel) {
                return;
            }

            foreach (Kernel::selected(Setter::class) as $method) {
                if ($Kernel->{$method} !== null) {
                    $kernel->{$method}($Kernel->{$method});
                }
            }

            foreach (Kernel::selected(Append::class) as $method) {
                foreach ($Kernel->{$method} as $middleware) {
                    $kernel->{$method}($middleware);
                }
            }

            foreach (Kernel::selected(Prepend::class) as $method) {
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

            foreach ($Kernel->whenRequestLifecycleIsLongerThan as $threshold => $handler) {
                $kernel->whenRequestLifecycleIsLongerThan(
                    is_numeric($threshold)
                        ? +$threshold
                        : (CarbonInterval::make($threshold) ?? throw new LogicException("The `kernel.whenRequestLifecycleIsLongerThan` threshold [{$threshold}] must be numeric or a parsable interval string.")),
                    $this->wrapDurationHandler($handler)
                );
            }
        });
    }

    private function wrapDurationHandler(string $handler): Closure
    {
        return fn ($startedAt, $request, $response): mixed => $this->app->call($handler, [
            'startedAt' => $startedAt,
            'request' => $request,
            'response' => $response,
        ]);
    }
}
```

### 4.5 Config Concern: `ConfigDeclarationServiceProvider`

Configuration must apply during `register()` so other service providers see updated values:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use LogicException;
use ZeroToProd\LaravelDeclaration\Manifest;

class ConfigDeclarationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $manifest = $this->app->make(Manifest::class);

        foreach ($manifest->config as $file => $values) {
            if (! is_array($values)) {
                throw new LogicException("The `config.$file` entry must be a map of config keys.");
            }

            Config::set(Arr::prependKeysWith($values, "$file."));
        }
    }
}
```

---

## 5. Configuration Defaults: `config/laravel-declaration.php`

```php
<?php

declare(strict_types=1);

return [

    'manifest' => 'manifest/app.yml',

    /*
    |--------------------------------------------------------------------------
    | Concern Providers
    |--------------------------------------------------------------------------
    |
    | Each declaration concern is handled by a plain ServiceProvider.
    | To disable a concern, remove it from this list.
    | To replace a concern, substitute your custom ServiceProvider.
    |
    */
    'providers' => [
        ZeroToProd\LaravelDeclaration\Providers\ConfigDeclarationServiceProvider::class,
        ZeroToProd\LaravelDeclaration\Providers\AppDeclarationServiceProvider::class,
        ZeroToProd\LaravelDeclaration\Providers\RouterDeclarationServiceProvider::class,
        ZeroToProd\LaravelDeclaration\Providers\ViewDeclarationServiceProvider::class,
        ZeroToProd\LaravelDeclaration\Providers\KernelDeclarationServiceProvider::class,
        ZeroToProd\LaravelDeclaration\Providers\ProvidersDeclarationServiceProvider::class,
        ZeroToProd\LaravelDeclaration\Providers\RoutesDeclarationServiceProvider::class,
    ],

    'mcp' => [
        'enabled' => true,
        'handle' => 'laravel-declaration',
    ],
];
```

---

## 6. Extending with `extra` via Plain Service Provider

### 6.1 `manifest/app.yml`

```yaml
config:
  app:
    name: "My App"

extra:
  sitemap:
    path: /sitemap.xml
```

### 6.2 Plugin Service Provider: `SitemapServiceProvider.php`

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Manifest;

class SitemapServiceProvider extends ServiceProvider
{
    /**
     * Laravel automatically injects Manifest and Router via boot() method injection.
     */
    public function boot(Manifest $manifest, Router $router): void
    {
        if (! isset($manifest->extra['sitemap'])) {
            return; // Absent without breaking
        }

        /** @var array{path: string} $sitemap */
        $sitemap = $manifest->extra['sitemap'];

        $router->get($sitemap['path'], static fn () => response('<urlset></urlset>', 200, [
            'Content-Type' => 'application/xml',
        ]));
    }
}
```

---

## 7. Replacing or Disabling Concerns

### 7.1 Replacing a Concern Provider

1. Write a custom `ServiceProvider`:
   ```php
   namespace App\Providers;

   use Illuminate\Routing\Router;
   use Illuminate\Support\ServiceProvider;
   use ZeroToProd\LaravelDeclaration\Manifest;

   class CustomRouterServiceProvider extends ServiceProvider
   {
       public function boot(Manifest $manifest, Router $router): void
       {
           // Custom routing logic
       }
   }
   ```
2. Replace the provider in `config/laravel-declaration.php`:
   ```php
   'providers' => [
       // ...
       App\Providers\CustomRouterServiceProvider::class, // Replaces RouterDeclarationServiceProvider
       // ...
   ],
   ```

### 7.2 Disabling a Concern

Remove the provider from `config/laravel-declaration.php`. If `KernelDeclarationServiceProvider` is omitted, kernel declarations are completely ignored without error.

---

## 8. Summary Comparison

| Aspect | Custom Contract Approach | Plain Provider Approach |
|---|---|---|
| **Custom Contracts** | Required (`SectionResolver`, `Resolver`) | **Zero** (uses `Illuminate\Support\ServiceProvider`) |
| **Pipeline Overhead** | Custom `Pipeline` runner & wrappers | **None** (uses Laravel's native provider bootloader) |
| **Dependency Injection** | Manual container `$app->make()` calls | **Native method injection** in `boot(Manifest $m, ...)` |
| **Developer Learning Curve**| Must learn package-specific interfaces | **None** (standard Laravel provider idiom) |
| **Pluggability** | Custom map lookups | **Provider array** in `config/laravel-declaration.php` |
