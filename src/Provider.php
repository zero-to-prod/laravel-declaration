<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Provider
{
    use DataModel;

    public const string name = 'name';

    public string $name;

    /** @var class-string */
    public string $class;

    public const string description = 'description';

    #[Describe([Describe::nullable => true])]
    public ?string $description;

    public const string bindings = 'bindings';

    /** @var array<class-string, class-string> */
    #[Describe([Describe::default => []])]
    public array $bindings;

    public const string singletons = 'singletons';

    /** @var array<class-string, class-string> */
    #[Describe([Describe::default => []])]
    public array $singletons;

    public const string factories = 'factories';

    /** @var array<class-string, class-string> */
    #[Describe([Describe::default => []])]
    public array $factories;
}
