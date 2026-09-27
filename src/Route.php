<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use LogicException;
use ReflectionClass;
use ReflectionProperty;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\Builder;
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
    #[Builder]
    public ?string $name;

    public const string prefix = 'prefix';

    #[Describe([Describe::nullable => true])]
    #[Builder]
    public ?string $prefix;

    public const string domain = 'domain';

    #[Describe([Describe::nullable => true])]
    #[Builder]
    public ?string $domain;

    public const string middleware = 'middleware';

    /** @var list<string> */
    #[Describe([Describe::default => []])]
    #[Builder]
    public array $middleware;

    public const string withoutMiddleware = 'withoutMiddleware';

    /** @var list<string> */
    #[Describe([Describe::default => []])]
    #[Builder]
    public array $withoutMiddleware;

    public const string can = 'can';

    /** @var array{ability: string, models?: string|list<string>} */
    #[Describe([Describe::default => []])]
    #[Builder]
    public array $can;

    public const string where = 'where';

    /** @var array<string, string> */
    #[Describe([Describe::default => []])]
    #[Builder]
    public array $where;

    public const string setDefaults = 'setDefaults';

    /** @var array<string, mixed> */
    #[Describe([Describe::default => []])]
    #[Builder]
    public array $setDefaults;

    public const string missing = 'missing';

    /** @var class-string invokable class wrapped in a Closure by the provider */
    #[Describe([Describe::nullable => true])]
    #[Builder]
    public ?string $missing;

    public const string fallback = 'fallback';

    #[Describe([Describe::default => false])]
    #[Builder]
    public bool $fallback;

    public const string scopeBindings = 'scopeBindings';

    #[Describe([Describe::default => false])]
    #[Builder]
    public bool $scopeBindings;

    public const string withoutScopedBindings = 'withoutScopedBindings';

    #[Describe([Describe::default => false])]
    #[Builder]
    public bool $withoutScopedBindings;

    public const string withTrashed = 'withTrashed';

    #[Describe([Describe::default => false])]
    #[Builder]
    public bool $withTrashed;

    public const string block = 'block';

    /** @var array{lockSeconds?: int|null, waitSeconds?: int|null}|null */
    #[Describe([Describe::nullable => true])]
    #[Builder]
    public ?array $block;

    public const string withoutBlocking = 'withoutBlocking';

    #[Describe([Describe::default => false])]
    #[Builder]
    public bool $withoutBlocking;

    public const string metadata = 'metadata';

    /** @var array<string, mixed> */
    #[Describe([Describe::default => []])]
    #[Builder]
    public array $metadata;

    /** @return class-string */
    public function missingHandler(): string
    {
        return $this->missing ?? throw new LogicException('The `missing` builder is not declared.');
    }

    /** @return array<string, mixed> */
    public function builders(): array
    {
        return array_filter(
            array_intersect_key(get_object_vars($this), $this->builderNames()),
            static fn (mixed $value): bool => ! in_array($value, [null, [], false], true),
        );
    }

    /** @return array<string, int> */
    private function builderNames(): array
    {
        static $names = null;

        return $names ??= array_flip(array_map(
            static fn (ReflectionProperty $ReflectionProperty): string => $ReflectionProperty->name,
            array_filter(
                new ReflectionClass(self::class)->getProperties(),
                static fn (ReflectionProperty $ReflectionProperty): bool => $ReflectionProperty->getAttributes(Builder::class) !== [],
            ),
        ));
    }
}
