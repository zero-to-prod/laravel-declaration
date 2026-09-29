<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use LogicException;
use ReflectionClass;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Model
{
    use DataModel;

    /** @var class-string<DeclaredModel> */
    #[Describe([Describe::pre => [self::class, 'validate'], Describe::required => true])]
    public string $class;

    public const string connection = 'connection';

    #[Describe([Describe::nullable => true])]
    public ?string $connection;

    public const string table = 'table';

    #[Describe([Describe::nullable => true])]
    public ?string $table;

    public const string primaryKey = 'primaryKey';

    #[Describe([Describe::nullable => true])]
    public ?string $primaryKey;

    public const string keyType = 'keyType';

    #[Describe([Describe::nullable => true])]
    public ?string $keyType;

    public const string incrementing = 'incrementing';

    #[Describe([Describe::nullable => true])]
    public ?bool $incrementing;

    public const string timestamps = 'timestamps';

    #[Describe([Describe::nullable => true])]
    public ?bool $timestamps;

    public const string dateFormat = 'dateFormat';

    #[Describe([Describe::nullable => true])]
    public ?string $dateFormat;

    public const string attributes = 'attributes';

    /** @var array<string, mixed>|null */
    #[Describe([Describe::nullable => true])]
    public ?array $attributes;

    public const string casts = 'casts';

    /** @var array<string, string>|null */
    #[Describe([Describe::nullable => true])]
    public ?array $casts;

    public const string fillable = 'fillable';

    /** @var list<string>|null */
    #[Describe([Describe::nullable => true])]
    public ?array $fillable;

    public const string guarded = 'guarded';

    /** @var list<string>|null */
    #[Describe([Describe::nullable => true])]
    public ?array $guarded;

    public const string hidden = 'hidden';

    /** @var list<string>|null */
    #[Describe([Describe::nullable => true])]
    public ?array $hidden;

    public const string visible = 'visible';

    /** @var list<string>|null */
    #[Describe([Describe::nullable => true])]
    public ?array $visible;

    public const string appends = 'appends';

    /** @var list<string>|null */
    #[Describe([Describe::nullable => true])]
    public ?array $appends;

    public const string with = 'with';

    /** @var list<string>|null */
    #[Describe([Describe::nullable => true])]
    public ?array $with;

    public const string withCount = 'withCount';

    /** @var list<string>|null */
    #[Describe([Describe::nullable => true])]
    public ?array $withCount;

    public const string touches = 'touches';

    /** @var list<string>|null */
    #[Describe([Describe::nullable => true])]
    public ?array $touches;

    public const string refreshes = 'refreshes';

    /** @var list<string>|null */
    #[Describe([Describe::nullable => true])]
    public ?array $refreshes;

    public const string perPage = 'perPage';

    #[Describe([Describe::nullable => true])]
    public ?int $perPage;

    public const string dispatchesEvents = 'dispatchesEvents';

    /** @var array<string, class-string>|null */
    #[Describe([Describe::nullable => true])]
    public ?array $dispatchesEvents;

    public const string observables = 'observables';

    /** @var list<string>|null */
    #[Describe([Describe::nullable => true])]
    public ?array $observables;

    public const string observe = 'observe';

    /** @var list<class-string> */
    #[Describe([Describe::default => []])]
    public array $observe;

    public const string addGlobalScope = 'addGlobalScope';

    /** @var list<class-string> */
    #[Describe([Describe::default => []])]
    public array $addGlobalScope;

    public const string getRouteKeyName = 'getRouteKeyName';

    #[Describe([Describe::nullable => true])]
    public ?string $getRouteKeyName;

    /** @var list<string> The `Model` instance properties, assigned as class-body defaults. */
    private const array properties = [
        self::connection,
        self::table,
        self::primaryKey,
        self::keyType,
        self::incrementing,
        self::timestamps,
        self::dateFormat,
        self::attributes,
        self::casts,
        self::fillable,
        self::guarded,
        self::hidden,
        self::visible,
        self::appends,
        self::with,
        self::withCount,
        self::touches,
        self::refreshes,
        self::perPage,
        self::dispatchesEvents,
        self::observables,
    ];

    /** @var list<string> */
    private const array keys = [
        'class',
        ...self::properties,
        self::observe,
        self::addGlobalScope,
        self::getRouteKeyName,
    ];

    /** @param  array<array-key, mixed>  $context */
    public static function validate(mixed $value, array $context): void
    {
        $unknown = array_diff(array_keys($context), self::keys);

        if ($unknown !== []) {
            throw new LogicException(
                'The `models` entry declares unknown key(s): '.implode(', ', $unknown).
                '. Every key must be an `Illuminate\Database\Eloquent\Model` property or method name.'
            );
        }

        if (is_string($value) && (! is_subclass_of($value, DeclaredModel::class) || new ReflectionClass($value)->name !== $value)) {
            throw new LogicException(
                "The `models` class [$value] must extend ".DeclaredModel::class.', spelled as `static::class` spells it.'
            );
        }
    }

    /** @return array<string, mixed> The declared properties; an absent key keeps the class default. */
    public function properties(): array
    {
        return array_filter(
            array_intersect_key(get_object_vars($this), array_flip(self::properties)),
            static fn (mixed $value): bool => $value !== null,
        );
    }
}
