<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Engine;

use Closure;

/**
 * THE FORMS — Σ[m] × value → calls (§1.2), one table with two projections:
 * `schema()` EMITS every form a key accepts, `calls()` PARSES the one form a value takes.
 * Both derive from the signature and the per-key curation and nothing else.
 *
 * Rows (first match wins):
 *   1  null | true                                       m()
 *   2  scalar                                            m(ρ(value, p0))
 *   3  list, p0.type = array ∨ list: argument            m(value)   — unless every item is a row (then row 5)
 *   4  list, p0 variadic                                 m(...ρ(item, p0))
 *   5  list, otherwise                                   one call per item (map item → row 10)
 *   6  map, first key ∉ names(P), form: entries          m(k) with rest = v
 *   7  map, first key ∉ names(P), n ≥ 2, p0.type ≠ array ENTRIES per (k, v)
 *   8  map, first key ∉ names(P), p0 is closure          m(λ(value))
 *   9  map, first key ∉ names(P), otherwise              m(value)
 *  10  map, first key ∈ names(P)                         ROW — m(...named), rest rides the return
 *
 * @phpstan-import-type Param from Signature
 *
 * @phpstan-type Curation array<string, mixed>
 * @phpstan-type Call array{0: array<int|string, mixed>, 1: array<string, mixed>}
 *
 * @internal
 */
final class Forms
{
    /** The unknown-key signature (a `__call` surface): dispatched as `(...$arguments)` — PHP decides. */
    public const array VARIADIC = ['x-manifest' => ['params' => ['...arguments' => null]]];

    /** The curation keywords the forms admit (§2.2); everything else on `x-manifest` is signature metadata. */
    public const array CURATION = ['form', 'list', 'order', 'closure', 'resolve', 'data'];

    /**
     * @param  Param|null  $param
     * @param  Curation  $curation
     */
    public static function isClosure(?array $param, array $curation): bool
    {
        if ($param === null) {
            return false;
        }

        $curated = $curation['closure'] ?? [];

        return $param['type'] === Signature::CLOSURE || (is_array($curated) && in_array($param['name'], $curated, true));
    }

    /**
     * Parses one manifest value into the calls it denotes.
     *
     * @param  Curation  $curation
     * @param  Closure(mixed, Param): mixed  $resolve  ρ
     * @param  Closure(array<mixed>): Closure  $lambda  λ
     * @return list<Call>
     */
    public static function calls(Signature $signature, array $curation, mixed $value, Closure $resolve, Closure $lambda): array
    {
        $p0 = $signature->param(0);
        $p1 = $signature->param(1);
        $count = count($signature->params);
        $reverse = ($curation['order'] ?? null) === 'reverse';

        if ($value === null || $value === true) {                                   // row 1
            return [[[], []]];
        }

        if (! is_array($value)) {                                                   // row 2
            return [[[$p0 === null ? $value : $resolve($value, $p0)], []]];
        }

        if (array_is_list($value)) {
            if ((($p0['type'] ?? null) === Signature::ARRAY || ($curation['list'] ?? null) === 'argument') && ! self::isRows($signature, $value)) {
                return [[[$value], []]];                                            // row 3 — the list IS the argument
            }

            if ($p0 !== null && $p0['variadic']) {                                  // row 4
                return [[array_map(static fn (mixed $item): mixed => $resolve($item, $p0), $value), []]];
            }

            $calls = [];                                                            // row 5

            foreach ($reverse ? array_reverse($value) : $value as $item) {
                $calls[] = self::isMap($item)
                    ? self::row($signature, $curation, $item, $resolve, $lambda)
                    : [[$p0 === null ? $item : $resolve($item, $p0)], []];
            }

            return $calls;
        }

        if (in_array(array_key_first($value), $signature->names(), true)) {          // row 10
            return [self::row($signature, $curation, $value, $resolve, $lambda)];
        }

        if (($curation['form'] ?? null) === 'entries') {                             // row 6
            $calls = [];

            foreach ($value as $key => $body) {
                $calls[] = [[$key], is_array($body) ? $body : []];
            }

            return $calls;
        }

        if ($count >= 2 && $p1 !== null && ($p0['type'] ?? null) !== Signature::ARRAY) { // row 7
            $calls = [];

            foreach ($value as $key => $entry) {
                if (is_array($entry) && array_is_list($entry) && $p1['type'] !== Signature::ARRAY) {
                    foreach ($reverse ? array_reverse($entry) : $entry as $item) {
                        $calls[] = [[$key, $resolve($item, $p1)], []];
                    }

                    continue;
                }

                $calls[] = self::isMap($entry) && self::isClosure($p1, $curation)
                    ? [[$key, $lambda($entry)], []]
                    : [[$key, $resolve($entry, $p1)], []];
            }

            return $calls;
        }

        if (self::isClosure($p0, $curation)) {                                      // row 8
            return [[[$lambda($value)], []]];
        }

        return [[[$value], []]];                                                    // row 9 — the map IS the argument
    }

