<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Internal\Engine\Forms;
use ZeroToProd\LaravelDeclaration\Internal\Engine\Signature;
use ZeroToProd\LaravelDeclaration\Tests\Feature\Engine\Support;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine\Arbitrary;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine\Child;

/** ρ tags every resolved value with the parameter it was resolved for, so the assertions see which parameter ρ saw. */
function rho(): Closure
{
    return static fn (mixed $value, array $param): mixed => is_scalar($value) ? "ρ($value|{$param['name']})" : $value;
}

/** λ returns a Closure that yields its body, so the assertions can unwrap it. */
function lambda(): Closure
{
    return static fn (array $data): Closure => static fn (): array => $data;
}

/** @return list<array{0: array<int|string, mixed>, 1: array<string, mixed>}> */
function callsOf(string $method, mixed $value, array $curation = []): array
{
    return Forms::calls(Support::signature($method), $curation, $value, rho(), lambda());
}

function body(mixed $closure): mixed
{
    return $closure instanceof Closure ? $closure() : null;
}

// ── row 1 — null | true → m() ────────────────────────────────────────────────────────────────────────────────────

it('calls with no arguments for null and true, on any arity', function (): void {
    expect(callsOf('flush', null))->toBe([[[], []]])
        ->and(callsOf('flush', true))->toBe([[[], []]])
        ->and(callsOf('route', null))->toBe([[[], []]])
        ->and(Support::validates('flush', null))->toBeTrue()
        ->and(Support::validates('flush', true))->toBeTrue()
        ->and(Support::validates('flush', 5))->toBeFalse()               // a zero-parameter method admits nothing else
        ->and(Support::validates('flush', 'x'))->toBeFalse();
});

// ── row 2 — scalar → m(ρ(value, p0)) ─────────────────────────────────────────────────────────────────────────────

it('passes a scalar as the first argument through ρ, false included', function (): void {
    expect(callsOf('name', 'x'))->toBe([[['ρ(x|name)'], []]])
        ->and(callsOf('enabled', false))->toBe([[['ρ(|enabled)'], []]])
        ->and(callsOf('flush', 5))->toBe([[[5], []]])                    // no p0: the value is passed untouched, PHP decides
        ->and(Support::validates('name', 'x'))->toBeTrue()
        ->and(Support::validates('name', 5))->toBeFalse()                // typed: string
        ->and(Support::validates('retries', 5))->toBeTrue()              // ?int → integer
        ->and(Support::validates('retries', 'x'))->toBeFalse()
        ->and(Support::validates('ratio', 1.5))->toBeTrue()
        ->and(Support::validates('enabled', false))->toBeTrue()
        ->and(Support::validates('scale', 'x'))->toBeTrue()              // string|int → untyped: every scalar
        ->and(Support::validates('label', 'x'))->toBeTrue()              // curated resolve: path → string
        ->and(Support::validates('label', 5))->toBeFalse()
        ->and(Support::validates('options', 'x'))->toBeFalse()           // an array-typed first parameter admits no scalar
        ->and(Support::validates('handler', 'App\Hook@run'))->toBeTrue() // a Closure parameter admits a reference
        ->and(Support::validates('handler', 'not a reference'))->toBeFalse()
        ->and(Support::validates('compiler', 'App\Compile::run'))->toBeTrue();
});

// ── row 3 — list, p0 array or list: argument → m(list) ───────────────────────────────────────────────────────────

it('passes a list whole when the first parameter is array-typed or curated list: argument', function (): void {
    expect(callsOf('options', ['a', 'b']))->toBe([[[['a', 'b']], []]])
        ->and(callsOf('scale', ['a', 'b'], ['list' => 'argument']))->toBe([[[['a', 'b']], []]])
        ->and(Support::validates('options', ['a', 'b']))->toBeTrue()
        ->and(Support::validates('scale', ['a', 'b']))->toBeTrue();
});

// ── row 4 — list, p0 variadic → m(...ρ(item)) ────────────────────────────────────────────────────────────────────

it('spreads a list into a variadic first parameter', function (): void {
    expect(callsOf('tags', ['a', 'b']))->toBe([[['ρ(a|tags)', 'ρ(b|tags)'], []]])
        ->and(Support::validates('tags', ['a', 'b']))->toBeTrue()
        ->and(Support::validates('tags', [1]))->toBeFalse();            // items: string
});

