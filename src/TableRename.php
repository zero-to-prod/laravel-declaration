<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class TableRename
{
    use DataModel;

    public const string from = 'from';

    public const string to = 'to';

    #[Describe([Describe::required => true])]
    public string $from;

    #[Describe([Describe::required => true])]
    public string $to;
}
