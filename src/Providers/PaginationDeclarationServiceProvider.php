<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Preset;
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

        foreach (Pagination::selected(Preset::class) as $method) {
            if ($Manifest->pagination->{$method}) {
                Paginator::{$method}();
            }
        }

        if ($Manifest->pagination->defaultView !== null) {
            Paginator::defaultView($Manifest->pagination->defaultView);
        }

        if ($Manifest->pagination->defaultSimpleView !== null) {
            Paginator::defaultSimpleView($Manifest->pagination->defaultSimpleView);
        }
    }
}
