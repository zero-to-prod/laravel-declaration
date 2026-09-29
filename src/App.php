<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Attributes\Binding;
use ZeroToProd\LaravelDeclaration\Attributes\Key;
use ZeroToProd\LaravelDeclaration\Attributes\Path;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class App
{
    use DataModel;

    public const string bind = 'bind';

    /** @var array<int|string, string|null> */
    #[Key, Binding, Describe([Describe::default => []])]
    public array $bind;

    public const string bindIf = 'bindIf';

    /** @var array<int|string, string|null> */
    #[Key, Binding, Describe([Describe::default => []])]
    public array $bindIf;

    public const string singleton = 'singleton';

    /** @var array<int|string, string|null> */
    #[Key, Binding, Describe([Describe::default => []])]
    public array $singleton;

    public const string singletonIf = 'singletonIf';

    /** @var array<int|string, string|null> */
    #[Key, Binding, Describe([Describe::default => []])]
    public array $singletonIf;

    public const string scoped = 'scoped';

    /** @var array<int|string, string|null> */
    #[Key, Binding, Describe([Describe::default => []])]
    public array $scoped;

    public const string scopedIf = 'scopedIf';

    /** @var array<int|string, string|null> */
    #[Key, Binding, Describe([Describe::default => []])]
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

    #[Key, Path, Describe([Describe::nullable => true])]
    public ?string $useAppPath;

    public const string useDatabasePath = 'useDatabasePath';

    #[Key, Path, Describe([Describe::nullable => true])]
    public ?string $useDatabasePath;

    public const string useLangPath = 'useLangPath';

    #[Key, Path, Describe([Describe::nullable => true])]
    public ?string $useLangPath;

    public const string usePublicPath = 'usePublicPath';

    #[Key, Path, Describe([Describe::nullable => true])]
    public ?string $usePublicPath;

    public const string useStoragePath = 'useStoragePath';

    #[Key, Path, Describe([Describe::nullable => true])]
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
}
