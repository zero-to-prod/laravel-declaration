<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes\Attributes;

use Attribute;
use ZeroToProd\LaravelDeclaration\BlueprintAction;
use ZeroToProd\LaravelDeclaration\Internal\ActionGuard;
use ZeroToProd\LaravelDeclaration\Internal\GuardKind;
use ZeroToProd\LaravelDeclaration\Internal\Guards;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final class ForeignKeyGuards extends Guards
{
    public function guards(BlueprintAction $action): array
    {
        return [new ActionGuard(GuardKind::ForeignKeyMissing, $this->columnList($action))];
    }

    /** @return list<string> */
    private function columnList(BlueprintAction $action): array
    {
        $columns = $this->argument($action, 'columns') ?? $this->argument($action, 0);

        if (is_string($columns)) {
            return [$columns];
        }

        return is_array($columns) ? $this->strings($columns) : [];
    }
}
