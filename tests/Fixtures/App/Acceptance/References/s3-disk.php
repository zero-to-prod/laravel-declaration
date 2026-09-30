<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\S3Disk;

// AT-12 — container.md — Contextual Binding: "->give(function () { return Storage::disk('s3'); })"
return function () {
    return new S3Disk;
};
