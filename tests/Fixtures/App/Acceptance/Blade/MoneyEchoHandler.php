<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade;

/** The `stringable` echo handler type-hinting `Money` (AT-04) — "does not rely on __toString". */
final class MoneyEchoHandler
{
    public static function render(Money $target): string
    {
        return $target->formatTo('en_GB');
    }
}
