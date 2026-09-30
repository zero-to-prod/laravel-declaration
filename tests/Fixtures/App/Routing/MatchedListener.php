<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing;

use Illuminate\Routing\Events\RouteMatched;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\MiddlewareLog;

/** `matched: [MatchedListener]`: the dispatcher's default method is `handle`. */
final class MatchedListener
{
    public function handle(RouteMatched $event): void
    {
        MiddlewareLog::record(self::class.'@'.$event->route->uri());
    }
}
