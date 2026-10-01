<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\MiddlewareLog;

/** docs/repos/laravel/docs/middleware.md — Middleware Aliases, the `subscribed` example. */
final class EnsureUserIsSubscribed
{
    public function handle(Request $request, Closure $next): Response
    {
        MiddlewareLog::record(self::class);

        if (! $request->boolean('subscribed')) {
            return response('Forbidden', 403);
        }

        return $next($request);
    }
}
