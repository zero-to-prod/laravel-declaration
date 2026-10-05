<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use Closure;

/**
 * What the migrate command reports: shared by every GuardedBlueprint the resolver creates.
 *
 * @internal
 */
final class Tally
{
    public int $created = 0;

    /** @var array<string, int> */
    public array $altered = [];

    /** @var array<string, true> */
    public array $missing = [];

    /** @param  Closure(string, string): void  $report  the two-column console detail */
    public function __construct(public readonly Closure $report) {}
}
