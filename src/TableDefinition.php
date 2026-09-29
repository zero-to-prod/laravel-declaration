<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
use LogicException;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Attributes\ColumnModifier;
use ZeroToProd\LaravelDeclaration\Attributes\ColumnType;
use ZeroToProd\LaravelDeclaration\Attributes\ForeignKeyModifier;
use ZeroToProd\LaravelDeclaration\Attributes\Key;
use ZeroToProd\LaravelDeclaration\Attributes\TableConstraint;
use ZeroToProd\LaravelDeclaration\Attributes\TableOption;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class TableDefinition
{
    use DataModel;

    public const string options = 'options';

    public const string columns = 'columns';

    public const string indexes = 'indexes';

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, list<ColumnDefinitionModel>>  $columns
     * @param  array<string, list<mixed>>  $indexes
     */
    public function __construct(
        #[Key, Describe([Describe::default => []])]
        public array $options = [],
        #[Key, Describe([Describe::default => []])]
        public array $columns = [],
        #[Key, Describe([Describe::default => []])]
        public array $indexes = []
    ) {}

    public static function from(mixed $context = []): self
    {
        if ($context instanceof self) {
            return $context;
        }

        if (! is_array($context)) {
            return new self;
        }

        if (isset($context[self::options]) && isset($context[self::columns]) && isset($context[self::indexes]) && count($context) === 3) {
            /** @var array<string, mixed> $options */
            $options = $context[self::options];
            /** @var array<string, list<ColumnDefinitionModel>> $columns */
            $columns = $context[self::columns];
            /** @var array<string, list<mixed>> $indexes */
            $indexes = $context[self::indexes];

            return new self($options, $columns, $indexes);
        }

        $tableOptions = [
            'engine' => true,
            'charset' => true,
            'collation' => true,
            'temporary' => true,
            'comment' => true,
        ];

        $tableConstraints = [
            'primary' => true,
            'unique' => true,
            'index' => true,
            'fullText' => true,
            'spatialIndex' => true,
            'vectorIndex' => true,
        ];

        $options = [];
        $columns = [];
        $indexes = [];

        foreach ($context as $key => $value) {
            $keyStr = (string) $key;

            if (isset($tableOptions[$keyStr])) {
                $options[$keyStr] = $value;

                continue;
            }

            if (isset($tableConstraints[$keyStr])) {
                $indexes[$keyStr] = self::normalizeIndexes($value);

                continue;
            }

            if (method_exists(Blueprint::class, $keyStr)) {
                if (is_array($value) && array_is_list($value)) {
                    $columnList = [];
                    foreach ($value as $item) {
                        $columnList[] = ColumnDefinitionModel::fromDefinition($keyStr, $item);
                    }
                    $columns[$keyStr] = $columnList;
                } else {
                    $columns[$keyStr] = [ColumnDefinitionModel::fromDefinition($keyStr, $value)];
                }

                continue;
            }

            throw new LogicException("Unknown table key or Blueprint method [{$keyStr}].");
        }

        return new self($options, $columns, $indexes);
    }

    /**
     * @return list<mixed>
     */
    private static function normalizeIndexes(mixed $value): array
    {
        if (! is_array($value)) {
            return [$value];
        }

        if (! array_is_list($value)) {
            return [$value];
        }

        return $value;
    }

    public function apply(Blueprint $table): void
    {
        $tableOptionDispatcher = new TableOption;
        $columnTypeDispatcher = new ColumnType;
        $columnModifierDispatcher = new ColumnModifier;
        $foreignKeyModifierDispatcher = new ForeignKeyModifier;
        $tableConstraintDispatcher = new TableConstraint;

        // 2. Blueprint Level Dynamic Dispatch: Table Options
        foreach ($this->options as $option => $value) {
            $tableOptionDispatcher->apply($table, $option, $value);
        }

        // 3. Blueprint Level Dynamic Dispatch: Columns
        foreach ($this->columns as $columnType => $definitions) {
            foreach ($definitions as $column) {
                $columnTarget = $columnTypeDispatcher->apply($table, $columnType, $column->factoryArguments());

                // If column factory returns void (morphs) or Collection (timestamps), skip modifier chaining
                if (! $columnTarget instanceof ColumnDefinition) {
                    continue;
                }

                // 4. ColumnDefinition Level Dynamic Dispatch: Fluent Column Modifiers
                foreach ($column->columnModifiers as $modifier => $modifierArgs) {
                    $columnModifierDispatcher->apply($columnTarget, $modifier, $modifierArgs);
                }

                // 5. Foreign Key Transition & Actions (ForeignIdColumnDefinition -> ForeignKeyDefinition)
                if ($columnTarget instanceof ForeignIdColumnDefinition && $column->isConstrained()) {
                    $foreignKey = $column->applyConstraint($columnTarget);
                    foreach ($column->foreignKeyModifiers as $modifier => $modifierArgs) {
                        $foreignKeyModifierDispatcher->apply($foreignKey, $modifier, $modifierArgs);
                    }
                }
            }
        }

        // 6. Blueprint Level Dynamic Dispatch: Table Indexes & Constraints
        foreach ($this->indexes as $indexType => $indexDefinitions) {
            foreach ($indexDefinitions as $indexArgs) {
                $tableConstraintDispatcher->apply($table, $indexType, $indexArgs);
            }
        }
    }
}
