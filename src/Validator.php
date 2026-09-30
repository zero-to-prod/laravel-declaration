<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Validator
{
    use DataModel;

    public const string extend = 'extend';

    /** @var array<string, string|array{extension: string, message?: string}> */
    #[Describe([Describe::default => []])]
    public array $extend;

    public const string extendImplicit = 'extendImplicit';

    /** @var array<string, string|array{extension: string, message?: string}> */
    #[Describe([Describe::default => []])]
    public array $extendImplicit;

    public const string extendDependent = 'extendDependent';

    /** @var array<string, string|array{extension: string, message?: string}> */
    #[Describe([Describe::default => []])]
    public array $extendDependent;

    public const string replacer = 'replacer';

    /** @var array<string, string> */
    #[Describe([Describe::default => []])]
    public array $replacer;

    public const string extension = 'extension';

    public const string message = 'message';
}