// ── row 5 — list, otherwise → one call per item; a map item is a row ─────────────────────────────────────────────

it('fans a list out to one call per item, a map item being a row', function (): void {
    expect(callsOf('label', ['a', 'b']))->toBe([[['ρ(a|label)'], []], [['ρ(b|label)'], []]])
        ->and(callsOf('label', ['a', 'b'], ['order' => 'reverse']))->toBe([[['ρ(b|label)'], []], [['ρ(a|label)'], []]])
        ->and(callsOf('route', [['methods' => 'GET', 'uri' => '/', 'name' => 'home'], 'POST']))->toBe([
            [['methods' => 'ρ(GET|methods)', 'uri' => 'ρ(/|uri)'], ['name' => 'home']],
            [['ρ(POST|methods)'], []],
        ])
        ->and(callsOf('label', [[]]))->toBe([[[[]], []]])                // an empty array item is a list, not a map
        ->and(Support::validates('route', [['methods' => 'GET', 'uri' => '/']]))->toBeTrue()
        ->and(Support::validates('route', [['uri' => '/']]))->toBeFalse() // a map item is a row: the first parameter is required
        ->and(Support::validates('route', ['GET']))->toBeTrue();
});

// ── row 6 — map, form: entries → m(k) with the value as a body on the return ─────────────────────────────────────

it('treats a curated entries map as one call per key whose value rides the return', function (): void {
    expect(callsOf('when', ['App\A' => ['needs' => 'X'], 'App\B' => null], ['form' => 'entries']))->toBe([
        [['App\A'], ['needs' => 'X']],
        [['App\B'], []],
    ])
        ->and(Support::validates('when', ['App\A' => ['needs' => 'X'], 'App\B' => null]))->toBeTrue()
        ->and(Support::validates('when', ['App\A' => 'x']))->toBeFalse();
});

// ── row 7 — map, n ≥ 2, p0 not array → entries per (k, v) ────────────────────────────────────────────────────────

it('calls once per entry with the key first, fanning a list value out unless the second parameter is array-typed', function (): void {
    expect(callsOf('set', ['a' => 1, 'b' => ['x', 'y']]))->toBe([
        [['a', 'ρ(1|value)'], []],
        [['b', 'ρ(x|value)'], []],
        [['b', 'ρ(y|value)'], []],
    ])
        ->and(callsOf('set', ['b' => ['x', 'y']], ['order' => 'reverse']))->toBe([[['b', 'ρ(y|value)'], []], [['b', 'ρ(x|value)'], []]])
        ->and(callsOf('set', ['a' => ['k' => 'v']]))->toBe([[['a', ['k' => 'v']], []]]) // a map value is the argument when p1 is not a closure
        ->and(callsOf('group', ['web' => ['A', 'B']]))->toBe([[['web', ['A', 'B']], []]])
        ->and(Support::validates('group', ['web' => ['A', 'B']]))->toBeTrue()
        ->and(Support::validates('group', ['web' => 'A']))->toBeFalse()   // array-typed second parameter
        ->and(Support::validates('set', ['a' => 1, 'b' => ['x', 'y'], 'c' => ['k' => 'v']]))->toBeTrue();
});

it('wraps a map value under a closure second parameter as a body on the closure argument', function (): void {
    $calls = callsOf('listen', ['evt' => ['flush' => null], 'other' => 'App\Listener', 'many' => ['A@a', 'B@b']]);

    expect($calls[0][0][0])->toBe('evt')
        ->and(body($calls[0][0][1]))->toBe(['flush' => null])
        ->and($calls[1])->toBe([['other', 'ρ(App\Listener|callback)'], []])
        ->and($calls[2])->toBe([['many', 'ρ(A@a|callback)'], []])
        ->and($calls[3])->toBe([['many', 'ρ(B@b|callback)'], []])
        ->and(Support::validates('listen', ['evt' => ['flush' => null], 'other' => 'App\Listener', 'many' => ['A@a', 'B@b']]))->toBeTrue()
        ->and(Support::validates('listen', ['evt' => 5]))->toBeFalse()
        ->and(Support::validates('listen', [Arbitrary::class => ['flush' => null]]))->toBeTrue()
        ->and(Support::validates('listen', [Arbitrary::class => ['nope' => null]]))->toBeFalse(); // a projected FQCN key validates its body
});

