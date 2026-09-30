<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Policies;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\User;

class PublishGate
{
    public function publish(User $user, string $post): bool
    {
        return $post === '1';
    }
}
