<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Contracts\Filesystem;

/** The s3 implementation the `UploadController` contextual binding gives (AT-12). */
final class S3Disk implements Filesystem {}