// ── row 8 — map, p0 is closure → m(λ(map)) ───────────────────────────────────────────────────────────────────────

it('wraps a map under a closure first parameter as a body, typed or curated', function (): void {
    $typed = callsOf('handler', ['flush' => null]);
    $curated = callsOf('booted', ['flush' => null, 'name' => 'x'], ['closure' => ['hook']]);

    expect(body($typed[0][0][0]))->toBe(['flush' => null])
        ->and(body($curated[0][0][0]))->toBe(['flush' => null, 'name' => 'x'])
        ->and(callsOf('booted', ['App\Hook'], ['closure' => ['hook']]))->toBe([[['ρ(App\Hook|hook)'], []]]) // a list item is a reference
        ->and(Support::validates('booted', ['flush' => null, 'name' => 'x']))->toBeTrue()                       // $ref to the receiver's own definition
        ->and(Support::validates('booted', ['nope' => null]))->toBeFalse()
        ->and(Support::validates('booted', 5))->toBeFalse();
});

// ── row 9 — map, otherwise → m(map) ──────────────────────────────────────────────────────────────────────────────

it('passes a map whole when no other map row applies', function (): void {
    expect(callsOf('options', ['x' => 1]))->toBe([[[['x' => 1]], []]])
        ->and(callsOf('label', ['x' => 1]))->toBe([[[['x' => 1]], []]])
        ->and(Support::validates('options', ['x' => 1]))->toBeTrue();
});

// ── row 10 — map, first key ∈ names(P) → named arguments; the rest rides the return ──────────────────────────────

it('calls with named arguments and keeps the other keys for the return value, in manifest order', function (): void {
    expect(callsOf('route', ['methods' => 'GET', 'uri' => '/', 'name' => 'home', 'where' => ['id' => '1'], 7 => 'seven']))->toBe([
        [['methods' => 'ρ(GET|methods)', 'uri' => 'ρ(/|uri)'], ['name' => 'home', 'where' => ['id' => '1'], '7' => 'seven']],
    ])
        ->and(callsOf('set', ['key' => 'a', 'value' => ['k' => 'v']]))->toBe([[['key' => 'ρ(a|key)', 'value' => ['k' => 'v']], []]]);

    $closure = callsOf('listen', ['event' => 'evt', 'callback' => ['flush' => null]]);

    expect($closure[0][0]['event'])->toBe('ρ(evt|event)')
        ->and(body($closure[0][0]['callback']))->toBe(['flush' => null])
        ->and(Support::validates('route', ['methods' => 'GET', 'uri' => '/', 'name' => 'home']))->toBeTrue()
        ->and(Support::validates('listen', ['event' => 'evt', 'callback' => ['flush' => null]]))->toBeTrue()
        ->and(Support::validates('listen', ['event' => 'evt', 'callback' => 5]))->toBeFalse();
});

// ── emit ─────────────────────────────────────────────────────────────────────────────────────────────────────────

it('emits the stub with a TODO for every parameter whose element type is unknown', function (): void {
    expect(Forms::stub(Support::signature('route'), []))->toBe('-> route($methods, $uri, $action) TODO(route: $methods, $uri, $action)')
        ->and(Forms::stub(Support::signature('name'), []))->toBe('-> name($name)')
        ->and(Forms::stub(Support::signature('flush'), []))->toBe('-> flush()')
        ->and(Forms::stub(Support::signature('label'), ['resolve' => ['label' => 'path']]))->toBe('-> label($label)');
});

it('maps every signature type to its JSON type, the curated vocabulary winning', function (): void {
    $param = static fn (?string $type, string $name = 'p'): array => ['name' => $name, 'type' => $type, 'variadic' => false];

    expect(Forms::type($param(Signature::STRING), []))->toBe(['type' => 'string'])
        ->and(Forms::type($param(Signature::INT), []))->toBe(['type' => 'integer'])
        ->and(Forms::type($param(Signature::FLOAT), []))->toBe(['type' => 'number'])
        ->and(Forms::type($param(Signature::BOOL), []))->toBe(['type' => 'boolean'])
        ->and(Forms::type($param(Signature::ARRAY), []))->toBe(['type' => ['array', 'object']])
        ->and(Forms::type($param(Signature::CLOSURE), []))->toBe(['$ref' => '#/definitions/closure'])
        ->and(Forms::type($param(null), []))->toBeTrue()
        ->and(Forms::type($param(null), ['closure' => ['p']]))->toBe(['$ref' => '#/definitions/closure'])
        ->and(Forms::type($param(null), ['resolve' => ['p' => 'path']]))->toBe(['$ref' => '#/definitions/path'])
        ->and(Forms::type($param(null), ['resolve' => ['p' => 'phpFile']]))->toBeTrue(); // a `.php` file is one option; anything else passes through
});

