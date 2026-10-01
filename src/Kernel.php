<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Append;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\AppendTo;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Key;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Prepend;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\PrependTo;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Setter;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Kernel
{
    use DataModel;

    public const string pushMiddleware = 'pushMiddleware';

    /** @var list<class-string> */
    #[Key, Append, Describe([Describe::default => []])]
    public array $pushMiddleware;

    public const string prependMiddleware = 'prependMiddleware';

    /** @var list<class-string> */
    #[Key, Prepend, Describe([Describe::default => []])]
    public array $prependMiddleware;

    public const string setGlobalMiddleware = 'setGlobalMiddleware';

    /** @var list<class-string>|null */
    #[Key, Setter, Describe([Describe::nullable => true])]
    public ?array $setGlobalMiddleware;

    public const string appendMiddlewareToGroup = 'appendMiddlewareToGroup';

    /** @var array<string, list<class-string>|class-string> */
    #[Key, AppendTo, Describe([Describe::default => []])]
    public array $appendMiddlewareToGroup;

    public const string prependMiddlewareToGroup = 'prependMiddlewareToGroup';

    /** @var array<string, list<class-string>|class-string> */
    #[Key, PrependTo, Describe([Describe::default => []])]
    public array $prependMiddlewareToGroup;

    public const string setMiddlewareGroups = 'setMiddlewareGroups';

    /** @var array<string, list<class-string>>|null */
    #[Key, Setter, Describe([Describe::nullable => true])]
    public ?array $setMiddlewareGroups;

    public const string setMiddlewareAliases = 'setMiddlewareAliases';

    /** @var array<string, class-string>|null */
    #[Key, Setter, Describe([Describe::nullable => true])]
    public ?array $setMiddlewareAliases;

    public const string setMiddlewarePriority = 'setMiddlewarePriority';

    /** @var list<class-string>|null */
    #[Key, Setter, Describe([Describe::nullable => true])]
    public ?array $setMiddlewarePriority;

    public const string prependToMiddlewarePriority = 'prependToMiddlewarePriority';

    /** @var list<class-string> */
    #[Key, Prepend, Describe([Describe::default => []])]
    public array $prependToMiddlewarePriority;

    public const string appendToMiddlewarePriority = 'appendToMiddlewarePriority';

    /** @var list<class-string> */
    #[Key, Append, Describe([Describe::default => []])]
    public array $appendToMiddlewarePriority;

    public const string addToMiddlewarePriorityBefore = 'addToMiddlewarePriorityBefore';

    /** @var array<class-string, list<class-string>|class-string> */
    #[Key, AppendTo, Describe([Describe::default => []])]
    public array $addToMiddlewarePriorityBefore;

    public const string addToMiddlewarePriorityAfter = 'addToMiddlewarePriorityAfter';

    /** @var array<class-string, list<class-string>|class-string> */
    #[Key, PrependTo, Describe([Describe::default => []])]
    public array $addToMiddlewarePriorityAfter;

    public const string whenRequestLifecycleIsLongerThan = 'whenRequestLifecycleIsLongerThan';

    /** @var array<int|string, class-string|string> */
    #[Key, Describe([Describe::default => []])]
    public array $whenRequestLifecycleIsLongerThan;
}
