<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Contracts\Filter;

/** Receives typed variadics (AT-14) — container.md's example service. */
final readonly class Firewall
{
    /** @var list<Filter> */
    public array $filters;

    public function __construct(Filter ...$filters)
    {
        $this->filters = array_values($filters);
    }
}
