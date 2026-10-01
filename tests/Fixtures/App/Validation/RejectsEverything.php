<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation;

/** extendImplicit: fails every value it is run against — a failure proves the rule ran. */
final class RejectsEverything
{
    public function validate(): bool
    {
        return false;
    }
}
