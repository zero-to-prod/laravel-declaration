<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use ZeroToProd\LaravelDeclaration\DeclaredQuery;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Airline;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Flight;

$manifest = __DIR__.'/../Fixtures/manifest/queries.yml';

beforeEach(function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    Schema::dropIfExists('my_flights');
    Schema::dropIfExists('airlines');

    Schema::create('airlines', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    Schema::create('my_flights', function (Blueprint $table): void {
        $table->string('flight_id')->primary();
        $table->foreignId('airline_id');
        $table->string('name');
        $table->string('code');
        $table->string('status')->default('scheduled');
        $table->text('options')->nullable();
        $table->boolean('delayed')->default(false);
        $table->string('secret')->nullable();
        $table->integer('departed_at')->nullable();
        $table->integer('altitude')->nullable();
        $table->integer('created_at')->nullable();
        $table->integer('updated_at')->nullable();
    });
});

it('executes a declared query rooted on an Eloquent model with default get terminal', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'status' => 'active']);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200', 'status' => 'cancelled']);

    $results = DeclaredQuery::run('all-flights');

    expect($results)->toBeInstanceOf(Collection::class)
        ->and($results)->toHaveCount(2)
        ->and($results->first()->flight_id)->toBe('f-1');
});

it('executes a declared query with where clause and explicit get terminal', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'status' => 'active']);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200', 'status' => 'cancelled']);

    $results = DeclaredQuery::run('active-flights');

    expect($results)->toHaveCount(1)
        ->and($results->first()->flight_id)->toBe('f-1');
});

it('executes a declared query with route parameter relation and pagination', function (): void {
    $airline1 = Airline::create(['name' => 'Acme Air']);
    $airline2 = Airline::create(['name' => 'Delta Air']);

    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline1->id, 'name' => 'Boston', 'code' => 'AA100', 'status' => 'active']);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline1->id, 'name' => 'Miami', 'code' => 'AA150', 'status' => 'active']);
    Flight::create(['flight_id' => 'f-3', 'airline_id' => $airline2->id, 'name' => 'Denver', 'code' => 'DL200', 'status' => 'active']);

    $paginator = DeclaredQuery::run('airline-flights', ['airline' => $airline1]);

    expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($paginator->total())->toBe(2)
        ->and($paginator->items())->toHaveCount(2);
});

it('executes an aggregate terminal method returning scalar integer count', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100']);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200']);

    $count = DeclaredQuery::run('flight-count');

    expect($count)->toBe(2);
});

it('resolves declared query handles within DeclaredView data', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'status' => 'active']);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Miami', 'code' => 'AA150', 'status' => 'active']);
    $this->get('/airlines/'.$airline->id.'/flights')
        ->assertOk()
        ->assertSeeText('Flights List|2');
});

it('throws LogicException when the declared query does not exist', function (): void {
    DeclaredQuery::run('non-existent-query');
})->throws(LogicException::class, 'The declared query [non-existent-query] does not exist.');

it('throws BadMethodCallException when query calls unmapped or invalid method', function (): void {
    $query = [
        'name' => 'broken',
        'model' => Flight::class,
        'nonExistentBuilderMethod' => 'value',
    ];

    DeclaredQuery::execute($query);
})->throws(BadMethodCallException::class);

it('allows native from method on Query Builder', function (): void {
    $query = [
        'name' => 'native-from',
        'model' => Flight::class,
        'from' => 'my_flights',
    ];

    expect(DeclaredQuery::execute($query))->toBeInstanceOf(Collection::class);
});

it('allows multiple terminal methods, executing the last declared terminal', function (): void {
    $query = [
        'name' => 'multi-terminal',
        'model' => Flight::class,
        'count' => true,
        'paginate' => 10,
    ];

    expect(DeclaredQuery::execute($query))->toBeInstanceOf(Illuminate\Contracts\Pagination\LengthAwarePaginator::class);
});

it('throws InvalidArgumentException when route parameter is not a Model', function (): void {
    $query = [
        'name' => 'param-test',
        'relation' => 'airline.flights',
    ];

    DeclaredQuery::execute($query, ['airline' => 'not-a-model']);
})->throws(InvalidArgumentException::class, 'Route parameter [airline] must be an instance of Illuminate\Database\Eloquent\Model to query relation [flights].');

