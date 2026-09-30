<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services;

use Illuminate\Cache\ArrayStore;

/** The custom cache driver the booting callback registers (AT-25) — cache.md's `MongoStore`. */
final class MongoStore extends ArrayStore {}
