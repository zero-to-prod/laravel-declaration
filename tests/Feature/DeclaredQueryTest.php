<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use ZeroToProd\LaravelDeclaration\DeclaredQuery;
use ZeroToProd\LaravelDeclaration\Query;
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

it('throws LogicException when queries entry has unknown keys', function (): void {
    Query::from([
        'name' => 'broken',
        'from' => Flight::class,
        'unknownKey' => 'value',
    ]);
})->throws(LogicException::class, 'The `queries` entry declares unknown key(s): unknownKey. Every key must be an `Illuminate\Database\Eloquent\Builder` method name.');

it('throws LogicException when queries.from is not an Eloquent Model or parameter relation', function (): void {
    Query::from([
        'name' => 'broken-from',
        'from' => 'NotAClassOrRelation',
    ]);
})->throws(LogicException::class, 'The `queries.from` [NotAClassOrRelation] must be an Eloquent Model class or a `param.relation` string.');

it('throws LogicException when multiple terminal methods are declared', function (): void {
    Query::from([
        'name' => 'multi-terminal',
        'from' => Flight::class,
        'count' => true,
        'paginate' => 10,
    ]);
})->throws(LogicException::class, 'The `queries` entry declares multiple terminal execution methods: count, paginate. A query pipeline must declare at most one terminal method.');

it('throws InvalidArgumentException when route parameter is not a Model', function (): void {
    $query = Query::from([
        'name' => 'param-test',
        'from' => 'airline.flights',
    ]);

    $query->run(['airline' => 'not-a-model']);
})->throws(InvalidArgumentException::class, 'Route parameter [airline] must be an instance of Illuminate\Database\Eloquent\Model to query relation [flights].');

it('throws LogicException when model does not define the relationship method', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    $query = Query::from([
        'name' => 'missing-rel',
        'from' => 'airline.nonExistentRelation',
    ]);

    $query->run(['airline' => $airline]);
})->throws(LogicException::class, 'Model ['.Airline::class.'] does not define relationship method [nonExistentRelation].');

it('throws LogicException when relationship method does not return Relation or Builder', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    $query = Query::from([
        'name' => 'invalid-rel-return',
        'from' => 'airline.notARelation',
    ]);

    $query->run(['airline' => $airline]);
})->throws(LogicException::class, 'Method [notARelation] on ['.Airline::class.'] must return an instance of Illuminate\Database\Eloquent\Relations\Relation or Illuminate\Database\Eloquent\Builder.');

it('tests where shapes and operators', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'status' => 'active', 'delayed' => false]);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200', 'status' => 'delayed', 'delayed' => true]);

    // Associative map where
    $qMap = Query::from([
        'name' => 'test-map',
        'from' => Flight::class,
        'where' => ['status' => 'active', 'delayed' => false],
    ]);
    expect($qMap->run())->toHaveCount(1);

    // List of tuples where
    $qTuples = Query::from([
        'name' => 'test-tuples',
        'from' => Flight::class,
        'where' => [['status', 'active'], ['code', 'AA100']],
    ]);
    expect($qTuples->run())->toHaveCount(1);

    // orWhere, whereNot, orWhereNot
    $qOrWhere = Query::from([
        'name' => 'test-or',
        'from' => Flight::class,
        'where' => ['status', 'active'],
        'orWhere' => ['status', 'delayed'],
        'whereNot' => ['code', 'AA999'],
        'orWhereNot' => ['code', 'AA000'],
    ]);
    expect($qOrWhere->run())->toHaveCount(2);
});

it('tests primary key whereKey and whereKeyNot clauses', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100']);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200']);

    $qKey = Query::from([
        'name' => 'test-key',
        'from' => Flight::class,
        'whereKey' => 'f-1',
    ]);
    expect($qKey->run())->toHaveCount(1);

    $qKeyNot = Query::from([
        'name' => 'test-key-not',
        'from' => Flight::class,
        'whereKeyNot' => 'f-1',
    ]);
    expect($qKeyNot->run())->toHaveCount(1);
});

it('tests whereIn and whereNotIn spread clauses', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100']);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200']);

    $qIn = Query::from([
        'name' => 'test-in',
        'from' => Flight::class,
        'whereIn' => ['code', ['AA100', 'AA200']],
        'whereNotIn' => ['code', ['AA300']],
    ]);
    expect($qIn->run())->toHaveCount(2);
});