    /**
     * Emits the key schema: `anyOf` of every row the signature admits (the ROW last, referenced by row 5 through
     * a JSON pointer), plus `x-manifest`.
     *
     * @param  Curation  $curation
     * @param  list<class-string>  $classes  the projected FQCNs (row 6 / 7 bodies, through the shared `bodies` definition)
     * @param  string  $receiver  the definition's own FQCN (row 8, and the ROW pointer)
     * @return array<string, mixed>
     */
    public static function schema(Signature $signature, array $curation, array $classes, string $receiver): array
    {
        $p0 = $signature->param(0);
        $p1 = $signature->param(1);
        $count = count($signature->params);
        $rows = [['enum' => [null, true]]];                                                     // row 1

        if ($p0 !== null && $p0['type'] !== Signature::ARRAY) {
            $scalar = self::type($p0, $curation);

            $rows = $scalar === true
                ? [['type' => ['null', 'boolean', 'string', 'integer', 'number']]]               // rows 1 + 2: an unknown first argument admits every scalar
                : [...$rows, $scalar];                                                           // row 2
        }

        $list = null;
        $item = true;

        if ($p0 !== null) {
            if ($p0['type'] === Signature::ARRAY || ($curation['list'] ?? null) === 'argument') {
                $rows[] = ['type' => 'array'];                                                 // row 3
            } elseif ($p0['variadic']) {
                $rows[] = ['type' => 'array', 'items' => self::type($p0, $curation)];          // row 4
            } else {
                $list = count($rows);                                                          // row 5 — items reference the ROW below
                $item = self::type($p0, $curation);
                $rows[] = ['type' => 'array'];
            }
        }

        if (($curation['form'] ?? null) === 'entries') {                                        // row 6
            $rows[] = ['type' => 'object', 'additionalProperties' => ['type' => ['object', 'null']], ...self::bodies($classes)];
        } elseif ($count >= 2 && $p1 !== null && ($p0['type'] ?? null) !== Signature::ARRAY) {  // row 7
            $rows[] = self::entries($signature, $p1, $curation, $classes);
        } elseif (self::isClosure($p0, $curation)) {                                            // row 8
            $rows[] = ['$ref' => '#/definitions/'.$receiver];
        } elseif ($p0 !== null) {                                                               // row 9
            $rows[] = ['type' => 'object'];
        }

        if ($p0 !== null) {
            $rows[] = self::rowSchema($signature, $curation);                                   // row 10 — last
        }

        if ($list !== null) {
            $rows[$list]['items'] = ['anyOf' => [
                $item === true ? ['not' => ['type' => 'object']] : $item,                      // an untyped item that is a map IS a row
                ['$ref' => '#/definitions/'.$receiver.'/properties/'.$signature->name.'/anyOf/'.(count($rows) - 1)],
            ]];
        }

        return [
            'description' => self::stub($signature, $curation),
            'anyOf' => $rows,
            'x-manifest' => [...$signature->toArray(), ...array_intersect_key($curation, array_flip(self::CURATION))],
        ];
    }

    /**
     * The `-> m($a, $b)` stub, with `TODO(m: $p)` for every parameter whose element type is unknown.
     *
     * @param  Curation  $curation
     */
    public static function stub(Signature $signature, array $curation): string
    {
        $names = array_map(static fn (string $name): string => '$'.$name, $signature->names());
        $unknown = [];

        foreach ($signature->params as $param) {
            if (self::type($param, $curation) === true) {
                $unknown[] = '$'.$param['name'];
            }
        }

        return '-> '.$signature->name.'('.implode(', ', $names).')'
            .($unknown === [] ? '' : ' TODO('.$signature->name.': '.implode(', ', $unknown).')');
    }

