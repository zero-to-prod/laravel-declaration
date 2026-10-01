<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Support\ServiceProvider;

/** The doc's boot dependency-injection example — providers.md — Boot Method Dependency Injection (AT-07). */
final class ResponseMacroProvider extends ServiceProvider
{
    public function boot(ResponseFactory $response): void
    {
        // The doc's `ResponseFactory` contract resolves to the Macroable factory.
        if (! $response instanceof \Illuminate\Routing\ResponseFactory) {
            throw new \LogicException('The container did not inject the routing response factory.');
        }

        $response->macro('serialized', fn (mixed $value): string => serialize($value));
    }
}
