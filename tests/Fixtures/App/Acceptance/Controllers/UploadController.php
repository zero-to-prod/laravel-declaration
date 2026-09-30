<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Contracts\Filesystem;

/** Receives the s3 `Filesystem` implementation (AT-12) — container.md's `UploadController`. */
final readonly class UploadController
{
    public function __construct(public Filesystem $filesystem) {}
}
