<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Hooks;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts\Clock;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\HookLog;

/**
 * An invokable `terminating` hook: Application::terminate() calls $this->call($callback)
 * with no named arguments, so everything is resolved by type-hint alone.
 */
final class FlushMetrics
{
    public function __invoke(Clock $clock): void
    {
        HookLog::record('FlushMetrics:'.$clock->now());
    }
}
