<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Append;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\AppendTo;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Binding;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Key;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\PrependTo;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\Setter;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Router
{
    use DataModel;

    public const string pattern = 'pattern';

    /** @var array<string, string> */
    #[Key, Binding, Describe([Describe::default => []])]
    public array $pattern;

    public const string model = 'model';

    /** @var array<string, string> */
    #[Key, Binding, Describe([Describe::default => []])]
    public array $model;

    public const string bind = 'bind';

    /** @var array<string, string> */
    #[Key, Binding, Describe([Describe::default => []])]
    public array $bind;

    public const string middlewareGroup = 'middlewareGroup';

    /** @var array<string, list<class-string|string>> one `Router::middlewareGroup($name, $middleware)` per entry */
    #[Key, Binding, Describe([Describe::default => []])]
    public array $middlewareGroup;

    public const string aliasMiddleware = 'aliasMiddleware';

    /** @var array<string, class-string|string> one `Router::aliasMiddleware($name, $class)` per entry */
    #[Key, Binding, Describe([Describe::default => []])]
    public array $aliasMiddleware;

    public const string prependMiddlewareToGroup = 'prependMiddlewareToGroup';

    /** @var array<string, list<class-string|string>|class-string|string> one call per item, reversed (declaration order lands at the group head) */
    #[Key, PrependTo, Describe([Describe::default => []])]
    public array $prependMiddlewareToGroup;

    public const string pushMiddlewareToGroup = 'pushMiddlewareToGroup';

    /** @var array<string, list<class-string|string>|class-string|string> one call per item; creates the group when missing */
    #[Key, AppendTo, Describe([Describe::default => []])]
    public array $pushMiddlewareToGroup;

    public const string removeMiddlewareFromGroup = 'removeMiddlewareFromGroup';

    /** @var array<string, list<class-string|string>|class-string|string> one call per item; silent no-op when absent */
    #[Key, AppendTo, Describe([Describe::default => []])]
    public array $removeMiddlewareFromGroup;

    public const string singularResourceParameters = 'singularResourceParameters';

    /** one `Router::singularResourceParameters($singular)` call; absent key -> Laravel default (true) */
    #[Key, Setter, Describe([Describe::nullable => true])]
    public ?bool $singularResourceParameters;

    public const string resourceParameters = 'resourceParameters';

    /** @var array<string, string>|null one `Router::resourceParameters($parameters)` call with the whole map (replaces) */
    #[Key, Setter, Describe([Describe::nullable => true])]
    public ?array $resourceParameters;

    public const string resourceVerbs = 'resourceVerbs';

    /** @var array<string, string>|null one `Router::resourceVerbs($verbs)` call with the whole map (merges) */
    #[Key, Setter, Describe([Describe::nullable => true])]
    public ?array $resourceVerbs;

    public const string matched = 'matched';

    /** @var list<string> one `Router::matched($callback)` per item; each item is a `Class` or `Class@method` listener reference */
    #[Key, Append, Describe([Describe::default => []])]
    public array $matched;
}
