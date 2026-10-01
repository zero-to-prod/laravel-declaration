<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Acceptance;

use Illuminate\View\View;

/** AT-07 — views.md — View Creators: observes at render time whether the creator already bound its data to the view. */
final class CreatorOrderObserver
{
    public function compose(View $view): void
    {
        $view->with('observed', array_key_exists('greeting', $view->getData()) ? 'creator-first' : 'creator-after');
    }
}
