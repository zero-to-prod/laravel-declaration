<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use LogicException;
use ZeroToProd\LaravelDeclaration\Attributes\Append;
use ZeroToProd\LaravelDeclaration\Attributes\Prepend;
use ZeroToProd\LaravelDeclaration\Attributes\Setter;
use ZeroToProd\LaravelDeclaration\Kernel;
use ZeroToProd\LaravelDeclaration\Manifest;

/** @internal */
class KernelDeclarationServiceProvider extends ServiceProvider
{
    public function boot(Manifest $manifest): void
    {
        if (! $manifest->kernel instanceof Kernel) {
            return;
        }

        $Kernel = $manifest->kernel;

        $this->callAfterResolving(KernelContract::class, function (KernelContract $kernel) use ($Kernel): void {
            $this->registerKernel($Kernel, $kernel);
        });
    }

    private function registerKernel(Kernel $Kernel, KernelContract $kernel): void
    {
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

        foreach ([Kernel::appendMiddlewareToGroup, Kernel::addToMiddlewarePriorityBefore] as $method) {
            foreach ($Kernel->{$method} as $target => $middlewares) {
                foreach ((array) $middlewares as $middleware) {
                    $kernel->{$method}($target, $middleware);
                }
            }
        }

        foreach ([Kernel::prependMiddlewareToGroup, Kernel::addToMiddlewarePriorityAfter] as $method) {
            foreach ($Kernel->{$method} as $target => $middlewares) {
                foreach (array_reverse((array) $middlewares) as $middleware) {
                    $kernel->{$method}($target, $middleware);
                }
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

    private function reference(string $reference): Closure|string
    {
        if (! str_ends_with($reference, '.php')) {
            return $reference;
        }

        $value = $this->fileValue($reference);

        if (! $value instanceof Closure) {
            throw new LogicException("The reference [$reference] must return a Closure, ".get_debug_type($value).' returned.');
        }

        return $value;
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
}
