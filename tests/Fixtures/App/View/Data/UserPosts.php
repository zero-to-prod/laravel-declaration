<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Data;

use ZeroToProd\LaravelDeclaration\DeclaredRequest;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\User;

final class UserPosts
{
    /** @return array{user: mixed, sort: mixed} */
    public function __invoke(User $user, DeclaredRequest $request): array
    {
        return ['user' => $user->getKey(), 'sort' => $request->validated('sort', 'created_at')];
    }
}
