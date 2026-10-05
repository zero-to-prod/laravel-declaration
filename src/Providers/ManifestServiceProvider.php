<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Yaml\Yaml;
use ZeroToProd\LaravelDeclaration\Internal\ManifestStore;
use ZeroToProd\LaravelDeclaration\Internal\Resolve;
use ZeroToProd\Manifest\Interpreter;

/**
 * The only dispatch call site in the package: the manifest is a body on the application, applied in `register()`.
 * Timing is written in the manifest with the application's own lifecycle methods (`registered`, `booting`,
 * `booted`, `afterResolving`, `make`), so this provider has no `boot()`.
 *
 * @internal
 */
class ManifestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $store = new ManifestStore($this->manifest(Config::string('laravel-declaration.manifest', 'manifest/app.yml')));

        $this->app->instance(ManifestStore::class, $store);

        $interpreter = Resolve::interpreter($this->app);

        $this->app->instance(Interpreter::class, $interpreter);

        $interpreter->body($this->app, $store->body());
    }

    /** @return array<string, mixed> */
    private function manifest(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $manifest = Yaml::parseFile($file);

        return is_array($manifest) ? $manifest : [];
    }
}
