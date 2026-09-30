<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation;

use Closure;
use Illuminate\Support\Fluent;

/** condition reference returning a Closure — passed through to ConditionalRules, which calls it
 *  natively with Fluent($data) at validator construction. */
final class ProTier
{
    public function __invoke(): Closure
    {
        return fn (Fluent $data): bool => ($data->get('tier') ?? null) === 'pro';
    }
}
