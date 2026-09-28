<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts\Cache;

class RedisCache implements Cache
{
    public function name(): string
    {
        return 'redis';
    }
}
