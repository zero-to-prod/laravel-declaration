<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
use Illuminate\Database\Schema\ForeignKeyDefinition;
use LogicException;
use ReflectionMethod;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class ColumnDefinitionModel
{
    use DataModel;

    public const string factoryArguments = 'factoryArguments';

    public const string columnModifiers = 'columnModifiers';

    public const string foreignKeyModifiers = 'foreignKeyModifiers';

    public const string constrained = 'constrained';

    /**
     * @param  list<mixed>  $factoryArguments
     * @param  array<string, mixed>  $columnModifiers
     * @param  array<string, mixed>  $foreignKeyModifiers
     */
    public function __construct(public array $factoryArguments = [], public array $columnModifiers = [], public array $foreignKeyModifiers = [], public mixed $constrained = null) {}

    /** @return list<mixed> */
    public function factoryArguments(): array
    {
        return $this->factoryArguments;
    }

    public function isConstrained(): bool
    {
        return $this->constrained !== null && $this->constrained !== false;
    }

    public function applyConstraint(ForeignIdColumnDefinition $columnTarget): ForeignKeyDefinition
    {
        if ($this->constrained === true || $this->constrained === null) {
            return $columnTarget->constrained();
        }

        if (is_string($this->constrained)) {
            return $columnTarget->constrained($this->constrained);
        }

        if (is_array($this->constrained)) {
            if (array_is_list($this->constrained)) {
                /** @var list<string|null> $args */
                $args = $this->constrained;

                return $columnTarget->constrained(...$args);
            }

            return $columnTarget->constrained(
                is_string($this->constrained['table'] ?? null) ? $this->constrained['table'] : null,
                is_string($this->constrained['column'] ?? null) ? $this->constrained['column'] : 'id',
                is_string($this->constrained['indexName'] ?? null) ? $this->constrained['indexName'] : null,
            );
        }

        return $columnTarget->constrained();
    }

    public static function fromDefinition(string $columnType, mixed $definition): self
    {
        if (! method_exists(Blueprint::class, $columnType)) {
            throw new LogicException("Unknown table key or Blueprint method [{$columnType}].");
        }

        if ($definition === null) {
            return new self;
        }

        if (! is_array($definition)) {
            return new self([$definition]);
        }

        $refMethod = new ReflectionMethod(Blueprint::class, $columnType);
        $methodParams = $refMethod->getParameters();

        $paramValues = [];
        $consumedKeys = [];

        foreach ($methodParams as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $definition)) {
                $paramValues[$name] = $definition[$name];
                $consumedKeys[$name] = true;
            } elseif ($name === 'name' && array_key_exists('column', $definition)) {
                $paramValues[$name] = $definition['column'];
                $consumedKeys['column'] = true;
            } elseif ($name === 'column' && array_key_exists('name', $definition)) {
                $paramValues[$name] = $definition['name'];
                $consumedKeys['name'] = true;
            }
        }

        $factoryArguments = [];
        if ($paramValues !== []) {
            $lastIndex = -1;
            foreach ($methodParams as $i => $param) {
                if (array_key_exists($param->getName(), $paramValues)) {
                    $lastIndex = $i;
                }
            }

            for ($i = 0; $i <= $lastIndex; $i++) {
                $param = $methodParams[$i];
                $name = $param->getName();
                if (array_key_exists($name, $paramValues)) {
                    $factoryArguments[] = $paramValues[$name];
                } elseif ($param->isDefaultValueAvailable()) {
                    $factoryArguments[] = $param->getDefaultValue();
                } else {
                    $factoryArguments[] = null;
                }
            }
        }

        $columnModifiers = [];
        $foreignKeyModifiers = [];
        $constrained = null;

        $fkModifiers = [
            'cascadeOnUpdate' => true,
            'restrictOnUpdate' => true,
            'nullOnUpdate' => true,
            'noActionOnUpdate' => true,
            'cascadeOnDelete' => true,
            'restrictOnDelete' => true,
            'nullOnDelete' => true,
            'noActionOnDelete' => true,
            'deferrable' => true,
            'initiallyImmediate' => true,
            'onDelete' => true,
            'onUpdate' => true,
            'references' => true,
            'on' => true,
        ];

        $colModifiers = [
            'nullable' => true,
            'default' => true,
            'unique' => true,
            'index' => true,
            'primary' => true,
            'unsigned' => true,
            'autoIncrement' => true,
            'comment' => true,
            'after' => true,
            'first' => true,
            'storedAs' => true,
            'virtualAs' => true,
            'invisible' => true,
            'useCurrent' => true,
            'useCurrentOnUpdate' => true,
            'always' => true,
            'change' => true,
            'charset' => true,
            'collation' => true,
            'from' => true,
            'fulltext' => true,
            'generatedAs' => true,
            'persisted' => true,
            'spatialIndex' => true,
            'vectorIndex' => true,
            'startingValue' => true,
            'type' => true,
        ];

        foreach ($definition as $key => $value) {
            $strKey = (string) $key;
            if (isset($consumedKeys[$strKey])) {
                continue;
            }

            if ($strKey === 'constrained') {
                $constrained = $value;

                continue;
            }

            if (isset($fkModifiers[$strKey])) {
                $foreignKeyModifiers[$strKey] = $value;

                continue;
            }

            if (isset($colModifiers[$strKey])) {
                $columnModifiers[$strKey] = $value;

                continue;
            }

            throw new LogicException("Unknown column modifier or option [{$strKey}] for column type [{$columnType}].");
        }

        return new self($factoryArguments, $columnModifiers, $foreignKeyModifiers, $constrained);
    }
}
