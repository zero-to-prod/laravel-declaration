<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Interpreter\Recorder;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Interpreter\Statics;
use ZeroToProd\Manifest\Interpreter;

it('calls each invocation node in manifest order, with no arguments when args is absent or empty', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, [['method' => 'none'], ['method' => 'zero', 'args' => []]]);

    expect($recorder->calls)->toBe([['none', []], ['zero', []]]);
});

it('forwards args positionally, exactly as written', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, [
        ['method' => 'one', 'args' => [false]],
        ['method' => 'one', 'args' => [[1, 2]]],
        ['method' => 'one', 'args' => [['nope' => 1]]],
        ['method' => 'typed', 'args' => [[1, 2]]],
        ['method' => 'spread', 'args' => [1, 2]],
        ['method' => 'pair', 'args' => ['a', [2, 3]]],
    ]);

    expect($recorder->calls)->toBe([
        ['one', [false]],
        ['one', [[1, 2]]],
        ['one', [['nope' => 1]]],
        ['typed', [[1, 2]]],
        ['spread', [1, 2]],
        ['pair', ['a', [2, 3]]],
    ]);
});

it('forwards a string-keyed args as PHP named arguments', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, [['method' => 'fluent', 'args' => ['b' => 2, 'a' => 1]]]);

    expect($recorder->calls)->toBe([['fluent', ['a' => 1, 'b' => 2]]]);
});

it('lets PHP reject a missing argument', function (): void {
    (new Interpreter)->body(new Recorder, [['method' => 'one']]);
})->throws(ArgumentCountError::class);

it('lets PHP reject an unknown named argument', function (): void {
    (new Interpreter)->body(new Recorder, [['method' => 'fluent', 'args' => ['nope' => 1]]]);
})->throws(Error::class, 'Unknown named parameter $nope');

it('never fans out: a list is one argument even on a one-parameter method', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, [['method' => 'one', 'args' => [['x', 'y']]]]);

    expect($recorder->calls)->toBe([['one', [['x', 'y']]]]);
});

it('makes a λ from a body under a Closure parameter', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, [['method' => 'hook', 'args' => ['K', [['method' => 'one', 'args' => [1]]]]]]);

    [$name, [$key, $closure]] = $recorder->calls[0];

    expect([$name, $key])->toBe(['hook', 'K'])
        ->and($closure)->toBeInstanceOf(Closure::class);

    $inner = new Recorder;

    if ($closure instanceof Closure) {
        $closure($inner);
    }

    expect($inner->calls)->toBe([['one', [1]]]);
});

it('passes a list under a non-Closure parameter through as the argument', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, [['method' => 'pairArray', 'args' => ['k', [['method' => 'one']]]]]);

    expect($recorder->calls)->toBe([['pairArray', ['k', [['method' => 'one']]]]]);
});

it('chains then on the return value, left to right', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, [['method' => 'fluent', 'args' => [1], 'then' => [['method' => 'fluent', 'args' => [2]], ['method' => 'one', 'args' => [3]]]]]);

    expect($recorder->calls)->toBe([['fluent', ['a' => 1, 'b' => null]], ['fluent', ['a' => 2, 'b' => null]], ['one', [3]]]);
});

it('threads the chain through a different return object', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, [['method' => 'fluent', 'args' => [1], 'then' => [['method' => 'other', 'args' => [2]], ['method' => 'one', 'args' => [3]]]]]);

    expect($recorder->calls)->toBe([['fluent', ['a' => 1, 'b' => null]], ['other', [2]]]); // one(3) ran on the new Recorder
});

it('continues a nested then as the same chain', function (): void {
    $recorder = new Recorder;
    (new Interpreter)->body($recorder, [['method' => 'fluent', 'args' => [1], 'then' => [
        ['method' => 'other', 'args' => [2], 'then' => [['method' => 'fluent', 'args' => [3]]]],
        ['method' => 'one', 'args' => [4]],
    ]]]);

    expect($recorder->calls)->toBe([['fluent', ['a' => 1, 'b' => null]], ['other', [2]]]); // fluent(3) and one(4) ran on the new Recorder
});

it('lets PHP fail a then on a void return', function (): void {
    (new Interpreter)->body(new Recorder, [['method' => 'void', 'args' => [1], 'then' => [['method' => 'one', 'args' => [2]]]]]);
})->throws(Error::class, 'Call to a member function one() on null');

it('lets PHP fail an unknown method', function (): void {
    (new Interpreter)->body(new Recorder, [['method' => 'nope']]);
})->throws(Error::class, 'Call to undefined method');

it('lets PHP fail a node without a method', function (): void {
    (new Interpreter)->body(new Recorder, [['args' => [1]]]);
})->throws(Error::class);

it('dispatches onto a __call surface with the args as written', function (): void {
    Statics::$calls = [];
    (new Interpreter)->body(new Statics, [['method' => 'anything', 'args' => [1, 2]], ['method' => 'again', 'args' => ['x']]]);

    expect(Statics::$calls)->toBe([['anything', [1, 2]], ['again', ['x']]]);
});

it('dispatches a receiver node statically, on any receiver including null', function (): void {
    Statics::$calls = [];
    (new Interpreter)->body(null, [['receiver' => Statics::class, 'calls' => [['method' => 'configure', 'args' => ['x']]]]]);

    expect(Statics::$calls)->toBe([['configure', ['x']]]);
});

it('lets PHP fail an invocation node on a null receiver', function (): void {
    (new Interpreter)->body(null, [['method' => 'configure']]);
})->throws(Error::class);

it('hands every non-λ argument to ρ with the parameter it fills: by position, by name, the variadic tail, or null on __call', function (): void {
    $recorder = new Recorder;
    $seen = [];
    $interpreter = new Interpreter(function (mixed $value, ?ReflectionParameter $parameter) use (&$seen): mixed {
        $seen[] = $parameter?->getName();

        return is_string($value) ? strtoupper($value) : $value;
    });

    $interpreter->body($recorder, [
        ['method' => 'pair', 'args' => ['x', 'y']],
        ['method' => 'fluent', 'args' => ['a' => 'p', 'b' => 'q']],
        ['method' => 'spread', 'args' => ['s', 't', 'u']],
        ['method' => 'hook', 'args' => ['K', [['method' => 'one', 'args' => [1]]]]],
    ]);
    $interpreter->body(new Statics, [['method' => 'dyn', 'args' => ['z']]]);

    expect($recorder->calls[0])->toBe(['pair', ['X', 'Y']])
        ->and($recorder->calls[1])->toBe(['fluent', ['a' => 'P', 'b' => 'Q']])
        ->and($recorder->calls[2])->toBe(['spread', ['S', 'T', 'U']])
        ->and($seen)->toBe(['k', 'v', 'a', 'b', 'x', 'x', 'x', 'k', null]);   // the λ never reached ρ
});
