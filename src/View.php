<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use LogicException;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class View
{
    use DataModel;

    public const string addLocation = 'addLocation';

    /** @var list<string> */
    #[Describe([Describe::pre => [self::class, 'validate'], Describe::default => []])]
    public array $addLocation;

    public const string prependLocation = 'prependLocation';

    /** @var list<string> */
    #[Describe([Describe::default => []])]
    public array $prependLocation;

    public const string addNamespace = 'addNamespace';

    /** @var array<string, string|list<string>> */
    #[Describe([Describe::default => []])]
    public array $addNamespace;

    public const string prependNamespace = 'prependNamespace';

    /** @var array<string, string|list<string>> */
    #[Describe([Describe::default => []])]
    public array $prependNamespace;

    public const string replaceNamespace = 'replaceNamespace';

    /** @var array<string, string|list<string>> */
    #[Describe([Describe::default => []])]
    public array $replaceNamespace;

    public const string addExtension = 'addExtension';

    /** @var array<string, string> */
    #[Describe([Describe::default => []])]
    public array $addExtension;

    public const string share = 'share';

    /** @var array<string, mixed> */
    #[Describe([Describe::default => []])]
    public array $share;

    public const string composer = 'composer';

    /** @var array<string, string|list<string>> */
    #[Describe([Describe::default => []])]
    public array $composer;

    public const string creator = 'creator';

    /** @var array<string, string|list<string>> */
    #[Describe([Describe::default => []])]
    public array $creator;

    /** @var list<string> */
    private const array keys = [
        self::addLocation,
        self::prependLocation,
        self::addNamespace,
        self::prependNamespace,
        self::replaceNamespace,
        self::addExtension,
        self::share,
        self::composer,
        self::creator,
    ];

    /** @param  array<array-key, mixed>  $context */
    public static function validate(mixed $value, array $context): void
    {
        $unknown = array_diff(array_keys($context), self::keys);

        if ($unknown !== []) {
            throw new LogicException(
                'The `view` block declares unknown key(s): '.implode(', ', $unknown).
                '. Every key must be an `Illuminate\View\Factory` method name.'
            );
        }
    }
}
