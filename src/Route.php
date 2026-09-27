<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use LogicException;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

use function in_array;

final readonly class Route
{
    use DataModel;

    public const string path = 'path';

    #[Describe([Describe::required => true])]
    public string $path;

    public const string methods = 'methods';

    #[Describe([Describe::required => true])]
    public string $methods;

    public const string action = 'action';

    /** @var string|list<string> `Class@method` string, invokable FQCN, or `[Class, 'method']` */
    #[Describe([Describe::required => true])]
    public string|array $action;

    public const string name = 'name';

    #[Describe([Describe::nullable => true])]
    public ?string $name;

    public const string prefix = 'prefix';

    #[Describe([Describe::nullable => true])]
    public ?string $prefix;

    public const string domain = 'domain';

    #[Describe([Describe::nullable => true])]
    public ?string $domain;

    public const string middleware = 'middleware';

    /** @var list<string> */
    #[Describe([Describe::default => []])]
    public array $middleware;

    public const string withoutMiddleware = 'withoutMiddleware';

    /** @var list<string> */
    #[Describe([Describe::default => []])]
    public array $withoutMiddleware;

    public const string can = 'can';

    /** @var array{ability: string, models?: string|list<string>} */
    #[Describe([Describe::default => []])]
    public array $can;

    public const string where = 'where';

    /** @var array<string, string> */
    #[Describe([Describe::default => []])]
    public array $where;

    public const string setDefaults = 'setDefaults';

    /** @var array<string, mixed> */
    #[Describe([Describe::default => []])]
    public array $setDefaults;

    public const string missing = 'missing';

    /** @var class-string invokable class wrapped in a Closure by the provider */
    #[Describe([Describe::nullable => true])]
    public ?string $missing;

    public const string fallback = 'fallback';

    #[Describe([Describe::default => false])]
    public bool $fallback;

    public const string scopeBindings = 'scopeBindings';

    #[Describe([Describe::default => false])]
    public bool $scopeBindings;

    public const string withoutScopedBindings = 'withoutScopedBindings';

    #[Describe([Describe::default => false])]
    public bool $withoutScopedBindings;

    public const string withTrashed = 'withTrashed';

    #[Describe([Describe::default => false])]
    public bool $withTrashed;

    public const string block = 'block';

    /** @var array{lockSeconds?: int|null, waitSeconds?: int|null}|null */
    #[Describe([Describe::nullable => true])]
    public ?array $block;

    public const string withoutBlocking = 'withoutBlocking';

    #[Describe([Describe::default => false])]
    public bool $withoutBlocking;

    public const string metadata = 'metadata';

    /** @var array<string, mixed> */
    #[Describe([Describe::default => []])]
    public array $metadata;

    /** Builder method names in dispatch order; `prefix` must run before `domain`. */
    private const array BUILDERS = [
        self::name,
        self::prefix,
        self::domain,
        self::middleware,
        self::withoutMiddleware,
        self::can,
        self::where,
        self::setDefaults,
        self::missing,
        self::fallback,
        self::scopeBindings,
        self::withoutScopedBindings,
        self::withTrashed,
        self::block,
        self::withoutBlocking,
        self::metadata,
    ];

    /** Zero-arg `Route` builder methods, dispatched as `$route->{$method}()`. */
    private const array FLAGS = [
        self::fallback,
        self::scopeBindings,
        self::withoutScopedBindings,
        self::withoutBlocking,
    ];

    /** @return class-string */
    public function missingHandler(): string
    {
        return $this->missing ?? throw new LogicException('The `missing` builder is not declared.');
    }

    /** @return array<string, mixed> */
    public function builders(): array
    {
        $builders = [];

        foreach (self::BUILDERS as $method) {
            $value = $this->{$method};

            if (in_array($method, self::FLAGS, true)) {
                if ($value === true) {
                    $builders[$method] = true;
                }

                continue;
            }

            if (! (in_array($value, [null, [], false], true))) {
                $builders[$method] = $value;
            }
        }

        return $builders;
    }
}
