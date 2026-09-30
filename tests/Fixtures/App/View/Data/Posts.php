<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Data;

final class Posts
{
    /** @return list<string> */
    public function __invoke(): array
    {
        return ['alpha', 'beta'];
    }

    /** @return list<string> */
    public function empty(): array
    {
        return [];
    }
}
