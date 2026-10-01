<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Database\Schema\Blueprint;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class TableDefinition
{
    use DataModel;

    /** @param  array<string, list<BlueprintAction>>  $actions */
    public function __construct(
        public array $actions = []
    ) {}

    public function apply(Blueprint $table): void
    {
        foreach ($this->actions as $actions) {
            foreach ($actions as $action) {
                $action->apply($table);
            }
        }
    }

    public static function from(mixed $context = []): self
    {
        if ($context instanceof self) {
            return $context;
        }

        if (! is_array($context)) {
            return new self;
        }

        $actions = [];

        foreach ($context as $key => $value) {
            $method = (string) $key;
            BlueprintAction::validate($method);
            $items = is_array($value) && array_is_list($value) ? $value : [$value];

            foreach ($items as $item) {
                $actions[$method][] = BlueprintAction::fromDefinition($method, $item);
            }
        }

        return new self($actions);
    }
}
