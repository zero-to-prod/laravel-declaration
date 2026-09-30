<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing;

use Illuminate\Routing\Events\RouteMatched;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\MiddlewareLog;

/** `matched: [InvokableMatchedListener]`: no `handle`, so the dispatcher falls back to `__invoke`. */
final class InvokableMatchedListener
{
    public function __invoke(RouteMatched $event): void
    {
        MiddlewareLog::record(self::class.'@'.$event->route->uri());
    }
}
