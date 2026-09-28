<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use LogicException;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

/**
 * The `app:` block of the manifest.
 *
 * Every key is an `Illuminate\Foundation\Application` (or inherited `Illuminate\Container\Container`)
 * method name and every value is the argument(s) of that method's signature, so the provider
 * applies the block with a small, fixed set of loops.
 *
 * @link docs/declarative-application.md
 */
final readonly class App
{
    use DataModel;

    public const string bind = 'bind';

    /** @var array<int|string, string|null> abstract => concrete (`class-string`, abstract, `.php` file, or null), or a list of abstracts */
    #[Describe([Describe::pre => [self::class, 'validate'], Describe::default => []])]
    public array $bind;

    public const string bindIf = 'bindIf';

    /** @var array<int|string, string|null> abstract => concrete, or a list of abstracts; skipped when already bound */
    #[Describe([Describe::default => []])]
    public array $bindIf;

    public const string singleton = 'singleton';

    /** @var array<int|string, string|null> abstract => concrete, or a list of abstracts */
    #[Describe([Describe::default => []])]
    public array $singleton;

    public const string singletonIf = 'singletonIf';

    /** @var array<int|string, string|null> abstract => concrete, or a list of abstracts; skipped when already bound */
    #[Describe([Describe::default => []])]
    public array $singletonIf;

    public const string scoped = 'scoped';

    /** @var array<int|string, string|null> abstract => concrete, or a list of abstracts */
    #[Describe([Describe::default => []])]
    public array $scoped;

    public const string scopedIf = 'scopedIf';

    /** @var array<int|string, string|null> abstract => concrete, or a list of abstracts; skipped when already bound */
    #[Describe([Describe::default => []])]
    public array $scopedIf;

    public const string instance = 'instance';

    /** @var array<string, mixed> abstract => literal value, `class-string`, or `.php` file */
    #[Describe([Describe::default => []])]
    public array $instance;

    public const string alias = 'alias';

    /** @var array<string, string> abstract => alias */
    #[Describe([Describe::default => []])]
    public array $alias;

    public const string extend = 'extend';

    /** @var array<string, string> abstract => reference */
    #[Describe([Describe::default => []])]
    public array $extend;

    public const string useAppPath = 'useAppPath';

    #[Describe([Describe::nullable => true])]
    public ?string $useAppPath;

    public const string useDatabasePath = 'useDatabasePath';

    #[Describe([Describe::nullable => true])]
    public ?string $useDatabasePath;

    public const string useLangPath = 'useLangPath';

    #[Describe([Describe::nullable => true])]
    public ?string $useLangPath;

    public const string usePublicPath = 'usePublicPath';

    #[Describe([Describe::nullable => true])]
    public ?string $usePublicPath;

    public const string useStoragePath = 'useStoragePath';

    #[Describe([Describe::nullable => true])]
    public ?string $useStoragePath;

    public const string setLocale = 'setLocale';

    #[Describe([Describe::nullable => true])]
    public ?string $setLocale;

    public const string setFallbackLocale = 'setFallbackLocale';

    #[Describe([Describe::nullable => true])]
    public ?string $setFallbackLocale;

    public const string registered = 'registered';

    /** @var list<string> */
    #[Describe([Describe::default => []])]
    public array $registered;

    public const string booting = 'booting';

    /** @var list<string> */
    #[Describe([Describe::default => []])]
    public array $booting;

    public const string booted = 'booted';

    /** @var list<string> */
    #[Describe([Describe::default => []])]
    public array $booted;

    public const string terminating = 'terminating';

    /** @var list<string> */
    #[Describe([Describe::default => []])]
    public array $terminating;

    /** @var list<string> Every `Application` method name the `app:` block may declare. */
    private const array keys = [
        self::bind,
        self::bindIf,
        self::singleton,
        self::singletonIf,
        self::scoped,
        self::scopedIf,
        self::instance,
        self::alias,
        self::extend,
        self::useAppPath,
        self::useDatabasePath,
        self::useLangPath,
        self::usePublicPath,
        self::useStoragePath,
        self::setLocale,
        self::setFallbackLocale,
        self::registered,
        self::booting,
        self::booted,
        self::terminating,
    ];

    /**
     * Rejects keys that name no `Application` method, so a typo fails fast instead of
     * hydrating silently, and `.php` list items under the `*If` keys. Runs first — property
     * declaration order — against the whole `app:` block, before any value is resolved.
     *
     * @param  array<array-key, mixed>  $context
     */
    public static function validate(mixed $value, array $context): void
    {
        $unknown = array_diff(array_keys($context), self::keys);

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