    /**
     * τ(p): the parameter's JSON type — the curated vocabulary wins, closures reference the shared definition,
     * an unknown type is `true`.
     *
     * @param  Param  $param
     * @param  Curation  $curation
     * @return array<string, mixed>|true
     */
    public static function type(array $param, array $curation): array|true
    {
        $resolve = $curation['resolve'] ?? [];
        $vocabulary = is_array($resolve) ? ($resolve[$param['name']] ?? null) : null;

        if ($vocabulary === 'phpFile') {
            return true;                                                                       // a `.php` file is one option; anything else passes through untouched
        }

        if (is_string($vocabulary)) {
            return ['$ref' => '#/definitions/'.$vocabulary];
        }

        return match ($param['type']) {
            Signature::STRING => ['type' => 'string'],
            Signature::INT => ['type' => 'integer'],
            Signature::FLOAT => ['type' => 'number'],
            Signature::BOOL => ['type' => 'boolean'],
            Signature::ARRAY => ['type' => ['array', 'object']],
            Signature::CLOSURE => ['$ref' => '#/definitions/closure'],
            default => self::isClosure($param, $curation) ? ['$ref' => '#/definitions/closure'] : true,
        };
    }

    /**
     * Row 7: a map whose keys are first arguments; each value is the second argument, a list of them, or (closure) a body.
     *
     * @param  Param  $p1
     * @param  Curation  $curation
     * @param  list<class-string>  $classes
     * @return array<string, mixed>
     */
    private static function entries(Signature $signature, array $p1, array $curation, array $classes): array
    {
        $type = self::type($p1, $curation);
        $schema = ['type' => 'object', 'propertyNames' => ['not' => ['enum' => $signature->names()]]];

        if (self::isClosure($p1, $curation)) {
            return [...$schema, 'additionalProperties' => ['anyOf' => [$type, ['type' => 'array', 'items' => $type], ['type' => 'object']]], ...self::bodies($classes)];
        }

        if ($type === true) {
            return $schema;                                                                    // an unknown second argument admits anything
        }

        return [...$schema, 'additionalProperties' => $p1['type'] === Signature::ARRAY ? $type : ['anyOf' => [$type, ['type' => 'array', 'items' => $type]]]];
    }

    /**
     * The projected FQCNs as keys: each one's value is a body on that class (the shared `bodies` definition).
     *
     * @param  list<class-string>  $classes
     * @return array{allOf?: list<array{'$ref': string}>}
     */
    private static function bodies(array $classes): array
    {
        return $classes === [] ? [] : ['allOf' => [['$ref' => '#/definitions/bodies']]];
    }

    /**
     * Row 10: the parameters by name; the first is required; every other key rides the return (validated at runtime),
     * so additional properties stay admitted.
     *
     * @param  Curation  $curation
     * @return array<string, mixed>
     */
    private static function rowSchema(Signature $signature, array $curation): array
    {
        $properties = [];

        foreach ($signature->params as $param) {
            $type = self::type($param, $curation);

            if ($type === true) {
                continue;                                                                      // an unknown type admits anything — the default
            }

            $properties[$param['name']] = self::isClosure($param, $curation)
                ? ['anyOf' => [$type, ['type' => 'object']]]
                : $type;
        }

        return [
            'type' => 'object',
            ...($properties === [] ? [] : ['properties' => $properties]),
            'required' => [$signature->params[0]['name']],
        ];
    }

    /**
     * @param  Curation  $curation
     * @param  array<mixed>  $item
     * @param  Closure(mixed, Param): mixed  $resolve
     * @param  Closure(array<mixed>): Closure  $lambda
     * @return Call
     */
    private static function row(Signature $signature, array $curation, array $item, Closure $resolve, Closure $lambda): array
    {
        $named = [];
        $rest = [];

        foreach ($item as $key => $value) {
            $param = is_string($key) ? $signature->named($key) : null;

            if ($param === null) {
                $rest[(string) $key] = $value;

                continue;
            }

            $named[$key] = self::isMap($value) && self::isClosure($param, $curation)
                ? $lambda($value)
                : $resolve($value, $param);
        }

        return [$named, $rest];
    }

    /**
     * A list whose every item is a row (a map whose first key is a parameter name) is one call per row even when
     * the first parameter is array-typed: `group: [{attributes: {…}, routes: {…}}]` on `group(array $attributes, $routes)`.
     *
     * @param  list<mixed>  $value
     */
    private static function isRows(Signature $signature, array $value): bool
    {
        return $value !== [] && array_all($value, static fn (mixed $item): bool => self::isMap($item) && in_array(array_key_first($item), $signature->names(), true));
    }

    /** @phpstan-assert-if-true array<string, mixed> $value */
    private static function isMap(mixed $value): bool
    {
        return is_array($value) && ! array_is_list($value);
    }
}
