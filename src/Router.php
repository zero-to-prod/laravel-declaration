<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use LogicException;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Router
{
    use DataModel;

    public const string pattern = 'pattern';

    /** @var array<string, string> */
    #[Describe([Describe::pre => [self::class, 'validate'], Describe::default => []])]
    public array $pattern;

    /** @var list<string> */
    private const array keys = [
        self::pattern,
    ];

    /** @param  array<array-key, mixed>  $context */
    public static function validate(mixed $value, array $context): void
    {
        $unknown = array_diff(array_keys($context), self::keys);

        if ($unknown !== []) {
            throw new LogicException(
                'The `router` block declares unknown key(s): '.implode(', ', $unknown).
                '. Every key must be an `Illuminate\Routing\Router` method name.'
            );
        }
    }
}
