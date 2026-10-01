<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Acceptance;

use Illuminate\View\View;

/** AT-04 — views.md — View Composers: "you may type-hint any dependencies you need within a composer's constructor". */
final readonly class ContainerComposer
{
    public function __construct(private UserRepository $users) {}

    public function compose(View $view): void
    {
        $view->with('count', $this->users->count());
    }
}
