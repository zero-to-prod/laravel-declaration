<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Models\User;

/** Receives the implicitly bound `{user}` model for AT-14/AT-15. */
final class ShowUserController
{
    /** @return array<string, mixed> */
    public function __invoke(User $user): array
    {
        return ['email' => $user->email];
    }
}
