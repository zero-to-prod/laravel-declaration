<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes\Attributes;

use Attribute;
use ZeroToProd\LaravelDeclaration\BlueprintAction;
use ZeroToProd\LaravelDeclaration\Internal\ActionGuard;
use ZeroToProd\LaravelDeclaration\Internal\GuardKind;
use ZeroToProd\LaravelDeclaration\Internal\Guards;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final class CommandGuards extends Guards
{
    public function guards(BlueprintAction $action): array
    {
        $arguments = $this->stringArguments($action);

        return match (true) {
            in_array($action->method, ['dropColumn', 'dropConstrainedForeignId', 'removeColumn'], true) && $arguments !== [] => [new ActionGuard(GuardKind::ColumnExists, $arguments)],
            in_array($action->method, ['dropPrimary', 'dropUnique', 'dropIndex', 'dropFullText', 'dropSpatialIndex', 'dropVectorIndex'], true) && $arguments !== [] => [new ActionGuard(GuardKind::IndexExists, $arguments)],
            $action->method === 'dropForeign' && $arguments !== [] => [new ActionGuard(GuardKind::ForeignKeyExists, $arguments)],
            $action->method === 'renameColumn' && count($arguments) === 2 => [new ActionGuard(GuardKind::ColumnExists, $arguments[0]), new ActionGuard(GuardKind::ColumnMissing, $arguments[1])],
            $action->method === 'renameIndex' && count($arguments) === 2 => [new ActionGuard(GuardKind::IndexExists, $arguments[0]), new ActionGuard(GuardKind::IndexMissing, $arguments[1])],
            default => [],
        };
    }
}