it('tests whereNull and whereNotNull clauses', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'secret' => 'shh']);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200', 'secret' => null]);

    $qNull = Query::from([
        'name' => 'test-null',
        'from' => Flight::class,
        'whereNull' => 'secret',
    ]);
    expect($qNull->run())->toHaveCount(1);

    $qNotNull = Query::from([
        'name' => 'test-not-null',
        'from' => Flight::class,
        'whereNotNull' => 'secret',
    ]);
    expect($qNotNull->run())->toHaveCount(1);
});

it('tests whereBetween and whereNotBetween spread clauses', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'altitude' => 100]);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200', 'altitude' => 200]);

    $qBetween = Query::from([
        'name' => 'test-between',
        'from' => Flight::class,
        'whereBetween' => ['altitude', [50, 150]],
        'whereNotBetween' => ['altitude', [300, 400]],
    ]);
    expect($qBetween->run())->toHaveCount(1);
});

it('tests whereDate, whereMonth, whereDay, whereYear, whereTime and whereColumn spread clauses', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'created_at' => 1700000000]);

    $qDate = Query::from([
        'name' => 'test-date',
        'from' => Flight::class,
        'whereDate' => ['created_at', '>=', '2020-01-01'],
        'whereMonth' => ['created_at', '>=', '1'],
        'whereDay' => ['created_at', '>=', '1'],
        'whereYear' => ['created_at', '>=', '2020'],
        'whereTime' => ['created_at', '>=', '00:00:00'],
        'whereColumn' => ['name', '!=', 'code'],
    ]);
    expect($qDate->run())->toHaveCount(1);
});

it('tests relationship constraints: whereRelation, orWhereRelation, whereDoesntHaveRelation, has, doesntHave', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100']);

    $qRel = Query::from([
        'name' => 'test-rel',
        'from' => Flight::class,
        'whereRelation' => ['airline', 'name', 'Acme Air'],
        'orWhereRelation' => ['airline', 'name', 'NonExistent'],
        'whereDoesntHaveRelation' => ['airline', 'name', 'Bad Air'],
        'has' => 'airline',
    ]);
    expect($qRel->run())->toHaveCount(1);
});

it('tests relationship has and doesntHave on Airline', function (): void {
    $airline1 = Airline::create(['name' => 'Acme Air']);
    $airline2 = Airline::create(['name' => 'Delta Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline1->id, 'name' => 'Boston', 'code' => 'AA100']);

    $qHas = Query::from([
        'name' => 'test-has',
        'from' => Airline::class,
        'has' => ['flights', '>=', 1],
    ]);
    expect($qHas->run())->toHaveCount(1);

    $qDoesntHave = Query::from([
        'name' => 'test-doesnt-have',
        'from' => Airline::class,
        'doesntHave' => 'flights',
    ]);
    expect($qDoesntHave->run())->toHaveCount(1);
});

it('tests whereBelongsTo clause with owner model and relation', function (): void {
    $airline1 = Airline::create(['name' => 'Acme Air']);
    $airline2 = Airline::create(['name' => 'Delta Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline1->id, 'name' => 'Boston', 'code' => 'AA100']);

    $qBelongsTo = Query::from([
        'name' => 'test-belongs-to',
        'from' => Flight::class,
        'whereBelongsTo' => 'airline',
    ]);
    expect($qBelongsTo->run(['airline' => $airline1]))->toHaveCount(1);

    // Tuple form with relation name
    $qBelongsToTuple = Query::from([
        'name' => 'test-belongs-to-tuple',
        'from' => Flight::class,
        'whereBelongsTo' => ['airline', 'airline'],
    ]);
    expect($qBelongsToTuple->run(['airline' => $airline1]))->toHaveCount(1);
});

it('throws InvalidArgumentException when BelongsTo parameter is not a Model', function (): void {
    $qBelongsTo = Query::from([
        'name' => 'test-belongs-to-err',
        'from' => Flight::class,
        'whereBelongsTo' => 'airline',
    ]);

    $qBelongsTo->run(['airline' => 'string-not-model']);
})->throws(InvalidArgumentException::class, 'Route parameter [airline] must be an instance of Illuminate\Database\Eloquent\Model.');

it('throws InvalidArgumentException when BelongsTo parameter is non-scalar', function (): void {
    $qBelongsTo = Query::from([
        'name' => 'test-belongs-to-nonscalar',
        'from' => Flight::class,
        'whereBelongsTo' => [['nested'], null],
    ]);

    $qBelongsTo->run([]);
})->throws(InvalidArgumentException::class, 'Route parameter [array] must be an instance of Illuminate\Database\Eloquent\Model.');

it('tests eager loading clauses: with, without, withOnly, withCount, withMax, withMin, withSum, withAvg, withExists', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100']);

    $qEager = Query::from([
        'name' => 'test-eager',
        'from' => Airline::class,
        'with' => 'flights',
        'withOnly' => 'flights',
        'without' => 'flights',
        'withCount' => 'flights',
        'withMax' => ['flights', 'departed_at'],
        'withMin' => ['flights', 'departed_at'],
        'withSum' => ['flights', 'departed_at'],
        'withAvg' => ['flights', 'departed_at'],
        'withExists' => 'flights',
    ]);
    $res = $qEager->run();
    expect($res)->toHaveCount(1)
        ->and($res->first()->flights_count)->toBe(1)
        ->and($res->first()->flights_exists)->toBeTrue();
});

it('tests local scopes clause', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'status' => 'active']);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200', 'status' => 'cancelled']);

    $qScope = Query::from([
        'name' => 'test-scope',
        'from' => Flight::class,
        'scopes' => 'active',
    ]);
    expect($qScope->run())->toHaveCount(1);
});

