<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts\Clock as ClockContract;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\HookLog;

/**
 * Eagerly `make()`d by the `instance` declaration — the constructor records the make,
 * proving the instance existed before boot().
 */
final class Clock implements ClockContract
{
    public function __construct()
    {
        HookLog::record('Clock');
    }

    public function now(): string
    {
        return 'now';
    }
}
