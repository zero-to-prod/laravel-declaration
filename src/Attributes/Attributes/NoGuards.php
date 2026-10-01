<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes\Attributes;

use Attribute;
use ZeroToProd\LaravelDeclaration\BlueprintAction;
use ZeroToProd\LaravelDeclaration\Internal\Guards;

/** @internal */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final class NoGuards extends Guards
{
    public function guards(BlueprintAction $action): array
    {
        return [];
    }
}