it('emits x-manifest with the signature and only the curation keywords', function (): void {
    $schema = Forms::schema(Support::signature('set'), ['order' => 'reverse', 'description' => 'ignored', 'bogus' => 1], Support::CLASSES, Arbitrary::class);

    expect($schema['x-manifest'])->toBe(['params' => ['key' => null, 'value' => null], 'order' => 'reverse'])
        ->and(Forms::schema(Support::signature('preset'), [], [], Arbitrary::class)['x-manifest'])->toBe(['static' => true, 'params' => ['view' => 'string']])
        ->and(Forms::schema(Support::signature('tags'), [], [], Arbitrary::class)['x-manifest'])->toBe(['params' => ['...tags' => 'string']]);
});

it('emits the row forms the signature admits and nothing else', function (): void {
    $rows = static fn (string $method, array $curation = []): array => array_map(
        static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        Forms::schema(Support::signature($method), $curation, [], Arbitrary::class)['anyOf'],
    );

    expect($rows('flush'))->toBe(['{"enum":[null,true]}'])
        ->and($rows('options'))->toBe(['{"enum":[null,true]}', '{"type":"array"}', '{"type":"object"}', '{"type":"object","properties":{"options":{"type":["array","object"]}},"required":["options"]}'])
        ->and($rows('tags'))->toBe(['{"enum":[null,true]}', '{"type":"string"}', '{"type":"array","items":{"type":"string"}}', '{"type":"object"}', '{"type":"object","properties":{"tags":{"type":"string"}},"required":["tags"]}'])
        ->and($rows('set'))->toBe([
            '{"type":["null","boolean","string","integer","number"]}',
            '{"type":"array","items":{"anyOf":[{"not":{"type":"object"}},{"$ref":"#/definitions/ZeroToProd\\\\LaravelDeclaration\\\\Tests\\\\Fixtures\\\\Engine\\\\Arbitrary/properties/set/anyOf/3"}]}}',
            '{"type":"object","propertyNames":{"not":{"enum":["key","value"]}}}',
            '{"type":"object","required":["key"]}',
        ])
        ->and($rows('when', ['form' => 'entries']))->toContain('{"type":"object","additionalProperties":{"type":["object","null"]}}')
        ->and($rows('handler'))->toContain('{"$ref":"#/definitions/ZeroToProd\\\\LaravelDeclaration\\\\Tests\\\\Fixtures\\\\Engine\\\\Arbitrary"}');
});

it('recognises a closure parameter by type or by curation', function (): void {
    expect(Forms::isClosure(null, []))->toBeFalse()
        ->and(Forms::isClosure(['name' => 'x', 'type' => Signature::CLOSURE, 'variadic' => false], []))->toBeTrue()
        ->and(Forms::isClosure(['name' => 'x', 'type' => null, 'variadic' => false], ['closure' => ['x']]))->toBeTrue()
        ->and(Forms::isClosure(['name' => 'x', 'type' => null, 'variadic' => false], ['closure' => 'x']))->toBeFalse();
});

it('round-trips a signature through its x-manifest form', function (): void {
    $signature = Support::signature('route', Arbitrary::class);
    $again = Signature::fromArray('route', $signature->toArray());

    expect($again->toArray())->toBe($signature->toArray())
        ->and($again->names())->toBe(['methods', 'uri', 'action'])
        ->and($again->named('uri'))->toBe(['name' => 'uri', 'type' => null, 'variadic' => false])
        ->and($again->named('nope'))->toBeNull()
        ->and(Signature::fromArray('x', [])->params)->toBeEmpty()
        ->and(Signature::fromArray('x', ['static' => true, 'params' => ['...rest' => 'int']])->params)->toBe([['name' => 'rest', 'type' => 'int', 'variadic' => true]])
        ->and(Support::signature('name', Child::class)->static)->toBeFalse();
});
