<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Acceptance;

use Illuminate\View\View;

/** AT-02 — views.md — View Composers: binds `count` like the doc's ProfileComposer. */
final class ProfileComposer
{
    public function compose(View $view): void
    {
        $view->with('count', 'composed');
    }
}
