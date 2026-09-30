<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services;

/** The different concrete the conditional declarations name for `TenantContext` (AT-06) — it must not win over the provider-registered singleton. */
final class InMemoryTenantContext {}
