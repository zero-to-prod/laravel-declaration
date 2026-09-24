<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

readonly class Manifest
{
    use DataModel;

    public const string app = 'app';

    #[Describe([Describe::required => true])]
    public App $app;
}
