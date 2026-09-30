<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Provider
{
    use DataModel;

    /** @var class-string */
    #[Describe([Describe::required => true])]
    public string $class;

    public const string force = 'force';

    #[Describe([Describe::default => false])]
    public bool $force;
}
