<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use LogicException;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Attributes\Key;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Router
{
    use DataModel;

    public const string pattern = 'pattern';

    /** @var array<string, string> */
    #[Key, Describe([Describe::pre => [self::class, 'validate'], Describe::default => []])]
    public array $pattern;

    public const string model = 'model';

    /** @var array<string, string> */
    #[Key, Describe([Describe::default => []])]
    public array $model;

    public const string bind = 'bind';

    /** @var array<string, string> */
    #[Key, Describe([Describe::default => []])]
    public array $bind;

    /** @param  array<array-key, mixed>  $context */
    public static function validate(mixed $value, array $context): void
    {
        $unknown = array_diff(array_keys($context), self::selected(Key::class));

        if ($unknown !== []) {
            throw new LogicException(
                'The `router` block declares unknown key(s): '.implode(', ', $unknown).
                '. Every key must be an `Illuminate\Routing\Router` method name.'
            );
        }
    }
}
