<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Acceptance;

use Illuminate\View\View;

/** AT-02/AT-04 — views.md — View Composers: the doc's ProfileComposer — the constructor type-hints a container-resolvable dependency and compose binds the repository-derived count. */
final readonly class ProfileComposer
{
    public function __construct(private UserRepository $users) {}

    public function compose(View $view): void
    {
        $view->with('count', $this->users->count());
    }
}
