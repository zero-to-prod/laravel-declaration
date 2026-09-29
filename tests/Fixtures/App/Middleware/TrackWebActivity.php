<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackWebActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        MiddlewareLog::record(self::class);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        MiddlewareLog::record(self::class.'::terminate');
    }
}
