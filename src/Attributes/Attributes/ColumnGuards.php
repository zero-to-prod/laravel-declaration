<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes\Attributes;

use Attribute;
use ZeroToProd\LaravelDeclaration\BlueprintAction;
use ZeroToProd\LaravelDeclaration\Internal\ActionGuard;
use ZeroToProd\LaravelDeclaration\Internal\GuardKind;
use ZeroToProd\LaravelDeclaration\Internal\Guards;

/** @internal */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final class ColumnGuards extends Guards
{
    public function guards(BlueprintAction $action): array
    {
        $column = $this->argument($action, 'column')
            ?? $this->argument($action, 'name')
            ?? self::CANONICAL_COLUMNS[$action->method]
            ?? $this->morphTarget($action);

        if (! is_string($column)) {
            return [new ActionGuard(GuardKind::None)];
        }

        $exists = ($action->modifiers['change'] ?? false) !== false;

        return [new ActionGuard($exists ? GuardKind::ColumnExists : GuardKind::ColumnMissing, $column)];
    }
}
