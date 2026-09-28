<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing;

/** `bind: {post: PostBinder}`: Laravel calls the default method, `bind`. */
final class PostBinder
{
    public function bind(string $value): string
    {
        return "post-$value";
    }
}
