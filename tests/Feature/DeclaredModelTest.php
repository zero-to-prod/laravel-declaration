<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Airline;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Flight;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\FlightCreated;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Undeclared;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\User;

beforeEach(function (): void {
    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/models.yml']);

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
        $table->text('options');
        $table->boolean('delayed');
        $table->string('secret')->nullable();
        $table->integer('departed_at')->nullable();
        $table->integer('created_at')->nullable();
        $table->integer('updated_at')->nullable();
    });
});

it('assigns every declared property before Laravel initializes the model', function (): void {
    $Flight = new Flight;

    expect($Flight->getTable())->toBe('my_flights')
        ->and($Flight->getKeyName())->toBe('flight_id')
        ->and($Flight->getKeyType())->toBe('string')
        ->and($Flight->getIncrementing())->toBeFalse()
        ->and($Flight->getDateFormat())->toBe('U')
        ->and($Flight->getAttributes())->toBe(['delayed' => false, 'options' => '[]'])
        ->and($Flight->getCasts())->toBe(['delayed' => 'boolean', 'options' => 'array', 'departed_at' => 'datetime'])
        ->and($Flight->getFillable())->toBe(['flight_id', 'airline_id', 'name', 'code', 'status'])
        ->and($Flight->getGuarded())->toBe(['*'])
        ->and($Flight->getHidden())->toBe(['secret'])
        ->and($Flight->getAppends())->toBe(['label'])
        ->and($Flight->getTouchedRelations())->toBe(['airline'])
        ->and($Flight->getPerPage())->toBe(5)
        ->and($Flight->dispatchesEvents())->toBe(['created' => FlightCreated::class])
        ->and($Flight->getObservableEvents())->toContain('boarding')
        ->and(array_keys($Flight->newQuery()->getEagerLoads()))->toBe(['airline'])
        ->and($Flight->getRouteKeyName())->toBe('code');

    $Airline = new Airline;

    expect($Airline->getConnectionName())->toBe('testing')
        ->and($Airline->usesTimestamps())->toBeFalse()
        ->and($Airline->getGuarded())->toBeEmpty()
        ->and($Airline->getVisible())->toBe(['id', 'name', 'flights_count']);
});

it('keeps Laravel defaults for a class without an entry', function (): void {
    $Undeclared = new Undeclared;

    expect($Undeclared->getTable())->toBe('undeclareds')
        ->and($Undeclared->getKeyName())->toBe('id')
        ->and($Undeclared->getGuarded())->toBe(['*'])
        ->and($Undeclared->usesTimestamps())->toBeTrue()
        ->and($Undeclared->getPerPage())->toBe(15)
        ->and($Undeclared->getRouteKeyName())->toBe('id');
});

it('persists through the declared table, casts, observer, event and refreshed columns', function (): void {
    $created = null;
    Event::listen(FlightCreated::class, function (FlightCreated $FlightCreated) use (&$created): void {
        $created = $FlightCreated->flight;
    });

    $Airline = Airline::create(['name' => 'Acme Air']);
    $Flight = Flight::create(['flight_id' => 'f-1', 'airline_id' => $Airline->id, 'name' => 'Boston', 'code' => 'AA100']);

    expect($Flight->status)->toBe('scheduled')
        ->and($Flight->secret)->toBe('observed')
        ->and($created)->toBe($Flight)
        ->and(DB::table('my_flights')->value('created_at'))->toBeNumeric()
        ->and(Flight::query()->sole()->options)->toBe([]);
});

it('registers declared observers and global scopes when the model boots', function (): void {
    $Airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $Airline->id, 'name' => 'Boston', 'code' => 'AA100']);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $Airline->id, 'name' => 'Denver', 'code' => 'AA200', 'status' => 'cancelled']);

    expect(Flight::all())->toHaveCount(1)
        ->and(Flight::withoutGlobalScopes()->count())->toBe(2)
        ->and(Flight::query()->sole()->relationLoaded('airline'))->toBeTrue()
        ->and(Airline::query()->sole()->flights_count)->toBe(1)
        ->and(Event::hasListeners('eloquent.boarding: '.Flight::class))->toBeTrue();
});

it('binds a router.model parameter by the declared route key, through the declared scope', function (): void {
    $Airline = Airline::create(['name' => 'Acme Air']);
    Flight::create(['flight_id' => 'f-1', 'airline_id' => $Airline->id, 'name' => 'Boston', 'code' => 'AA100']);
    Flight::create(['flight_id' => 'f-2', 'airline_id' => $Airline->id, 'name' => 'Denver', 'code' => 'AA200', 'status' => 'cancelled']);

    $this->get('/flights/AA100')
        ->assertOk()
        ->assertJsonPath('flight.flight_id', 'f-1')
        ->assertJsonPath('flight.label', 'AA100 Boston')
        ->assertJsonPath('flight.options', [])
        ->assertJsonPath('flight.delayed', false)
        ->assertJsonMissingPath('flight.secret')
        ->assertJsonPath('flight.airline', ['id' => 1, 'name' => 'Acme Air', 'flights_count' => 1]);

    $this->get('/flights/AA200')->assertNotFound();
});

it('does not touch a parent whose timestamps are declared false', function (): void {
    $Airline = Airline::create(['name' => 'Acme Air']);
    $Flight = Flight::create(['flight_id' => 'f-1', 'airline_id' => $Airline->id, 'name' => 'Boston', 'code' => 'AA100']);

    expect(Airline::isIgnoringTouch())->toBeTrue()
        ->and(Flight::isIgnoringTouch())->toBeFalse()
        ->and($Flight->update(['name' => 'Chicago']))->toBeTrue();
});

it('declares nothing without a models block', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/requests.yml']);

    $Flight = new Flight;

    expect($Flight->getTable())->toBe('flights')
        ->and($Flight->getGuarded())->toBe(['*'])
        ->and($Flight->getCasts())->toBe(['id' => 'int', 'departed_at' => 'datetime']);
});

it('ignores unknown model keys', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, <<<'YAML'
        models: [{class: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Flight, fillabel: [name]}]
        YAML);

    expect($this->withConfig(['laravel-declaration.manifest' => $file]))->not->toBeNull();
});

it('ignores a class that is not a DeclaredModel spelled as static::class', function (string $class): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, "models:\n  - class: '$class'\n");

    expect($this->withConfig(['laravel-declaration.manifest' => $file]))->not->toBeNull();
})->with([
    'an Eloquent model' => User::class,
    'a leading backslash' => '\\'.Flight::class,
    'another case' => strtolower(Flight::class),
    'a missing class' => 'App\Models\Missing',
]);

it('reports the declaration through model:show', function (): void {
    Artisan::call('model:show', ['model' => Flight::class, '--json' => true]);

    /** @var array<string, mixed> $info */
    $info = json_decode(Artisan::output(), true);

    expect($info['table'])->toBe('my_flights')
        ->and($info['events'])->toBe([['event' => 'created', 'class' => FlightCreated::class]])
        ->and(array_column($info['observers'], 'event'))->toBe(['creating', 'boarding']);
});
