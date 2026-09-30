<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Response
{
    use DataModel;

    public const string macro = 'macro';

    /** @var array<string, string> */
    #[Describe([Describe::default => []])]
    public array $macro;
}
