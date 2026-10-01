<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Acceptance;

use Illuminate\View\View;

/** AT-06 — views.md — Attaching a Composer to Multiple Views: the composer attached to all views through the `*` wildcard. */
final class WildcardComposer
{
    public function compose(View $view): void
    {
        $view->with('wildcard', 'wildcard');
    }
}
