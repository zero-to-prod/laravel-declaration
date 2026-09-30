<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Database
{
    use DataModel;

    public const string connection = 'connection';

    #[Describe([Describe::nullable => true])]
    public ?string $connection;

    public const string listen = 'listen';

    /** @var list<string> */
    #[Describe([Describe::default => []])]
    public array $listen;
}
