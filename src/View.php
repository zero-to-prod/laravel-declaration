<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class View
{
    use DataModel;

    public const string addLocation = 'addLocation';

    /** @var list<string> */
    #[Describe([Describe::default => []])]
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

    /** @var array<string, string> */
    #[Describe([Describe::default => []])]
    public array $composer;

    public const string creator = 'creator';

    /** @var array<string, string> */
    #[Describe([Describe::default => []])]
    public array $creator;

    public const string flushFinderCache = 'flushFinderCache';

    #[Describe([Describe::default => false])]
    public bool $flushFinderCache;

    public const string flushState = 'flushState';

    #[Describe([Describe::default => false])]
    public bool $flushState;
}
