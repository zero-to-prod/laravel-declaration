<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Hooks;

use Illuminate\Foundation\Application;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\HookLog;

/**
 * An invokable `booting` hook: resolved through Container::call with the named `app` argument.
 * Records whether the app had booted, proving it fires first in boot().
 */
final class WarmConnections
{
    public function __invoke(Application $app): void
    {
        HookLog::record('WarmConnections:'.($app->isBooted() ? 'booted' : 'booting'));
    }
}
