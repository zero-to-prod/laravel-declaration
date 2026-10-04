<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator;

use Closure;

final class Kinds
{
    public function group(string $name, string $middleware): void {}

    /** @param  string|array<string>  $middleware */
    public function middleware(string $group, string|array $middleware): void {}

    /**
     * @param  mixed  $key
     * @param  mixed  $binder
     */
    public function bind($key, $binder): void {}

    public function handlers(string ...$handlers): void {}

    public function ratio(float $rate, string $value): void {}

    public function register(string $name, string $class, ?Closure $callback = null): void {}
}
