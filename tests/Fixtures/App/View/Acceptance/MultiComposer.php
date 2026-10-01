<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Acceptance;

use Illuminate\View\View;

/** AT-05 — views.md — Attaching a Composer to Multiple Views: the doc's MultiComposer attached to profile and dashboard. */
final class MultiComposer
{
    public function compose(View $view): void
    {
        $view->with('count', 'multi');
    }
}
