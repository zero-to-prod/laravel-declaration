<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers;

use Illuminate\Http\Request;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\FirstSnapshotter;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\LastSnapshotter;

/** Echoes both declared input snapshots and the current input for AT-02/AT-03/AT-04. */
final class SnapshotEchoController
{
    /** @return array<string, mixed> */
    public function __invoke(Request $request): array
    {
        return [
            'first' => $request->attributes->get(FirstSnapshotter::ATTRIBUTE),
            'last' => $request->attributes->get(LastSnapshotter::ATTRIBUTE),
            'input' => $request->input(),
        ];
    }
}
