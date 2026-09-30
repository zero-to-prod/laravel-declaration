<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class RouteResource
{
    use DataModel;

    public const string name = 'name';

    public const string controller = 'controller';

    public const string options = 'options';

    #[Describe([Describe::required => true])]
    public string $name;

    #[Describe([Describe::required => true])]
    public string $controller;

    /** @var array<string, mixed> */
    #[Describe([Describe::default => []])]
    public array $options;
}
