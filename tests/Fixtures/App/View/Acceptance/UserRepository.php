<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Acceptance;

/** AT-04 — views.md — View Composers: the container-resolvable dependency the doc's ProfileComposer type-hints. */
final class UserRepository
{
    public function count(): int
    {
        return 42;
    }
}
