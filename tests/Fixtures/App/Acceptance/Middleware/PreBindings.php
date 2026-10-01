<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The plan's `PreBindings` — records what `{user}` holds at its turn in the pipeline. */
final class PreBindings
{
    public static mixed $observed = null;

    public function handle(Request $request, Closure $next): Response
    {
        self::$observed = $request->route('user');

        return $next($request);
    }

    public static function reset(): void
    {
        self::$observed = null;
    }
}
