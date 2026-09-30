<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services;

/**
 * The closure-built service of AT-02, AT-03 and AT-15 — container.md's example service.
 * `declared` and `warmed` are marks the declared closures set when they run.
 */
final class Transistor
{
    public bool $declared = false;

    public bool $warmed = false;

    public function __construct(public readonly PodcastParser $parser) {}
}
