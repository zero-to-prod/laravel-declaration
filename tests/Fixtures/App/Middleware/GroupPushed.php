<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Declared in `router.pushMiddlewareToGroup.tenant`; records its group position at dispatch. */
class GroupPushed
{
    public function handle(Request $request, Closure $next): Response
    {
        MiddlewareLog::record(self::class);

        return $next($request);
    }
}
