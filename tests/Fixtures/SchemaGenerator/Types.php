<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator;

use Closure;
use Countable;
use Stringable;

final class Types
{
    /** @param  array<mixed>  $verbs */
    public function verbs(array $verbs): void {}

    public function scale(string|int $scale): void {}

    public function ratio(float $ratio): void {}

    public function enabled(bool $enabled): void {}

    /** @param  string|array<mixed>  $pair */
    public function pair(string|array $pair): void {}

    public function maybe(string|int|null $maybe): void {}

    /** @param  array<mixed>|null  $optional */
    public function optional(?array $optional): void {}

    public function sink(mixed $sink): void {}

    public function target(?Suit $suit): void {}

    public function callback(Closure $callback): void {}

    /** @param  iterable<mixed>  $items */
    public function flow(iterable $items): void {}

    public function shape(string|Suit $shape): void {}

    public function either((Stringable&Countable)|string $either): void {}
}
