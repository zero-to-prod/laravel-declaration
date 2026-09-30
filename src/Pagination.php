<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Pagination
{
    use DataModel;

    public const string defaultView = 'defaultView';

    #[Describe([Describe::nullable => true])]
    public ?string $defaultView;

    public const string defaultSimpleView = 'defaultSimpleView';

    #[Describe([Describe::nullable => true])]
    public ?string $defaultSimpleView;

    public const string useTailwind = 'useTailwind';

    #[Describe([Describe::default => false])]
    public bool $useTailwind;

    public const string useBootstrapFive = 'useBootstrapFive';

    #[Describe([Describe::default => false])]
    public bool $useBootstrapFive;
}