it('tests Flag attribute branches: false, true, null, and custom value', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'departed_at' => 100]);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200', 'departed_at' => 200]);

    // distinct false (early return)
    $qDistinctFalse = Query::from([
        'name' => 'test-distinct-false',
        'from' => Flight::class,
        'distinct' => false,
    ]);
    expect($qDistinctFalse->run())->toHaveCount(2);

    // distinct true (calls method with no args)
    $qDistinctTrue = Query::from([
        'name' => 'test-distinct-true',
        'from' => Flight::class,
        'distinct' => true,
    ]);
    expect($qDistinctTrue->run())->toHaveCount(2);

    // latest null (calls latest() with default created_at)
    $qLatestNull = Query::from([
        'name' => 'test-latest-null',
        'from' => Flight::class,
        'latest' => null,
    ]);
    expect($qLatestNull->run())->toHaveCount(2);

    // latest with column value
    $qLatestCol = Query::from([
        'name' => 'test-latest-col',
        'from' => Flight::class,
        'latest' => 'departed_at',
    ]);
    expect($qLatestCol->run()->first()->flight_id)->toBe('f-2');

    // oldest
    $qOldest = Query::from([
        'name' => 'test-oldest',
        'from' => Flight::class,
        'oldest' => 'departed_at',
    ]);
    expect($qOldest->run()->first()->flight_id)->toBe('f-1');

    // inRandomOrder with seed
    $qRandom = Query::from([
        'name' => 'test-random',
        'from' => Flight::class,
        'inRandomOrder' => true,
    ]);
    expect($qRandom->run())->toHaveCount(2);

    // withoutGlobalScopes
    $qNoScopes = Query::from([
        'name' => 'test-noscopes',
        'from' => Flight::class,
        'withoutGlobalScopes' => true,
    ]);
    expect($qNoScopes->run())->toHaveCount(2);
});

it('tests select, addSelect, orderBy, orderByDesc, groupBy, having, limit, offset clauses', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'status' => 'active']);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200', 'status' => 'active']);

    $qClauses = Query::from([
        'name' => 'test-clauses',
        'from' => Flight::class,
        'select' => ['flight_id', 'status'],
        'addSelect' => 'name',
        'orderBy' => ['flight_id', 'desc'],
        'limit' => 1,
        'offset' => 0,
    ]);
    $res = $qClauses->run();
    expect($res)->toHaveCount(1)
        ->and($res->first()->flight_id)->toBe('f-2');

    // orderByDesc and string orderBy
    $qOrderDesc = Query::from([
        'name' => 'test-order-desc',
        'from' => Flight::class,
        'orderByDesc' => 'name',
        'orderBy' => 'flight_id',
    ]);
    expect($qOrderDesc->run())->toHaveCount(2);

    // groupBy and having
    $qGroup = Query::from([
        'name' => 'test-group',
        'from' => Flight::class,
        'select' => ['status'],
        'groupBy' => 'status',
        'having' => ['status', '=', 'active'],
    ]);
    expect($qGroup->run())->toHaveCount(1);
});

