<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade;

/**
 * The documented `disk` conditional closure (AT-05, AT-06) — "checks
 * config('filesystems.default') === $value". Variadic because the declaration
 * provider forwards conditional arguments positionally through the container.
 */
final class DiskCondition
{
    public static function check(mixed ...$args): bool
    {
        return config('filesystems.default') === $args[0];
    }
}
