<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class ColumnModifier
{
    public function apply(object $target, string $modifier, mixed $args): void
    {
        if ($args === true || $args === null) {
            $target->{$modifier}();
        } elseif (is_array($args) && array_is_list($args)) {
            $target->{$modifier}(...$args);
        } else {
            $target->{$modifier}($args);
        }
    }
}