it('throws LogicException when query declares neither model nor relation', function (): void {
    $query = [
        'name' => 'no-root',
    ];

    DeclaredQuery::execute($query);
})->throws(LogicException::class, "Query [no-root] must declare either 'model' or 'relation'.");

it('throws InvalidArgumentException when declared query model is not an Eloquent Model subclass', function (): void {
    $query = [
        'name' => 'not-a-model-class',
        'model' => stdClass::class,
    ];

    DeclaredQuery::execute($query);
})->throws(InvalidArgumentException::class, 'Declared query model [stdClass] must be a subclass of Illuminate\Database\Eloquent\Model.');

it('throws InvalidArgumentException when relation is not formatted as param.relation', function (): void {
    $query = [
        'name' => 'invalid-relation-format',
        'relation' => 'invalidformat',
    ];

    DeclaredQuery::execute($query);
})->throws(InvalidArgumentException::class, "Relation query must specify route parameter and relation in 'param.relation' format; 'invalidformat' given.");

it('throws LogicException when model does not define the relationship method', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    $query = [
        'name' => 'missing-rel',
        'relation' => 'airline.nonExistentRelation',
    ];

    DeclaredQuery::execute($query, ['airline' => $airline]);
})->throws(LogicException::class, 'Model ['.Airline::class.'] does not define relationship method [nonExistentRelation].');

it('throws LogicException when relationship method does not return Relation or Builder', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    $query = [
        'name' => 'invalid-rel-return',
        'relation' => 'airline.notARelation',
    ];

    DeclaredQuery::execute($query, ['airline' => $airline]);
})->throws(LogicException::class, 'Relationship method [notARelation] on ['.Airline::class.'] must return an Eloquent Relation or Builder.');

it('tests where shapes and operators', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'status' => 'active', 'delayed' => false]);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200', 'status' => 'delayed', 'delayed' => true]);

    // Associative map where
    $qMap = [
        'name' => 'test-map',
        'model' => Flight::class,
        'where' => ['status' => 'active', 'delayed' => false],
    ];
    expect(DeclaredQuery::execute($qMap))->toHaveCount(1);

    // List of tuples where
    $qTuples = [
        'name' => 'test-tuples',
        'model' => Flight::class,
        'where' => [['status', 'active'], ['code', 'AA100']],
    ];
    expect(DeclaredQuery::execute($qTuples))->toHaveCount(1);

    // orWhere, whereNot, orWhereNot
    $qOrWhere = [
        'name' => 'test-or',
        'model' => Flight::class,
        'where' => ['status', 'active'],
        'orWhere' => ['status', 'delayed'],
        'whereNot' => ['code', 'AA999'],
        'orWhereNot' => ['code', 'AA000'],
    ];
    expect(DeclaredQuery::execute($qOrWhere))->toHaveCount(2);
});

it('tests primary key whereKey and whereKeyNot clauses', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100']);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200']);

    $qKey = [
        'name' => 'test-key',
        'model' => Flight::class,
        'whereKey' => 'f-1',
    ];
    expect(DeclaredQuery::execute($qKey))->toHaveCount(1);

    $qKeyNot = [
        'name' => 'test-key-not',
        'model' => Flight::class,
        'whereKeyNot' => 'f-1',
    ];
    expect(DeclaredQuery::execute($qKeyNot))->toHaveCount(1);
});

it('tests whereIn and whereNotIn spread clauses', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100']);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200']);

    $qIn = [
        'name' => 'test-in',
        'model' => Flight::class,
        'whereIn' => ['code', ['AA100', 'AA200']],
        'whereNotIn' => ['code', ['AA300']],
    ];
    expect(DeclaredQuery::execute($qIn))->toHaveCount(2);
});

it('tests whereNull and whereNotNull clauses', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'secret' => 'shh']);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200', 'secret' => null]);

    $qNull = [
        'name' => 'test-null',
        'model' => Flight::class,
        'whereNull' => 'secret',
    ];
    expect(DeclaredQuery::execute($qNull))->toHaveCount(1);

    $qNotNull = [
        'name' => 'test-not-null',
        'model' => Flight::class,
        'whereNotNull' => 'secret',
    ];
    expect(DeclaredQuery::execute($qNotNull))->toHaveCount(1);
});

