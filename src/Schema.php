<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Support\Collection;
use ReflectionAttribute;
use ReflectionProperty;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Key;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Schema
{
    use DataModel;

    public const string connection = 'connection';

    public const string create = 'create';

    public const string table = 'table';

    public const string rename = 'rename';

    public const string drop = 'drop';

    public const string dropIfExists = 'dropIfExists';

    #[Key, Describe([Describe::nullable => true])]
    public ?string $connection;

    /** @var Collection<string, TableDefinition> */
    #[Key, Describe([Describe::cast => [self::class, 'mapOf'], 'type' => TableDefinition::class])]
    public Collection $create;

    /** @var Collection<string, TableDefinition> */
    #[Key, Describe([Describe::cast => [self::class, 'mapOf'], 'type' => TableDefinition::class])]
    public Collection $table;

    /** @var list<TableRename> */
    #[Key, Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => TableRename::class])]
    public array $rename;

    /** @var list<string> */
    #[Key, Describe([Describe::default => []])]
    public array $drop;

    /** @var list<string> */
    #[Key, Describe([Describe::default => []])]
    public array $dropIfExists;

    /**
     * @param  array<string, mixed>  $context
     * @param  ReflectionAttribute<Describe>|null  $Attribute
     * @return list<object>
     */
    public static function listOf(mixed $value, array $context, ?ReflectionAttribute $Attribute, ReflectionProperty $Property): array
    {
        $arguments = $Attribute?->getArguments()[0];
        $type = is_array($arguments) ? ($arguments['type'] ?? null) : null;

        if (! is_string($type) || ! is_array($value)) {
            return [];
        }

        /** @var list<array<string, mixed>> $items */
        $items = array_values($value);

        return array_map(static fn (array $item): object => $type::from($item), $items);
    }
}
