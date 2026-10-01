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
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Append;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\AppendTo;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Prepend;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\PrependTo;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Setter;
use ZeroToProd\LaravelDeclaration\Kernel;
use ZeroToProd\LaravelDeclaration\Manifest;

/** @internal */
class KernelDeclarationServiceProvider extends ServiceProvider
{
    public function boot(?Manifest $Manifest = null): void
    {
        if (! $Manifest?->kernel instanceof Kernel) {
            return;
        }

        $this->callAfterResolving(KernelContract::class, function (KernelContract $KernelContract) use ($Manifest): void {
            $this->registerKernel($Manifest->kernel, $KernelContract);
        });
    }

    private function registerKernel(Kernel $Kernel, KernelContract $KernelContract): void
    {
        if (! $KernelContract instanceof HttpKernel) {
            return;
        }

        foreach (Kernel::selected(Setter::class) as $method) {
            if ($Kernel->{$method} !== null) {
                $KernelContract->{$method}($Kernel->{$method});
            }
        }

        foreach (Kernel::selected(Append::class) as $method) {
            foreach ($Kernel->{$method} as $middleware) {
                $KernelContract->{$method}($middleware);
            }
        }

        foreach (Kernel::selected(Prepend::class) as $method) {
            foreach (array_reverse($Kernel->{$method}) as $middleware) {
                $KernelContract->{$method}($middleware);
            }
        }

        foreach (Kernel::selected(AppendTo::class) as $method) {
            foreach ($Kernel->{$method} as $target => $middlewares) {
                foreach ((array) $middlewares as $middleware) {
                    $KernelContract->{$method}($target, $middleware);
                }
            }
        }

        foreach (Kernel::selected(PrependTo::class) as $method) {
            foreach ($Kernel->{$method} as $target => $middlewares) {
                foreach (array_reverse((array) $middlewares) as $middleware) {
                    $KernelContract->{$method}($target, $middleware);
                }
            }
        }

        foreach ($Kernel->whenRequestLifecycleIsLongerThan as $threshold => $handler) {
            $KernelContract->whenRequestLifecycleIsLongerThan(
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
