<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine;

/** A projected interface: an implementing class finds its definition through its interfaces. */
interface Contract
{
    public function define(string $ability, string $callback): void;
}
