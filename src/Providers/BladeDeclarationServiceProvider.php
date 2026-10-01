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
    public function boot(?Manifest $Manifest = null): void
    {
        if (! $Manifest?->blade instanceof Blade) {
            return;
        }

        $this->callAfterResolving('blade.compiler', function (BladeCompiler $blade) use ($Manifest): void {
            foreach ($Manifest->blade->directive as $name => $handler) {
                $blade->directive($name, fn ($expression) => $this->app->call($handler, ['expression' => $expression]));
            }

            foreach ($Manifest->blade->if as $name => $callback) {
                // Conditional directives are invoked positionally by Blade::check
                // (blade.md — Custom If Statements: "Blade::if('disk', function
                // (string $value) { ... })"), so the declared callable reference
                // registers directly with Blade::if.
                $blade->if($name, $callback);
            }

            foreach ($Manifest->blade->component as $class => $alias) {
                $blade->component($class, $alias);
            }

            if ($Manifest->blade->components !== []) {
                $blade->components($Manifest->blade->components);
            }

            foreach ($Manifest->blade->anonymousComponentPath as $entry) {
                $blade->anonymousComponentPath($this->app->basePath($entry['path']), $entry['prefix'] ?? null);
            }

            foreach ($Manifest->blade->anonymousComponentNamespace as $entry) {
                $blade->anonymousComponentNamespace($this->app->basePath($entry['directory']), $entry['prefix'] ?? null);
            }

            foreach ($Manifest->blade->stringable as $class => $callback) {
                $blade->stringable($class, fn ($target) => $this->app->call($callback, ['target' => $target]));
            }

            if ($Manifest->blade->withoutDoubleEncoding) {
                $blade->withoutDoubleEncoding();
            }
        });
    }
}
