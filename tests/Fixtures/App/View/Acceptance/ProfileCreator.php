<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Acceptance;

use Illuminate\View\View;

/** AT-07 — views.md — View Creators: the doc's ProfileCreator; Laravel calls the default method `create` at make(). */
final class ProfileCreator
{
    public function create(View $view): void
    {
        $view->with('greeting', 'created');
    }
}