it('tests terminal methods: first, firstOrFail, sole, find, findOrFail', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100']);

    // first true (Fetch no args)
    $qFirst = Query::from(['name' => 't-first', 'from' => Flight::class, 'first' => true]);
    expect($qFirst->run()->flight_id)->toBe('f-1');

    // first with string column
    $qFirstCol = Query::from(['name' => 't-first-col', 'from' => Flight::class, 'first' => 'flight_id']);
    expect($qFirstCol->run()->flight_id)->toBe('f-1');

    // first with array columns
    $qFirstArr = Query::from(['name' => 't-first-arr', 'from' => Flight::class, 'first' => ['flight_id']]);
    expect($qFirstArr->run()->flight_id)->toBe('f-1');

    // firstOrFail
    $qFirstOrFail = Query::from(['name' => 't-first-or-fail', 'from' => Flight::class, 'firstOrFail' => true]);
    expect($qFirstOrFail->run()->flight_id)->toBe('f-1');

    // sole
    $qSole = Query::from(['name' => 't-sole', 'from' => Flight::class, 'sole' => true]);
    expect($qSole->run()->flight_id)->toBe('f-1');

    // find (single arg)
    $qFind = Query::from(['name' => 't-find', 'from' => Flight::class, 'find' => 'f-1']);
    expect($qFind->run()->flight_id)->toBe('f-1');

    // findOrFail
    $qFindOrFail = Query::from(['name' => 't-find-or-fail', 'from' => Flight::class, 'findOrFail' => 'f-1']);
    expect($qFindOrFail->run()->flight_id)->toBe('f-1');
});

it('tests scalar terminal methods: min, max, sum, avg, value, exists, doesntExist, pluck', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100', 'altitude' => 10]);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $airline->id, 'name' => 'Denver', 'code' => 'AA200', 'altitude' => 20]);

    expect(Query::from(['name' => 't-min', 'from' => Flight::class, 'min' => 'altitude'])->run())->toBe(10)
        ->and(Query::from(['name' => 't-max', 'from' => Flight::class, 'max' => 'altitude'])->run())->toBe(20)
        ->and(Query::from(['name' => 't-sum', 'from' => Flight::class, 'sum' => 'altitude'])->run())->toBe(30)
        ->and(Query::from(['name' => 't-avg', 'from' => Flight::class, 'avg' => 'altitude'])->run())->toBe(15.0)
        ->and(Query::from(['name' => 't-val', 'from' => Flight::class, 'value' => 'code'])->run())->toBe('AA100')
        ->and(Query::from(['name' => 't-exists', 'from' => Flight::class, 'exists' => true])->run())->toBeTrue()
        ->and(Query::from(['name' => 't-not-exists', 'from' => Flight::class, 'doesntExist' => true])->run())->toBeFalse()
        ->and(Query::from(['name' => 't-pluck-str', 'from' => Flight::class, 'pluck' => 'code'])->run()->all())->toBe(['AA100', 'AA200'])
        ->and(Query::from(['name' => 't-pluck-arr', 'from' => Flight::class, 'pluck' => ['name', 'flight_id']])->run()->all())->toBe(['f-1' => 'Boston', 'f-2' => 'Denver']);
});

it('tests pagination terminals: paginate, simplePaginate, cursorPaginate', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100']);

    // paginate with array args
    $qPagArr = Query::from(['name' => 't-pag-arr', 'from' => Flight::class, 'paginate' => [1, ['flight_id']]]);
    expect($qPagArr->run())->toBeInstanceOf(LengthAwarePaginator::class);

    // paginate with bool/true
    $qPagTrue = Query::from(['name' => 't-pag-true', 'from' => Flight::class, 'paginate' => true]);
    expect($qPagTrue->run())->toBeInstanceOf(LengthAwarePaginator::class);

    // simplePaginate
    $qSimple = Query::from(['name' => 't-simple-pag', 'from' => Flight::class, 'simplePaginate' => 1]);
    expect($qSimple->run())->toBeInstanceOf(Paginator::class);

    // cursorPaginate
    $qCursor = Query::from(['name' => 't-cursor-pag', 'from' => Flight::class, 'cursorPaginate' => 1]);
    expect($qCursor->run())->toBeInstanceOf(CursorPaginator::class);
});

it('tests withoutGlobalScope clause', function (): void {
    // withoutGlobalScope
    $qWithoutScope = Query::from([
        'name' => 't-without-scope',
        'from' => Flight::class,
        'withoutGlobalScope' => 'nonExistentScopeClass',
    ]);
    expect($qWithoutScope->run())->toBeInstanceOf(Collection::class);
});

it('tests get terminal with string and array columns', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100']);

    $qGetStr = Query::from(['name' => 't-get-str', 'from' => Flight::class, 'get' => 'flight_id']);
    expect($qGetStr->run())->toHaveCount(1);

    $qGetArr = Query::from(['name' => 't-get-arr', 'from' => Flight::class, 'get' => ['flight_id']]);
    expect($qGetArr->run())->toHaveCount(1);
});

it('tests count terminal with string column', function (): void {
    $airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $airline->id, 'name' => 'Boston', 'code' => 'AA100']);

    $qCountStr = Query::from(['name' => 't-count-str', 'from' => Flight::class, 'count' => 'flight_id']);
    expect($qCountStr->run())->toBe(1);
});
