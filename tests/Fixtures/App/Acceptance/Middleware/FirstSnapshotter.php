<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The plan's `First` — snapshots the request input before the default global stack cleans it. */
final class FirstSnapshotter
{
    public const string ATTRIBUTE = 'acceptance-first-input';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::ATTRIBUTE, $request->input());

        return $next($request);
    }
}
