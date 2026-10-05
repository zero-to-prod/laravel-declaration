<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine;

abstract class ArbitraryParent
{
    use Recording;

    public function inherited(string $value): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }
}
