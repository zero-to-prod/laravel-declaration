<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade;

/** An object with only a `__toString` method and no `stringable` handler covering it (AT-03). */
final class PlainStringable implements \Stringable
{
    public function __toString(): string
    {
        return 'plain-string';
    }
}
