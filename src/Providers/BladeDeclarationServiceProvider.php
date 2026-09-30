<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use ZeroToProd\LaravelDeclaration\Blade;
use ZeroToProd\LaravelDeclaration\Manifest;

/** @internal */
class BladeDeclarationServiceProvider extends ServiceProvider
{
    public function boot(Manifest $manifest): void
    {
        if (! $manifest->blade instanceof Blade) {
            return;
        }

        $bladeConfig = $manifest->blade;

        $this->callAfterResolving('blade.compiler', function (BladeCompiler $blade) use ($bladeConfig): void {
            foreach ($bladeConfig->directive as $name => $handler) {
                $blade->directive($name, fn ($expression) => $this->app->call($handler, ['expression' => $expression]));
            }

            foreach ($bladeConfig->if as $name => $callback) {
                $blade->if($name, fn (...$args) => $this->app->call($callback, $args));
            }

            foreach ($bladeConfig->component as $class => $alias) {
                $blade->component($class, $alias);
            }

            if ($bladeConfig->components !== []) {
                $blade->components($bladeConfig->components);
            }

            foreach ($bladeConfig->anonymousComponentPath as $entry) {
                $blade->anonymousComponentPath($this->app->basePath($entry['path']), $entry['prefix'] ?? null);
            }

            foreach ($bladeConfig->anonymousComponentNamespace as $entry) {
                $blade->anonymousComponentNamespace($this->app->basePath($entry['directory']), $entry['prefix'] ?? null);
            }

            foreach ($bladeConfig->stringable as $class => $callback) {
                $blade->stringable($class, fn ($target) => $this->app->call($callback, ['target' => $target]));
            }

            if ($bladeConfig->withoutDoubleEncoding) {
                $blade->withoutDoubleEncoding();
            }
        });
    }
}
