<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade;

/**
 * The documented `disk` conditional closure (AT-05, AT-06) — "checks
 * config('filesystems.default') === $value", declared with the documented
 * `string $value` parameter (blade.md — Custom If Statements).
 */
final class DiskCondition
{
    public static function check(string $value): bool
    {
        return config('filesystems.default') === $value;
    }
}
