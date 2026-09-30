<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers;

use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts\Pdf;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\DomPdf;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\RequestLog;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\TenantContext;

/**
 * Registers the bindings the conditional declarations must not overwrite
 * (AT-04, AT-06, AT-08) — container.md's "only if a binding has not already
 * been registered" Given, exercised through another provider's `register()`,
 * which runs before the app: block is applied in the registered() pass.
 */
final class PreBindingProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Pdf::class, DomPdf::class);
        $this->app->singleton(TenantContext::class, TenantContext::class);
        $this->app->scoped(RequestLog::class, RequestLog::class);
    }
}
