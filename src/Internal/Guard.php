<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use Illuminate\Database\Schema\Builder;

abstract class Guard
{
    /** @param  string|list<string>|null  $target */
    abstract public function passes(Builder $Builder, string $table, string|array|null $target): bool;

    /** @param  string|list<string>|null  $target */
    protected function hasColumns(Builder $Builder, string $table, string|array|null $target): bool
    {
        if (is_array($target)) {
            return $Builder->hasColumns($table, $target);
        }

        return $target !== null && $Builder->hasColumn($table, $target);
    }
}
