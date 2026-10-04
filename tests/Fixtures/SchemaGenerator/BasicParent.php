<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator;

abstract class BasicParent
{
    public function inherited(string $value): void {}
}
