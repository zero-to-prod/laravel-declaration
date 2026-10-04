<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator;

use Closure;

final class Basic extends BasicParent
{
    public function __construct() {}

    /** @internal */
    public function internal(string $value): void {}

    public function label(string $label): void {}

    public function retries(?int $retries): void {}

    /** @param  mixed  $handler */
    public function handler($handler): void {}

    public function register(string $name, string $class, ?Closure $callback = null): void {}

    public function version(): void {}

    /** @param  array<string, mixed>  $registry */
    public function attach(array &$registry): void {}
}
