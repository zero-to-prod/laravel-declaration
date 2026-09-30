<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Policies;

use Illuminate\Auth\Access\Response;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\User;

class PostPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->id === 1 ? Response::allow() : Response::deny('Only user 1 may list posts.');
    }

    public function update(User $user, string $post): bool
    {
        return $post === '1';
    }

    public function attach(User $user, mixed $post = null): bool
    {
        return $post === null || in_array($post, ['5', 5], true);
    }
}
