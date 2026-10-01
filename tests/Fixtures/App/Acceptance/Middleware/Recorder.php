<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\MiddlewareLog;

/** The plan's generic recorder — records each request it sees; subclasses report their own name. */
class Recorder
{
    public function handle(Request $request, Closure $next): Response
    {
        MiddlewareLog::record(static::class);

        return $next($request);
    }
}
