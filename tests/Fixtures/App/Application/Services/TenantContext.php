<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services;

class TenantContext
{
    public function tenant(): string
    {
        return 'tenant';
    }
}
