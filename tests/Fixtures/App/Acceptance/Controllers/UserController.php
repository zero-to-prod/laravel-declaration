<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers;

/** Receives an injected primitive (AT-13) — container.md's `UserController`. */
final readonly class UserController
{
    public function __construct(public int $userId) {}
}