it('tests whereBetween and whereNotBetween spread clauses', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'altitude' => 100]);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200', 'altitude' => 200]);

    $qBetween = [
        'name' => 'test-between',
        'model' => Flight::class,
        'whereBetween' => ['altitude', [50, 150]],
        'whereNotBetween' => ['altitude', [300, 400]],
    ];
    expect(DeclaredQuery::execute($qBetween))->toHaveCount(1);
});

it('tests whereDate, whereMonth, whereDay, whereYear, whereTime and whereColumn spread clauses', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'created_at' => 1700000000]);

    $qDate = [
        'name' => 'test-date',
        'model' => Flight::class,
        'whereDate' => ['created_at', '>=', '2020-01-01'],
        'whereMonth' => ['created_at', '>=', '1'],
        'whereDay' => ['created_at', '>=', '1'],
        'whereYear' => ['created_at', '>=', '2020'],
        'whereTime' => ['created_at', '>=', '00:00:00'],
        'whereColumn' => ['name', '!=', 'code'],
    ];
    expect(DeclaredQuery::execute($qDate))->toHaveCount(1);
});

it('tests relationship constraints: whereRelation, orWhereRelation, whereDoesntHaveRelation, has, doesntHave', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100']);

    $qRel = [
        'name' => 'test-rel',
        'model' => Flight::class,
        'whereRelation' => ['airline', 'name', 'Acme Air'],
        'orWhereRelation' => ['airline', 'name', 'NonExistent'],
        'whereDoesntHaveRelation' => ['airline', 'name', 'Bad Air'],
        'has' => 'airline',
    ];
    expect(DeclaredQuery::execute($qRel))->toHaveCount(1);
});

it('tests relationship has and doesntHave on Airline', function (): void {
    $airline1 = Airline::create(['name' => 'Acme Air']);
    $airline2 = Airline::create(['name' => 'Delta Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline1->id, 'name' => 'Boston', 'code' => 'AA100']);

    $qHas = [
        'name' => 'test-has',
        'model' => Airline::class,
        'has' => ['flights', '>=', 1],
    ];
    expect(DeclaredQuery::execute($qHas))->toHaveCount(1);

    $qDoesntHave = [
        'name' => 'test-doesnt-have',
        'model' => Airline::class,
        'doesntHave' => 'flights',
    ];
    expect(DeclaredQuery::execute($qDoesntHave))->toHaveCount(1);
});

it('tests whereBelongsTo clause with owner model and relation', function (): void {
    $airline1 = Airline::create(['name' => 'Acme Air']);
    $airline2 = Airline::create(['name' => 'Delta Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline1->id, 'name' => 'Boston', 'code' => 'AA100']);

    $qBelongsTo = [
        'name' => 'test-belongs-to',
        'model' => Flight::class,
        'whereBelongsTo' => $airline1,
    ];
    expect(DeclaredQuery::execute($qBelongsTo))->toHaveCount(1);

    // Tuple form with relation name
    $qBelongsToTuple = [
        'name' => 'test-belongs-to-tuple',
        'model' => Flight::class,
        'whereBelongsTo' => [$airline1, 'airline'],
    ];
    expect(DeclaredQuery::execute($qBelongsToTuple))->toHaveCount(1);
});

it('tests eager loading clauses: with, without, withOnly, withCount, withMax, withMin, withSum, withAvg, withExists', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100']);

    $qEager = [
        'name' => 'test-eager',
        'model' => Airline::class,
        'with' => 'flights',
        'withOnly' => 'flights',
        'without' => 'flights',
        'withCount' => 'flights',
        'withMax' => ['flights', 'departed_at'],
        'withMin' => ['flights', 'departed_at'],
        'withSum' => ['flights', 'departed_at'],
        'withAvg' => ['flights', 'departed_at'],
        'withExists' => 'flights',
    ];
    $res = DeclaredQuery::execute($qEager);
    expect($res)->toHaveCount(1)
        ->and($res->first()->flights_count)->toBe(1)
        ->and($res->first()->flights_exists)->toBeTrue();
});

it('tests local scopes clause', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'status' => 'active']);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200', 'status' => 'cancelled']);

    $qScope = [
        'name' => 'test-scope',
        'model' => Flight::class,
        'scopes' => 'active',
    ];
    expect(DeclaredQuery::execute($qScope))->toHaveCount(1);
});

