<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Support\Collection;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Attributes\Key;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Schema
{
    use DataModel;

    public const string connection = 'connection';

    #[Key, Describe([Describe::nullable => true])]
    public ?string $connection;

    public const string tables = 'tables';

    /** @var Collection<string, TableDefinition> */
    #[Key, Describe([
        Describe::cast => [self::class, 'mapOf'],
        'type' => TableDefinition::class,
    ])]
    public Collection $tables;
}
