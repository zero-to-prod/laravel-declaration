<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

/** @internal */
final readonly class ActionGuard
{
    /** @param  string|list<string>|null  $target */
    public function __construct(
        public GuardKind $kind,
        public string|array|null $target = null,
    ) {}
}