it('tests dynamic method dispatch: boolean, null, array, and scalar arguments', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'departed_at' => 100]);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200', 'departed_at' => 200]);

    // distinct true (calls method with no args)
    $qDistinctTrue = [
        'name' => 'test-distinct-true',
        'model' => Flight::class,
        'distinct' => true,
    ];
    expect(DeclaredQuery::execute($qDistinctTrue))->toHaveCount(2);

    // latest null (calls latest() with default created_at)
    $qLatestNull = [
        'name' => 'test-latest-null',
        'model' => Flight::class,
        'latest' => null,
    ];
    expect(DeclaredQuery::execute($qLatestNull))->toHaveCount(2);

    // latest with column value
    $qLatestCol = [
        'name' => 'test-latest-col',
        'model' => Flight::class,
        'latest' => 'departed_at',
    ];
    expect(DeclaredQuery::execute($qLatestCol)->first()->flight_id)->toBe('f-2');

    // oldest
    $qOldest = [
        'name' => 'test-oldest',
        'model' => Flight::class,
        'oldest' => 'departed_at',
    ];
    expect(DeclaredQuery::execute($qOldest)->first()->flight_id)->toBe('f-1');

    // inRandomOrder with seed
    $qRandom = [
        'name' => 'test-random',
        'model' => Flight::class,
        'inRandomOrder' => true,
    ];
    expect(DeclaredQuery::execute($qRandom))->toHaveCount(2);

    // withoutGlobalScopes
    $qNoScopes = [
        'name' => 'test-noscopes',
        'model' => Flight::class,
        'withoutGlobalScopes' => true,
    ];
    expect(DeclaredQuery::execute($qNoScopes))->toHaveCount(2);
});

it('tests select, addSelect, orderBy, orderByDesc, groupBy, having, limit, offset clauses', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'status' => 'active']);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200', 'status' => 'active']);

    $qClauses = [
        'name' => 'test-clauses',
        'model' => Flight::class,
        'select' => ['flight_id', 'status'],
        'addSelect' => 'name',
        'orderBy' => ['flight_id', 'desc'],
        'limit' => 1,
        'offset' => 0,
    ];
    $res = DeclaredQuery::execute($qClauses);
    expect($res)->toHaveCount(1)
        ->and($res->first()->flight_id)->toBe('f-2');

    // orderByDesc and string orderBy
    $qOrderDesc = [
        'name' => 'test-order-desc',
        'model' => Flight::class,
        'orderByDesc' => 'name',
        'orderBy' => 'flight_id',
    ];
    expect(DeclaredQuery::execute($qOrderDesc))->toHaveCount(2);

    // groupBy and having
    $qGroup = [
        'name' => 'test-group',
        'model' => Flight::class,
        'select' => ['status'],
        'groupBy' => 'status',
        'having' => ['status', '=', 'active'],
    ];
    expect(DeclaredQuery::execute($qGroup))->toHaveCount(1);
});

it('tests terminal methods: first, firstOrFail, sole, find, findOrFail', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100']);

    // first true
    $qFirst = ['name' => 't-first', 'model' => Flight::class, 'first' => true];
    expect(DeclaredQuery::execute($qFirst)->flight_id)->toBe('f-1');

    // first with string column
    $qFirstCol = ['name' => 't-first-col', 'model' => Flight::class, 'first' => 'flight_id'];
    expect(DeclaredQuery::execute($qFirstCol)->flight_id)->toBe('f-1');

    // first with array columns
    $qFirstArr = ['name' => 't-first-arr', 'model' => Flight::class, 'first' => ['flight_id']];
    expect(DeclaredQuery::execute($qFirstArr)->flight_id)->toBe('f-1');

    // firstOrFail
    $qFirstOrFail = ['name' => 't-first-or-fail', 'model' => Flight::class, 'firstOrFail' => true];
    expect(DeclaredQuery::execute($qFirstOrFail)->flight_id)->toBe('f-1');

    // sole
    $qSole = ['name' => 't-sole', 'model' => Flight::class, 'sole' => true];
    expect(DeclaredQuery::execute($qSole)->flight_id)->toBe('f-1');

    // find (single arg)
    $qFind = ['name' => 't-find', 'model' => Flight::class, 'find' => 'f-1'];
    expect(DeclaredQuery::execute($qFind)->flight_id)->toBe('f-1');

    // findOrFail
    $qFindOrFail = ['name' => 't-find-or-fail', 'model' => Flight::class, 'findOrFail' => 'f-1'];
    expect(DeclaredQuery::execute($qFindOrFail)->flight_id)->toBe('f-1');
});

