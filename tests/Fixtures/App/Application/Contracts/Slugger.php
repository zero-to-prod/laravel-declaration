<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts;

interface Slugger
{
    public function slug(string $value): string;
}
