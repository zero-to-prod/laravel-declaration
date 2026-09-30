<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Blade
{
    use DataModel;

    public const string directive = 'directive';

    /** @var array<string, string> */
    #[Describe([Describe::default => []])]
    public array $directive;

    public const string if = 'if';

    /** @var array<string, string> */
    #[Describe([Describe::default => []])]
    public array $if;

    public const string component = 'component';

    /** @var array<string, string> */
    #[Describe([Describe::default => []])]
    public array $component;

    public const string components = 'components';

    /** @var array<string, string> */
    #[Describe([Describe::default => []])]
    public array $components;

    public const string anonymousComponentPath = 'anonymousComponentPath';

    /** @var list<array{path: string, prefix?: string|null}> */
    #[Describe([Describe::default => []])]
    public array $anonymousComponentPath;

    public const string anonymousComponentNamespace = 'anonymousComponentNamespace';

    /** @var list<array{directory: string, prefix?: string|null}> */
    #[Describe([Describe::default => []])]
    public array $anonymousComponentNamespace;

    public const string stringable = 'stringable';

    /** @var array<class-string, string> */
    #[Describe([Describe::default => []])]
    public array $stringable;

    public const string withoutDoubleEncoding = 'withoutDoubleEncoding';

    #[Describe([Describe::default => false])]
    public bool $withoutDoubleEncoding;
}
