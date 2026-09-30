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
    public function boot(Manifest $manifest): void
    {
        if (! $manifest->pagination instanceof Pagination) {
            return;
        }

        $pagination = $manifest->pagination;

        if ($pagination->useTailwind) {
            Paginator::useTailwind();
        }

        if ($pagination->useBootstrapFive) {
            Paginator::useBootstrapFive();
        }

        if ($pagination->defaultView !== null) {
            Paginator::defaultView($pagination->defaultView);
        }

        if ($pagination->defaultSimpleView !== null) {
            Paginator::defaultSimpleView($pagination->defaultSimpleView);
        }
    }
}
