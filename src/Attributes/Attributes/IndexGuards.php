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
final class IndexGuards extends Guards
{
    public function guards(BlueprintAction $action): array
    {
        return [new ActionGuard(GuardKind::IndexMissing, $this->indexTarget($action))];
    }

    /** @return string|list<string> */
    private function indexTarget(BlueprintAction $action): string|array
    {
        if (is_string($name = $this->argument($action, 'name'))) {
            return $name;
        }

        $columns = $this->argument($action, 'columns') ?? $this->argument($action, 0);

        if (is_string($columns)) {
            return [$columns];
        }

        return is_array($columns) ? $this->strings($columns) : [];
    }
}
