<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Acceptance;

/** AT-02/AT-04 — views.md — View Composers: the container-resolvable dependency the doc's ProfileComposer type-hints. Auto-resolved instances report a per-render varying count — the Factory builds a fresh composer per render (ManagesEvents::buildClassEventCallback), so each render's repository returns the next invocation count, which distinguishes compose re-running from replaying memoized data. */
final class UserRepository
{
    private static int $rendered = 0;

    /** AT-04 binds the repository with a fixed count so the render only shows it when the container injected the bound instance. */
    public function __construct(private readonly ?int $fixed = null) {}

    public function count(): int
    {
        return $this->fixed ?? ++self::$rendered;
    }
}
