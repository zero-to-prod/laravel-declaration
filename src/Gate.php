<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Gate
{
    use DataModel;

    public const string policy = 'policy';

    /** @var array<string, string> */
    #[Describe([Describe::default => []])]
    public array $policy;

    public const string define = 'define';

    /** @var array<string, string> */
    #[Describe([Describe::default => []])]
    public array $define;
}
