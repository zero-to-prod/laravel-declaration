<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc;

use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;

/** The doc's ComposerServiceProvider — providers.md — The Boot Method (AT-06). */
final class ComposerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        ViewFacade::composer('profile', static function (View $view): void {
            $view->with('count', 42);
        });
    }
}
