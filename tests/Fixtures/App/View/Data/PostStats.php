<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Data;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\User;

final class PostStats
{
    public function forUser(User $user): int
    {
        $id = $user->getKey();

        return is_int($id) ? $id * 10 : 0;
    }
}
