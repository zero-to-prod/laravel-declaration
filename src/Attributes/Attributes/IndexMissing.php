<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes\Attributes;

use Attribute;
use Illuminate\Database\Schema\Builder;
use ZeroToProd\LaravelDeclaration\Internal\Guard;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final class IndexMissing extends Guard
{
    /** @param  string|list<string>|null  $target */
    public function passes(Builder $Builder, string $table, string|array|null $target): bool
    {
        return ! $Builder->hasIndex($table, $target ?? []);
    }
}
