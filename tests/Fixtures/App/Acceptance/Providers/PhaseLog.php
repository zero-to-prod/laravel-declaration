<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers;

/**
 * The providers fixtures' own phase log — the plan's "test log" is the declared
 * providers' record, not the application-wide HookLog (which harness providers
 * also write to during boot).
 */
final class PhaseLog
{
    /** @var list<string> */
    public static array $entries = [];

    public static function record(string $entry): void
    {
        self::$entries[] = $entry;
    }

    /** @return list<string> */
    public static function entries(): array
    {
        return self::$entries;
    }

    public static function reset(): void
    {
        self::$entries = [];
    }
}
