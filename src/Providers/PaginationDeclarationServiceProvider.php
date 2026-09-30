<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Pagination;

/** @internal */
class PaginationDeclarationServiceProvider extends ServiceProvider
{
    public function boot(?Manifest $Manifest = null): void
    {
        if (! $Manifest?->pagination instanceof Pagination) {
            return;
        }

        if ($Manifest->pagination->useTailwind) {
            Paginator::useTailwind();
        }

        if ($Manifest->pagination->useBootstrapFive) {
            Paginator::useBootstrapFive();
        }

        if ($Manifest->pagination->defaultView !== null) {
            Paginator::defaultView($Manifest->pagination->defaultView);
        }

        if ($Manifest->pagination->defaultSimpleView !== null) {
            Paginator::defaultSimpleView($Manifest->pagination->defaultSimpleView);
        }
    }
}
