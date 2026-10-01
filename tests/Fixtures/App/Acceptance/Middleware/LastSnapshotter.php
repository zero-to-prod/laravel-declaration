<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The plan's `Last` — snapshots the request input after the default global stack cleaned it. */
final class LastSnapshotter
{
    public const string ATTRIBUTE = 'acceptance-last-input';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::ATTRIBUTE, $request->input());

        return $next($request);
    }
}
