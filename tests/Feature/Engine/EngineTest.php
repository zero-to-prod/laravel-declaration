<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Internal\Engine\Engine;
use ZeroToProd\LaravelDeclaration\Internal\Engine\Resolve;
use ZeroToProd\LaravelDeclaration\Tests\Feature\Engine\Support;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine\Arbitrary;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine\Child;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine\Dynamic;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine\Implementation;

function engine(?Closure $guard = null): Engine
{
    return new Engine(Support::schema(), app(), new Resolve(app()), $guard);
}

beforeEach(function (): void {
    Arbitrary::$static = [];
});

it('applies every form end to end on a receiver, in manifest order', function (): void {
    $receiver = new Arbitrary;

    engine()->body($receiver, [
        'flush' => null,                                   // row 1
        'name' => 'x',                                     // row 2
        'enabled' => false,                                // row 2 — false calls
        'options' => ['a', 'b'],                           // row 3
        'tags' => ['a', 'b'],                              // row 4 — the curated order: reverse applies to fan-outs (rows 5 and 7) only
        'label' => ['a', 'b'],                             // row 5, curated resolve: path
        'set' => ['k' => ['v1', 'v2'], 'solo' => 1],       // row 7, curated order: reverse on the list value
        'group' => ['web' => ['A']],                       // row 7, array-typed second parameter
        'inherited' => 'parent',                           // a parent method
        'aliased' => 'alias',                              // a trait alias
    ]);

    expect($receiver->calls)->toBe([
        ['flush', []],
        ['name', ['x']],
        ['enabled', [false]],
        ['options', [['a', 'b']]],
        ['tags', ['a', 'b']],
        ['label', [base_path('a')]],
        ['label', [base_path('b')]],
        ['set', ['k', 'v2']],
        ['set', ['k', 'v1']],
        ['set', ['solo', 1]],
        ['group', ['web', ['A']]],
        ['inherited', ['parent']],
        ['fromTrait', ['alias']],
    ]);
});

it('threads the return value through a chain, keeping the receiver on void returns', function (): void {
    $receiver = new Arbitrary;

    engine()->body($receiver, [
        'route' => [
            ['methods' => 'GET', 'uri' => '/', 'action' => 'Home', 'name' => 'home', 'where' => ['id' => '[0-9]+'], 'constrained' => 'users', 'cascadeOnDelete' => null, 'nullable' => null],
        ],
        'self' => ['value' => 'a', 'name' => 'rides-self'],   // self() returns $this: the rest rides the same receiver
    ]);

    expect($receiver->calls)->toBe([['route', ['GET', '/', 'Home']], ['self', ['a']], ['name', ['rides-self']]])
        ->and($receiver->child?->calls)->toBe([['name', ['home']], ['where', ['id', '[0-9]+']], ['constrained', ['users']]])
        ->and($receiver->child?->grandchild?->calls)->toBe([['cascadeOnDelete', []], ['nullable', []]]);   // void returns keep the Grandchild

    $receiver = new Arbitrary;

    engine()->body($receiver, ['route' => [['methods' => 'GET', 'uri' => '/', 'constrained' => ['table' => 'users', 'cascadeOnDelete' => null]]]]);   // a row's rest rides its own return

    expect($receiver->child?->calls)->toBe([['constrained', ['users']]])
        ->and($receiver->child?->grandchild?->calls)->toBe([['cascadeOnDelete', []]]);
});

it('runs a map under a closure parameter as a body on the closure argument', function (): void {
    $receiver = new Arbitrary;

    engine()->body($receiver, [
        'listen' => ['evt' => ['flush' => null, 'name' => 'inner']],
        'booted' => ['name' => 'hooked'],
        'handler' => ['set' => ['k' => 'v']],
    ]);

    $inner = new Arbitrary;

    foreach ($receiver->calls as [$method, $arguments]) {
        $closure = $arguments[1] ?? $arguments[0];

        expect($closure)->toBeInstanceOf(Closure::class);

        $closure($inner);
    }

    expect($inner->calls)->toBe([['flush', []], ['name', ['inner']], ['name', ['hooked']], ['set', ['k', 'v']]]);
});

