<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Attributes\ClassDefault;
use ZeroToProd\LaravelDeclaration\Attributes\Key;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Model
{
    use DataModel;

    /** @var class-string<DeclaredModel> */
    #[Key, Describe([Describe::required => true])]
    public string $class;

    public const string connection = 'connection';

    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?string $connection;

    public const string table = 'table';

    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?string $table;

    public const string primaryKey = 'primaryKey';

    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?string $primaryKey;

    public const string keyType = 'keyType';

    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?string $keyType;

    public const string incrementing = 'incrementing';

    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?bool $incrementing;

    public const string timestamps = 'timestamps';

    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?bool $timestamps;

    public const string dateFormat = 'dateFormat';

    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?string $dateFormat;

    public const string attributes = 'attributes';

    /** @var array<string, mixed>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $attributes;

    public const string casts = 'casts';

    /** @var array<string, string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $casts;

    public const string fillable = 'fillable';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $fillable;

    public const string guarded = 'guarded';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $guarded;

    public const string hidden = 'hidden';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $hidden;

    public const string visible = 'visible';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $visible;

    public const string appends = 'appends';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $appends;

    public const string with = 'with';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $with;

    public const string withCount = 'withCount';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $withCount;

    public const string touches = 'touches';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $touches;

    public const string refreshes = 'refreshes';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $refreshes;

    public const string perPage = 'perPage';

    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?int $perPage;

    public const string dispatchesEvents = 'dispatchesEvents';

    /** @var array<string, class-string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $dispatchesEvents;

    public const string observables = 'observables';

    /** @var list<string>|null */
    #[Key, ClassDefault, Describe([Describe::nullable => true])]
    public ?array $observables;

    public const string observe = 'observe';

    /** @var list<class-string> */
    #[Key, Describe([Describe::default => []])]
    public array $observe;

    public const string addGlobalScope = 'addGlobalScope';

    /** @var list<class-string> */
    #[Key, Describe([Describe::default => []])]
    public array $addGlobalScope;

    public const string getRouteKeyName = 'getRouteKeyName';

    #[Key, Describe([Describe::nullable => true])]
    public ?string $getRouteKeyName;

    /** @return array<string, mixed> */
    public function properties(): array
    {
        return array_filter(
            array_intersect_key(get_object_vars($this), array_flip(self::selected(ClassDefault::class))),
            static fn (mixed $value): bool => $value !== null,
        );
    }
}
