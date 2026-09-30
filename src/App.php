<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Attributes\Binding;
use ZeroToProd\LaravelDeclaration\Attributes\Conditional;
use ZeroToProd\LaravelDeclaration\Attributes\Path;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class App
{
    use DataModel;

    public const string bind = 'bind';

    /** @var array<string, string|null>|list<string> */
    #[Binding, Describe([Describe::default => []])]
    public array $bind;

    public const string bindIf = 'bindIf';

    /** @var array<string, string|null>|list<string> */
    #[Binding, Conditional, Describe([Describe::default => []])]
    public array $bindIf;

    public const string singleton = 'singleton';

    /** @var array<string, string|null>|list<string> */
    #[Binding, Describe([Describe::default => []])]
    public array $singleton;

    public const string singletonIf = 'singletonIf';

    /** @var array<string, string|null>|list<string> */
    #[Binding, Conditional, Describe([Describe::default => []])]
    public array $singletonIf;

    public const string scoped = 'scoped';

    /** @var array<string, string|null>|list<string> */
    #[Binding, Describe([Describe::default => []])]
    public array $scoped;

    public const string scopedIf = 'scopedIf';

    /** @var array<string, string|null>|list<string> */
    #[Binding, Conditional, Describe([Describe::default => []])]
    public array $scopedIf;

    public const string instance = 'instance';

    /** @var array<string, mixed> */
    #[Describe([Describe::default => []])]
    public array $instance;

    public const string alias = 'alias';

    /** @var array<string, string> */
    #[Describe([Describe::default => []])]
    public array $alias;

    public const string extend = 'extend';

    /** @var array<string, string> */
    #[Describe([Describe::default => []])]
    public array $extend;

    public const string tag = 'tag';

    /** @var array<string, list<string>|string> */
    #[Describe([Describe::default => []])]
    public array $tag;

    public const string when = 'when';

    public const string give = 'give';

    public const string needs = 'needs';

    /** @var array<class-string, array{needs: string, give: mixed}> */
    #[Describe([Describe::default => []])]
    public array $when;

    public const string useAppPath = 'useAppPath';

    #[Path, Describe([Describe::nullable => true])]
    public ?string $useAppPath;

    public const string useDatabasePath = 'useDatabasePath';

    #[Path, Describe([Describe::nullable => true])]
    public ?string $useDatabasePath;

    public const string useLangPath = 'useLangPath';

    #[Path, Describe([Describe::nullable => true])]
    public ?string $useLangPath;

    public const string usePublicPath = 'usePublicPath';

    #[Path, Describe([Describe::nullable => true])]
    public ?string $usePublicPath;

    public const string useStoragePath = 'useStoragePath';

    #[Path, Describe([Describe::nullable => true])]
    public ?string $useStoragePath;

    public const string useBootstrapPath = 'useBootstrapPath';

    #[Path, Describe([Describe::nullable => true])]
    public ?string $useBootstrapPath;

    public const string useConfigPath = 'useConfigPath';

    #[Path, Describe([Describe::nullable => true])]
    public ?string $useConfigPath;

    public const string useEnvironmentPath = 'useEnvironmentPath';

    #[Path, Describe([Describe::nullable => true])]
    public ?string $useEnvironmentPath;

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

    public const string resolving = 'resolving';

    /** @var array<string, string|list<string>> */
    #[Describe([Describe::default => []])]
    public array $resolving;

    public const string afterResolving = 'afterResolving';

    /** @var array<string, string|list<string>> */
    #[Describe([Describe::default => []])]
    public array $afterResolving;

    public const string terminating = 'terminating';

    /** @var list<string> */
    #[Describe([Describe::default => []])]
    public array $terminating;
}
