<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware;

final class MiddlewareLog
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
