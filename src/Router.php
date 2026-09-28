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

    public const string model = 'model';

    /** @var array<string, string> */
    #[Describe([Describe::default => []])]
    public array $model;

    public const string bind = 'bind';

    /** @var array<string, string> */
    #[Describe([Describe::default => []])]
    public array $bind;

    /** @var list<string> */
    private const array keys = [
        self::pattern,
        self::model,
        self::bind,
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
