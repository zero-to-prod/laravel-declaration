<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Contracts\Filesystem;

/** Receives the local `Filesystem` implementation (AT-12) — container.md's `PhotoController`. */
final readonly class PhotoController
{
    public function __construct(public Filesystem $filesystem) {}
}
