<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function strtoupper;

/**
 * A declarative `Illuminate\Routing\Route` declaration.
 *
 * `path`, `methods` and `action` are the `Router::addRoute()` arguments.
 * Every other key is a `Route` builder method name and its value is that
 * method's argument, exposed in document order by {@see Route::builders()}.
 *
 * @link docs/declarative-routing.md
 */
final readonly class Route
{
    use DataModel {
        DataModel::from as baseFrom;
    }

    /** The single HTTP verbs a declared route may answer. */
    private const array VERBS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

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

    /** Every manifest key: the reserved `Router::addRoute()` args plus the builders. */
    private const array KEYS = [
        self::path,
        self::methods,
        self::action,
        ...self::BUILDERS,
    ];

    /**
     * Fails fast on manifest keys that are not `Route` API and on `methods`
     * that are not a single HTTP verb, then hydrates via `DataModel::from()`.
     *
     * @param  array<string, mixed>|object|null|string  $context
     *
     * @throws InvalidArgumentException When an unknown key is declared.
     * @throws UnexpectedValueException When `methods` is not a single verb.
     */
    public static function from(array|object|null|string $context = [], mixed $instance = null): self
    {
        $context = match (true) {
            $context instanceof self => $context,
            is_object($context) => (array) $context,
            is_array($context) => $context,
            default => [],
        };

        if ($context instanceof self) {
            return $context;
        }

        $unknown = array_diff(array_keys($context), self::KEYS);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown route key(s): '.implode(', ', $unknown)
                .'. Every key must be a `Route` method name or one of: '.implode(', ', self::KEYS),
            );
        }

        if (isset($context[self::methods])) {
            $methods = is_string($context[self::methods]) ? strtoupper($context[self::methods]) : null;

            if ($methods === null || ! in_array($methods, self::VERBS, true)) {
                throw new UnexpectedValueException(
                    '`methods` must be a single HTTP verb ('.implode(', ', self::VERBS).'), got: '
                    .json_encode($context[self::methods]),
                );
            }
        }

        return self::baseFrom($context, $instance);
    }

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

    /**
     * The invokable `missing` handler class, guaranteed non-null by `builders()`.
     *
     * @return class-string
     */
    public function missingHandler(): string
    {
        return $this->missing ?? throw new LogicException('The `missing` builder is not declared.');
    }

    /**
     * Every declared builder, ordered for dispatch: `map<Route method name, argument(s)>`.
     * Flags map to `true`; `can` and `block` map to their named-argument arrays.
     *
     * @return array<string, mixed>
     */
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
