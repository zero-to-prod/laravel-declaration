<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Requests\Gates;

final class AllowUser
{
    public function __invoke(): bool
    {
        return true;
    }
}
