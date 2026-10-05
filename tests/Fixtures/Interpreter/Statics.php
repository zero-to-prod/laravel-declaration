<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\Interpreter;

/** A `__call` surface and a static receiver. */
final class Statics
{
    /** @var list<array{0: string, 1: array<array-key, mixed>}> */
    public static array $calls = [];

    public static function configure(mixed $x): void
    {
        self::$calls[] = ['configure', [$x]];
    }

    /** @param  list<mixed>  $arguments */
    public function __call(string $name, array $arguments): void
    {
        self::$calls[] = [$name, $arguments];
    }
}
