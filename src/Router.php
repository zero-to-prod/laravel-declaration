<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Attributes\Binding;
use ZeroToProd\LaravelDeclaration\Attributes\Key;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Router
{
    use DataModel;

    public const string pattern = 'pattern';

    /** @var array<string, string> */
    #[Key, Binding, Describe([Describe::default => []])]
    public array $pattern;

    public const string model = 'model';

    /** @var array<string, string> */
    #[Key, Binding, Describe([Describe::default => []])]
    public array $model;

    public const string bind = 'bind';

    /** @var array<string, string> */
    #[Key, Binding, Describe([Describe::default => []])]
    public array $bind;
}
