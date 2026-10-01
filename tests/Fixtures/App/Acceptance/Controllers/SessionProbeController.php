<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers;

use Illuminate\Http\Request;

/** Probes whether session state is available to the route for AT-11. */
final class SessionProbeController
{
    /** @return array<string, bool> */
    public function __invoke(Request $request): array
    {
        return ['hasSession' => $request->hasSession()];
    }
}
