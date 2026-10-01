<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Preset;
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

    #[Preset, Describe([Describe::default => false])]
    public bool $useTailwind;

    #[Preset, Describe([Describe::default => false])]
    public bool $useBootstrap;

    #[Preset, Describe([Describe::default => false])]
    public bool $useBootstrapThree;

    #[Preset, Describe([Describe::default => false])]
    public bool $useBootstrapFour;

    #[Preset, Describe([Describe::default => false])]
    public bool $useBootstrapFive;
}