it('tests scalar terminal methods: min, max, sum, avg, value, exists, doesntExist, pluck', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'altitude' => 10]);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200', 'altitude' => 20]);

    expect(DeclaredQuery::execute(['name' => 't-min', 'model' => Flight::class, 'min' => 'altitude']))->toBe(10)
        ->and(DeclaredQuery::execute(['name' => 't-max', 'model' => Flight::class, 'max' => 'altitude']))->toBe(20)
        ->and(DeclaredQuery::execute(['name' => 't-sum', 'model' => Flight::class, 'sum' => 'altitude']))->toBe(30)
        ->and(DeclaredQuery::execute(['name' => 't-avg', 'model' => Flight::class, 'avg' => 'altitude']))->toBe(15.0)
        ->and(DeclaredQuery::execute(['name' => 't-val', 'model' => Flight::class, 'value' => 'code']))->toBe('AA100')
        ->and(DeclaredQuery::execute(['name' => 't-exists', 'model' => Flight::class, 'exists' => true]))->toBeTrue()
        ->and(DeclaredQuery::execute(['name' => 't-not-exists', 'model' => Flight::class, 'doesntExist' => true]))->toBeFalse()
        ->and(DeclaredQuery::execute(['name' => 't-pluck-str', 'model' => Flight::class, 'pluck' => 'code'])->all())->toBe(['AA100', 'AA200'])
        ->and(DeclaredQuery::execute(['name' => 't-pluck-arr', 'model' => Flight::class, 'pluck' => ['name', 'flight_id']])->all())->toBe(['f-1' => 'Boston', 'f-2' => 'Denver']);
});

it('tests pagination terminals: paginate, simplePaginate, cursorPaginate', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100']);

    // paginate with array args
    $qPagArr = ['name' => 't-pag-arr', 'model' => Flight::class, 'paginate' => [1, ['flight_id']]];
    expect(DeclaredQuery::execute($qPagArr))->toBeInstanceOf(LengthAwarePaginator::class);

    // paginate with bool/true
    $qPagTrue = ['name' => 't-pag-true', 'model' => Flight::class, 'paginate' => true];
    expect(DeclaredQuery::execute($qPagTrue))->toBeInstanceOf(LengthAwarePaginator::class);

    // simplePaginate
    $qSimple = ['name' => 't-simple-pag', 'model' => Flight::class, 'simplePaginate' => 1];
    expect(DeclaredQuery::execute($qSimple))->toBeInstanceOf(Paginator::class);

    // cursorPaginate
    $qCursor = ['name' => 't-cursor-pag', 'model' => Flight::class, 'cursorPaginate' => 1];
    expect(DeclaredQuery::execute($qCursor))->toBeInstanceOf(CursorPaginator::class);
});

it('tests withoutGlobalScope clause', function (): void {
    $qWithoutScope = [
        'name' => 't-without-scope',
        'model' => Flight::class,
        'withoutGlobalScope' => 'nonExistentScopeClass',
    ];
    expect(DeclaredQuery::execute($qWithoutScope))->toBeInstanceOf(Collection::class);
});

it('tests get terminal with string and array columns', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100']);

    $qGetStr = ['name' => 't-get-str', 'model' => Flight::class, 'get' => 'flight_id'];
    expect(DeclaredQuery::execute($qGetStr))->toHaveCount(1);

    $qGetArr = ['name' => 't-get-arr', 'model' => Flight::class, 'get' => ['flight_id']];
    expect(DeclaredQuery::execute($qGetArr))->toHaveCount(1);
});

it('tests count terminal with string column', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100']);

    $qCountStr = ['name' => 't-count-str', 'model' => Flight::class, 'count' => 'flight_id'];
    expect(DeclaredQuery::execute($qCountStr))->toBe(1);
});
