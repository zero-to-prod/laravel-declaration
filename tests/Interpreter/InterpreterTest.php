<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Interpreter\Recorder;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Interpreter\Statics;
use ZeroToProd\Manifest\Interpreter;

it('calls a present key with no arguments for null and true, in manifest order', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, ['none' => null, 'zero' => true]);

    expect($recorder->calls)->toBe([['none', []], ['zero', []]]);
});

it('lets PHP reject true on a method that requires an argument', function (): void {
    (new Interpreter)->body(new Recorder, ['one' => true]);
})->throws(ArgumentCountError::class);

it('passes false as a scalar', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, ['one' => false]);

    expect($recorder->calls)->toBe([['one', [false]]]);
});

it('reads a list by the first parameter: argument when array-typed, spread when variadic, otherwise one call per item', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, ['typed' => [1, 2], 'spread' => [1, 2], 'one' => [1, 2]]);

    expect($recorder->calls)->toBe([['typed', [[1, 2]]], ['spread', [1, 2]], ['one', [1]], ['one', [2]]]);
});

it('reads a nested list item as the argument', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, ['one' => [[1, 2]]]);

    expect($recorder->calls)->toBe([['one', [[1, 2]]]]);
});

it('reads entries on a two-parameter method, fanning a list value out unless the second parameter is array-typed', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, ['pair' => ['a' => 1, 'b' => [2, 3]], 'pairArray' => ['b' => [2, 3]]]);

    expect($recorder->calls)->toBe([['pair', ['a', 1]], ['pair', ['b', 2]], ['pair', ['b', 3]], ['pairArray', ['b', [2, 3]]]]);
});

it('makes a λ from a map under a Closure parameter', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, ['hook' => ['K' => ['one' => 1]]]);

    [$name, [$key, $closure]] = $recorder->calls[0];

    expect([$name, $key])->toBe(['hook', 'K'])
        ->and($closure)->toBeInstanceOf(Closure::class);

    $inner = new Recorder;

    if ($closure instanceof Closure) {
        $closure($inner);
    }

    expect($inner->calls)->toBe([['one', [1]]]);
});

it('reads a row as named arguments and chains the other keys on the return', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, ['fluent' => ['a' => 1, 'one' => 2]]);

    expect($recorder->calls)->toBe([['fluent', ['a' => 1, 'b' => null]], ['one', [2]]]);
});

it('threads the chain through a different return object', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, ['fluent' => ['a' => 1, 'other' => 2, 'one' => 3]]);

    expect($recorder->calls)->toBe([['fluent', ['a' => 1, 'b' => null]], ['other', [2]]]); // one(3) ran on the new Recorder
});

it('reads a list of rows as one call per row even on an array-typed first parameter', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, ['typed' => [['x' => [1]], ['x' => [2]]]]);

    expect($recorder->calls)->toBe([['typed', [[1]]], ['typed', [[2]]]]);
});

it('lets PHP fail a chain on a void return', function (): void {
    (new Interpreter)->body(new Recorder, ['void' => ['a' => 1, 'one' => 2]]);
})->throws(Error::class, 'Call to a member function one() on null');

it('lets PHP fail an unknown key', function (): void {
    (new Interpreter)->body(new Recorder, ['nope' => 1]);
})->throws(Error::class, 'Call to undefined method');

it('spreads onto a __call surface', function (): void {
    Statics::$calls = [];
    $surface = new Statics;
    (new Interpreter)->body($surface, ['anything' => [1, 2], 'again' => 'x']);

    expect(Statics::$calls)->toBe([['anything', [1, 2]], ['again', ['x']]]);
});

it('treats a key containing a namespace separator as a static receiver, on any receiver including null', function (): void {
    Statics::$calls = [];

    (new Interpreter)->body(null, [Statics::class => ['configure' => 'x']]);

    expect(Statics::$calls)->toBe([['configure', ['x']]]);
});

it('passes a map whose first key is not a parameter name as the argument on a one-parameter method', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, ['one' => ['nope' => 1]]);

    expect($recorder->calls)->toBe([['one', [['nope' => 1]]]]);
});

it('hands every non-λ argument to ρ with the parameter it fills', function (): void {
    $recorder = new Recorder;
    $seen = [];
    $interpreter = new Interpreter(function (mixed $value, ?ReflectionParameter $parameter) use (&$seen): mixed {
        $seen[] = $parameter?->getName();

        return is_string($value) ? strtoupper($value) : $value;
    });

    $interpreter->body($recorder, ['pair' => ['a' => 'x'], 'hook' => ['K' => ['one' => 1]]]);

    expect($recorder->calls[0])->toBe(['pair', ['a', 'X']])
        ->and($seen)->toBe(['v']);                // an entry key is passed raw (rule 7); the λ never reached ρ
});
