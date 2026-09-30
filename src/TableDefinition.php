<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Database\Schema\Blueprint;
use LogicException;
use Zerotoprod\DataModel\Describe;
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
        #[Describe([Describe::default => []])]
        public array $options = [],
        #[Describe([Describe::default => []])]
        public array $columns = [],
        #[Describe([Describe::default => []])]
        public array $indexes = []
    ) {}

    public function apply(Blueprint $table): void
    {
        foreach ($this->options as $option => $value) {
            if (property_exists($table, $option)) {
                $table->{$option} = $value;
            } elseif (method_exists($table, $option)) {
                $table->{$option}($value);
            }
        }

        foreach ($this->columns as $type => $columnModels) {
            foreach ($columnModels as $columnModel) {
                $columnModel->apply($table, $type);
            }
        }

        foreach ($this->indexes as $indexMethod => $indexDefinitions) {
            foreach ($indexDefinitions as $definition) {
                if (is_array($definition) && array_is_list($definition)) {
                    if (isset($definition[0]) && is_array($definition[0])) {
                        $table->{$indexMethod}(...$definition);
                    } else {
                        $table->{$indexMethod}($definition);
                    }
                } else {
                    $table->{$indexMethod}($definition);
                }
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

        if (isset($context[self::options], $context[self::columns], $context[self::indexes]) && count($context) === 3 && is_array($context[self::options]) && is_array($context[self::columns]) && is_array($context[self::indexes])) {
            $options = [];
            foreach ($context[self::options] as $k => $v) {
                $options[(string) $k] = $v;
            }
            $columns = [];
            foreach ($context[self::columns] as $k => $models) {
                if (is_array($models)) {
                    $columnList = [];
                    foreach ($models as $m) {
                        if ($m instanceof ColumnDefinitionModel) {
                            $columnList[] = $m;
                        }
                    }
                    $columns[(string) $k] = $columnList;
                }
            }
            $indexes = [];
            foreach ($context[self::indexes] as $k => $idxList) {
                $indexes[(string) $k] = is_array($idxList) ? array_values($idxList) : [$idxList];
            }

            return new self($options, $columns, $indexes);
        }

        $options = [];
        $columns = [];
        $indexes = [];

        foreach ($context as $key => $value) {
            $keyStr = (string) $key;

            if (in_array($keyStr, ['engine', 'charset', 'collation', 'temporary', 'comment'], true)) {
                $options[$keyStr] = $value;

                continue;
            }

            if (in_array($keyStr, ['primary', 'unique', 'index', 'fullText', 'spatialIndex'], true)) {
                $indexes[$keyStr] = is_array($value) && ! array_is_list($value)
                    ? [$value]
                    : array_values((array) $value);

                continue;
            }

            if (method_exists(Blueprint::class, $keyStr)) {
                $items = is_array($value) && array_is_list($value) ? $value : [$value];
                $columns[$keyStr] = array_map(
                    fn ($item): ColumnDefinitionModel => ColumnDefinitionModel::fromDefinition($keyStr, $item),
                    $items
                );

                continue;
            }

            throw new LogicException("Unknown Blueprint method or table option [{$keyStr}].");
        }

        return new self($options, $columns, $indexes);
    }
}