it('dispatches every key of an unknown receiver as (...$arguments)', function (): void {
    $dynamic = new Dynamic;

    engine()->body($dynamic, ['spread' => [1, 2], 'map' => ['a' => 'b'], 'plain' => 'x', 'none' => null]);

    expect($dynamic->calls)->toBe([['spread', [1, 2]], ['map', [['a' => 'b']]], ['plain', ['x']], ['none', []]]);

    $receiver = new Arbitrary;

    engine()->body($receiver, ['fluent' => ['value' => 'v', 'anything' => 'goes']]);   // the rest rides the unprojected return

    expect($receiver->calls)->toBe([['fluent', ['v']]]);
});

it('finds the definition of a receiver through its interfaces', function (): void {
    $receiver = new Implementation;

    engine()->body($receiver, ['define' => ['edit' => 'App\Policy@edit']]);

    expect($receiver->calls)->toBe([['define', ['edit', 'App\Policy@edit']]]);
});

it('calls a static key statically on an instance receiver', function (): void {
    engine()->body(new Arbitrary, ['preset' => 'tailwind']);

    expect(Arbitrary::$static)->toBe([['preset', ['tailwind']]]);
});

it('treats a projected class with static keys as a static receiver at the root, and skips data keys', function (): void {
    engine()->body(app(), [
        'requests' => [['name' => 'stored, never dispatched']],
        Arbitrary::class => ['preset' => 'bootstrap', 'reset' => null],
        Child::class => 'not a body',
    ]);

    expect(Arbitrary::$static)->toBe([['preset', ['bootstrap']], ['reset', []]]);
})->throws(BadMethodCallException::class, 'Method Illuminate\\Foundation\\Application::'.Child::class.' does not exist.'); // a projected class without static keys is not a receiver

it('ignores a non-map value under a static receiver', function (): void {
    engine()->body(app(), [Arbitrary::class => 'not a body']);

    expect(Arbitrary::$static)->toBeEmpty();
});

it('fails natively when a root key is neither a method, a data key nor a static receiver', function (): void {
    engine()->body(app(), ['bogus' => null]);
})->throws(BadMethodCallException::class, 'Method Illuminate\Foundation\Application::bogus does not exist.');

it('fails natively for an unknown class-string receiver', function (): void {
    engine()->body('App\Nope', ['x' => null]);
})->throws(Error::class);

it('fails natively when a key rides a scalar return', function (): void {
    engine()->body(new Arbitrary, ['scalar' => ['value' => 'x', 'more' => null]]);
})->throws(Error::class, 'Call to a member function more() on int');

it('consults the guard per call with the receiver, key, arguments and rest', function (): void {
    $receiver = new Arbitrary;
    $seen = [];

    engine(static function (object|string $t, string $key, array $args, array $rest) use (&$seen): bool {
        $seen[] = [is_object($t) ? $t::class : $t, $key, $args, $rest];

        return $key !== 'name';
    })->body($receiver, ['name' => 'skipped', 'set' => ['a' => 1], 'route' => [['methods' => 'GET', 'uri' => '/', 'name' => 'home']]]);

    expect($receiver->calls)->toBe([['set', ['a', 1]], ['route', ['GET', '/']]])
        ->and($seen)->toBe([
            [Arbitrary::class, 'name', ['skipped'], []],
            [Arbitrary::class, 'set', ['a', 1], []],
            [Arbitrary::class, 'route', ['methods' => 'GET', 'uri' => '/'], ['name' => 'home']],   // chain calls are not guarded
        ]);
});

it('returns a guarded clone, leaving the original unguarded', function (): void {
    $engine = engine();
    $guarded = $engine->withGuard(static fn (): bool => false);
    $receiver = new Arbitrary;

    $guarded->body($receiver, ['name' => 'skipped']);
    $engine->body($receiver, ['name' => 'applied']);

    expect($receiver->calls)->toBe([['name', ['applied']]]);
});
