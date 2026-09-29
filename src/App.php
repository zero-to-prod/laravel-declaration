<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use LogicException;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Attributes\Key;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class App
{
    use DataModel;

    public const string bind = 'bind';

    /** @var array<int|string, string|null> */
    #[Key, Describe([Describe::pre => [self::class, 'validate'], Describe::default => []])]
    public array $bind;

    public const string bindIf = 'bindIf';

    /** @var array<int|string, string|null> */
    #[Key, Describe([Describe::default => []])]
    public array $bindIf;

    public const string singleton = 'singleton';

    /** @var array<int|string, string|null> */
    #[Key, Describe([Describe::default => []])]
    public array $singleton;

    public const string singletonIf = 'singletonIf';

    /** @var array<int|string, string|null> */
    #[Key, Describe([Describe::default => []])]
    public array $singletonIf;

    public const string scoped = 'scoped';

    /** @var array<int|string, string|null> */
    #[Key, Describe([Describe::default => []])]
    public array $scoped;

    public const string scopedIf = 'scopedIf';

    /** @var array<int|string, string|null> */
    #[Key, Describe([Describe::default => []])]
    public array $scopedIf;

    public const string instance = 'instance';

    /** @var array<string, mixed> */
    #[Key, Describe([Describe::default => []])]
    public array $instance;

    public const string alias = 'alias';

    /** @var array<string, string> */
    #[Key, Describe([Describe::default => []])]
    public array $alias;

    public const string extend = 'extend';

    /** @var array<string, string> */
    #[Key, Describe([Describe::default => []])]
    public array $extend;

    public const string useAppPath = 'useAppPath';

    #[Key, Describe([Describe::nullable => true])]
    public ?string $useAppPath;

    public const string useDatabasePath = 'useDatabasePath';

    #[Key, Describe([Describe::nullable => true])]
    public ?string $useDatabasePath;

    public const string useLangPath = 'useLangPath';

    #[Key, Describe([Describe::nullable => true])]
    public ?string $useLangPath;

    public const string usePublicPath = 'usePublicPath';

    #[Key, Describe([Describe::nullable => true])]
    public ?string $usePublicPath;

    public const string useStoragePath = 'useStoragePath';

    #[Key, Describe([Describe::nullable => true])]
    public ?string $useStoragePath;

    public const string setLocale = 'setLocale';

    #[Key, Describe([Describe::nullable => true])]
    public ?string $setLocale;

    public const string setFallbackLocale = 'setFallbackLocale';

    #[Key, Describe([Describe::nullable => true])]
    public ?string $setFallbackLocale;

    public const string registered = 'registered';

    /** @var list<string> */
    #[Key, Describe([Describe::default => []])]
    public array $registered;

    public const string booting = 'booting';

    /** @var list<string> */
    #[Key, Describe([Describe::default => []])]
    public array $booting;

    public const string booted = 'booted';

    /** @var list<string> */
    #[Key, Describe([Describe::default => []])]
    public array $booted;

    public const string terminating = 'terminating';

    /** @var list<string> */
    #[Key, Describe([Describe::default => []])]
    public array $terminating;

    /** @param  array<array-key, mixed>  $context */
    public static function validate(mixed $value, array $context): void
    {
        $unknown = array_diff(array_keys($context), self::selected(Key::class));

        if ($unknown !== []) {
            throw new LogicException(
                'The `app` block declares unknown key(s): '.implode(', ', $unknown).
                '. Every key must be an `Illuminate\Foundation\Application` method name.'
            );
        }

        foreach ([self::bindIf, self::singletonIf, self::scopedIf] as $key) {
            $items = $context[$key] ?? null;

            foreach (is_array($items) ? $items : [] as $index => $item) {
                if (is_int($index) && is_string($item) && str_ends_with($item, '.php')) {
                    throw new LogicException(
                        "The `app.$key` list declares the `.php` item [$item]. `$key()` calls `bound(\$abstract)`, which cannot take ".
                        "the file's Closure; declare it in the map form (`Abstract: $item`)."
                    );
                }
            }
        }
    }
}
