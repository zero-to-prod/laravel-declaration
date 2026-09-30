<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
use Illuminate\Database\Schema\ForeignKeyDefinition;
use LogicException;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class ColumnDefinitionModel
{
    use DataModel;

    /**
     * @param  list<mixed>  $factoryArguments
     * @param  array<string, mixed>  $columnModifiers
     * @param  array<string, mixed>  $foreignKeyModifiers
     */
    public function __construct(
        public array $factoryArguments = [],
        public array $columnModifiers = [],
        public array $foreignKeyModifiers = [],
        public mixed $constrained = null
    ) {}

    public function apply(Blueprint $table, string $type): mixed
    {
        /** @var mixed $column */
        $column = $table->{$type}(...$this->factoryArguments);

        if ($this->constrained !== null && $column instanceof ForeignIdColumnDefinition) {
            $fk = $this->applyConstraint($column);

            foreach ($this->foreignKeyModifiers as $method => $args) {
                if ($args === true || $args === null) {
                    $fk->{$method}();
                } elseif (is_array($args) && array_is_list($args)) {
                    $fk->{$method}(...$args);
                } else {
                    $fk->{$method}($args);
                }
            }
        }

        if ($column instanceof ColumnDefinition) {
            foreach ($this->columnModifiers as $method => $args) {
                if ($args === true || $args === null) {
                    $column->{$method}();
                } elseif (is_array($args) && array_is_list($args)) {
                    $column->{$method}(...$args);
                } else {
                    $column->{$method}($args);
                }
            }
        }

        return $column;
    }

    private function applyConstraint(ForeignIdColumnDefinition $column): ForeignKeyDefinition
    {
        if ($this->constrained === true || $this->constrained === null) {
            return $column->constrained();
        }

        if (is_string($this->constrained)) {
            return $column->constrained($this->constrained);
        }

        if (is_array($this->constrained)) {
            if (array_is_list($this->constrained)) {
                return $column->constrained(...$this->constrained);
            }

            return $column->constrained(
                isset($this->constrained['table']) && is_string($this->constrained['table']) ? $this->constrained['table'] : null,
                isset($this->constrained['column']) && is_string($this->constrained['column']) ? $this->constrained['column'] : 'id',
                isset($this->constrained['indexName']) && is_string($this->constrained['indexName']) ? $this->constrained['indexName'] : null
            );
        }

        return $column->constrained();
    }

    public static function fromDefinition(string $columnType, mixed $definition): self
    {
        if (! method_exists(Blueprint::class, $columnType)) {
            throw new LogicException("Unknown Blueprint column method [{$columnType}].");
        }

        if ($definition === null) {
            return new self;
        }

        if (! is_array($definition)) {
            return new self([$definition]);
        }

        $factoryArguments = [];
        $columnModifiers = [];
        $foreignKeyModifiers = [];
        $constrained = null;

        foreach ($definition as $key => $value) {
            if ($key === 'constrained') {
                $constrained = $value;

                continue;
            }

            if (str_ends_with($key, 'OnDelete') || str_ends_with($key, 'OnUpdate') || in_array($key, ['onDelete', 'onUpdate', 'references', 'on', 'deferrable', 'initiallyImmediate'], true)) {
                $foreignKeyModifiers[$key] = $value;

                continue;
            }

            if ($key === 'name' || $key === 'column') {
                $factoryArguments[0] = $value;

                continue;
            }

            if ($key === 'args' && is_array($value)) {
                $factoryArguments = $value;

                continue;
            }

            $columnModifiers[$key] = $value;
        }

        return new self(array_values($factoryArguments), $columnModifiers, $foreignKeyModifiers, $constrained);
    }
}
