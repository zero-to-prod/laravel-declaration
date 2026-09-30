<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Contracts\Filesystem;

/** The local implementation the `PhotoController` contextual binding gives (AT-12). */
final class LocalDisk implements Filesystem {}
