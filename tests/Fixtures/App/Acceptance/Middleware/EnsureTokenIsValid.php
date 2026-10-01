<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\MiddlewareLog;

/** docs/repos/laravel/docs/middleware.md — Defining Middleware, verbatim example. */
final class EnsureTokenIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        MiddlewareLog::record(self::class);

        if ($request->input('token') !== 'my-secret-token') {
            return redirect('/home');
        }

        return $next($request);
    }
}
