---
name: schema-generator-php-parser
task: >-
  Re-platform the schema generator's extraction layer from PHP Reflection to the
  nikic/PHP-Parser AST: identical §4.1–§4.6 derivation algorithm, identical
  merge/encode, file-based source of truth, and trait flattening verified to
  reproduce Reflection truth (Router 65/65, order included).
plan: docs/declarative-schema-generator.md (§4 algorithm unchanged)
replaces: docs/implementation/schema_generator/ (reflection engine; units 01–04 implemented)
parser: docs/repos/nikic/PHP-Parser (v5.9 vendored; doc/ read in full)
units:
  - 01-engine-swap-pipeline-and-filter
  - 02-signature-shapes-over-ast-params
  - 03-value-types-over-ast-type-nodes
  - 04-trait-flattening
  - 05-command-write-path
  - 06-acceptance-router
---

# Schema Generator — PHP-Parser (AST) Implementation Plan

A script that generates the **schema** fragment for a YAML **manifest** block directly from a native Laravel class — now derived from the class's **source file** with [nikic/PHP-Parser](../repos/nikic/PHP-Parser/doc/0_Introduction.markdown) instead of PHP Reflection.

- Input: one native Laravel class FQCN, e.g. `Illuminate\Routing\Router`.
- Output: the `manifest.schema.json` `definitions.<block>` entry for that class.
- The derivation algorithm (§4.1–§4.6), `merge()`, `encode()`, and the command UX are **unchanged** — see [declarative-schema-generator.md](../declarative-schema-generator.md) and the reflection-engine units ([00-overview.md](schema_generator/00-overview.md)). This plan re-specifies only the extraction mechanism, grounded in the PHP-Parser docs under `docs/repos/nikic/PHP-Parser/doc/` and verified empirically against this repo's vendor tree.

## 0. Why PHP-Parser (and what it changes)

| Concern | Reflection engine | PHP-Parser engine |
| --- | --- | --- |
| Source of truth | the **loaded** class (`new ReflectionClass`) — triggers autoload, class linking, parent/enum loading | the **file bytes** — parse `vendor/...` sources; the target class never loads |
| Runtime coupling | the host PHP must parse the vendor source at load time | [token emulation](../repos/nikic/PHP-Parser/doc/component/Lexer.markdown) parses newer syntax than the host ("allows to parse PHP 8.4 source code running on PHP 7.4") |
| Types | `ReflectionNamedType`/`ReflectionUnionType`/`ReflectionIntersectionType` juggling | discriminated **node classes**: `Node\Identifier`, `Node\Name`, `Node\NullableType`, `Node\UnionType`, `Node\IntersectionType` (§3) |
| Docblocks | raw string `getDocComment()|false` | first-class `Node\Comment\Doc` via `getDocComment()` ([usage doc](../repos/nikic/PHP-Parser/doc/2_Usage_of_basic_components.markdown)) |
| Declaring-class filter | `getDeclaringClass()` reports the *using* class for trait methods (reconciliation 1 of the reflection plan) | `Class_::getMethods()` is **own-body only**; trait methods must be flattened explicitly (unit 04 — verified rule, §2.4) |
| Native failure for bad source | impossible (PHP would not have loaded the file) | `PhpParser\Error` ([error-handling doc](../repos/nikic/PHP-Parser/doc/component/Error_handling.markdown)) |
| Defaults | reflection may fail on non-constant defaults | `$param->default` is the raw AST `Expr`, **never evaluated** (verified: `Scalar\String_`) |

Not derivable remains not derivable: the **attribute kind**, curated prose, and PHP-type-inexpressible refinements are still curation (§3 of the plan) — merge and encoding are carried over byte-for-byte from the reflection units.

## 1. Grounding — verified facts (docs + this repo)

Every PHP-Parser-specific claim below was checked against the vendored `nikic/php-parser` **v5.9.0** and the vendor `laravel/framework` tree in this repo — and re-verified by running a scratch harness over that tree (node shapes, `findFile` mapping, the §2.4 flatten order against `ReflectionClass`, the §2.5 error surface):

1. **Parser bootstrap** — `ParserFactory::createForNewestSupportedVersion()` ([usage doc](../repos/nikic/PHP-Parser/doc/2_Usage_of_basic_components.markdown)): "when analyzing arbitrary code you are usually best off using the newest supported version, which tends to accept the widest range of code". A parser instance is reusable across files; reuse it per `render()` call for target + traits ([performance doc](../repos/nikic/PHP-Parser/doc/component/Performance.markdown)). `createForHostVersion()` rejected: it couples acceptance to the CLI's runtime version.
2. **Comments** — the parser adds a `comments` attribute (array of `PhpParser\Comment[\Doc]`); "the last doc comment … can be obtained using `getDocComment()`" (usage doc). Verified: a fixture `/** @internal */ public function …` yields a `Doc` whose `getText()` contains `@internal`.
3. **Visibility** — `ClassMethod::isPublic()` is `true` for methods declared **without** a visibility keyword (verified). (The [lexer doc](../repos/nikic/PHP-Parser/doc/component/Lexer.markdown) `public`-vs-`var` caveat applies to properties, not methods.)
4. **Own-body methods only** — `Class_::getMethods()` returns the methods declared in that class node's body, in **source order** (verified: parent methods of `C extends P` are absent). This makes the §4.1 declaring-class rule ("parent methods belong to the parent's projection") the *natural* AST behavior.
5. **Param/type nodes** — verified shapes: untyped → `$param->type === null`; `string $x` → `Node\Identifier(name: "string")`; `?int` → `Node\NullableType`; `string|array` → `Node\UnionType` of two `Identifier`s; `A&B` → `Node\IntersectionType`; `Closure`/class types → `Node\Name\FullyQualified` after name resolution; `mixed` → `Identifier("mixed")`; `array &$r` → `byRef: true`; `string ...$h` → `variadic: true` with type `Identifier("string")`; defaults are raw `Expr` nodes; DNF `(A&B)\|null` → `Node\UnionType` with an `IntersectionType` member (the `Types` fixture's `either`); a `NullableType` is never a union member (`?int\|string` is itself a `PhpParser\Error`); builtin keywords arrive normalized (`STRING` → `Identifier("string")`, matching reflection's normalized `getName()`).
6. **Name resolution** — `NameResolver` (added to a `NodeTraverser`) resolves imports and adds a `namespacedName` **property** to class/interface/enum/trait declarations ([name-resolution doc](../repos/nikic/PHP-Parser/doc/component/Name_resolution.markdown)). Verified on `Router.php`: `use Macroable;` resolves to `Illuminate\Support\Traits\Macroable`, and the class node's `namespacedName` is `Illuminate\Routing\Router`. The type map itself **never needs resolved names** — every `Node\Name` is unknown-path regardless of resolution; only trait imports (unit 04) need `NameResolver`.
7. **Class selection** — `NodeFinder::findFirst($stmts, …)` is the documented shortcut for "find the class with a certain name" ([walking doc](../repos/nikic/PHP-Parser/doc/component/Walking_the_AST.markdown)); select the `Node\Stmt\ClassLike` whose `namespacedName->toString()` equals the requested FQCN (works for `Class_`, `Interface_`, `Enum_`, `Trait_`).
8. **File location** — the one non-PHP-Parser piece: `Composer\Autoload\ClassLoader::findFile($fqcn): string|false`, obtained via `ClassLoader::getRegisteredLoaders()` (verified; composer 2.9 — the registry is keyed by vendor directory, so iterate its values). It resolves Laravel's multi-prefix PSR-4 mapping — verified: `Illuminate\Support\Traits\Macroable` → `vendor/laravel/framework/src/Illuminate/Macroable/Traits/Macroable.php`, whose in-file `namespace Illuminate\Support\Traits` matches the import. Internal classes (`Closure`) and unknown classes → `false` (verified).
9. **Trait flattening ≡ Reflection truth** — the rule in §2.4 (own methods, then `TraitUse` statements in body order, with `insteadof` exclusions and `as` aliases appended *per statement*) was run against `Illuminate\Routing\Router`: **65 declarable methods in exactly reflection's order** (verified; Router uses two traits — `Macroable` with `__call as macroCall`, and `Tappable` providing `tap`).
10. **Errors** — parse failure throws `PhpParser\Error` by default (`ErrorHandler\Throwing`) (verified: `<?php {"not php"}}}` → `Syntax error, unexpected '}' on line 1`; a source with no `<?php` tag parses *silently* as inline HTML — an empty AST — which the not-declared guard (§2.5) turns loud). `ErrorHandler\Collecting` (recovery → partial AST with `Expr\Error` nodes — verified present) is **rejected**: Rule 3.3 — no partial, silent structure.
11. **Dependency state** — `nikic/php-parser` v5.9 is currently only a **transitive dev dependency** (`pestphp/pest-plugin-mutate`, `phpunit/php-code-coverage`, `psy/psysh`, …). A `--no-dev` consumer install would lack it → unit 01 adds `"nikic/php-parser": "^5.9"` to `require` (also required by the `composer-require-checker` gate).
12. **Starting point** — the reflection engine is implemented and green through old unit 04 (`src/Internal/SchemaGenerator.php` with `render/skipped/merge/encode`; fixtures `tests/Fixtures/SchemaGenerator/{Basic,BasicParent,Kinds,Suit,Types}.php`; `SchemaGeneratorTest.php`, `GenerateSchemaCommandTest.php`). Old units 05 (`--out`) and 06 (Router acceptance) are not yet implemented. This plan swaps the engine unit-by-unit with the **existing tests as the regression harness**; 05/06 land directly on the new engine.

## 2. Derivation specification — AST mapping

The §4.1–§4.6 semantics are the algorithm (plan doc). Here is each rule's AST mechanism.

### 2.1 Pipeline

```
FQCN ──findFile──▶ path ──file_get_contents──▶ code
        │ (I/O — command layer only)
        ▼
SchemaGenerator::render($class, $block, $source)        # pure statics, no I/O
  parse(code) ──NameResolver──▶ stmts
  select ClassLike by namespacedName === $class          # NodeFinder (§1.7)
  flatten ClassMethod list (§2.4)                        # own body + traits (unit 04)
  apply §4.1 filter → declarable | skipped
  classify each method: §4.2 kinds over Node\Param (unit 02)
  map each type: §4.3 map over type nodes (unit 03)
  build key schemas + stub descriptions (§4.4, §4.5)
```

- **Source seam**: `render(string $class, string $block, Closure $source): array` and `skipped(string $class, Closure $source): list<string>`, where `@param Closure(string): (string|null) $source` returns file contents for an FQCN or `null` when the file cannot be read. The **command** injects `findFile + file_get_contents` (the only I/O); **tests** inject a map-backed closure — the core stays I/O-free and deterministic. (The reflection engine's `render()` performed autoload implicitly; the new seam makes the file dependency explicit and injectable.)
- Parser + traverser are constructed once per `render()` call and reused for target + traits (§1.1).

Complete seam (final state — unit 01 lands own-body only, unit 04 swaps the `flatten()` call):

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use Closure;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

/** @internal */
final class SchemaGenerator
{
    /**
     * @param  class-string  $class  the native Laravel class to project
     * @param  string  $block  the manifest block key — reported by the command, unused by the shape
     * @param  Closure(string): (string|null)  $source  file contents per FQCN, null when unreadable (the command injects the I/O)
     * @return array<string, mixed> the JSON-decodable definition object (not encoded — the caller encodes)
     *
     * @throws Error               the source is not valid PHP (Rule 3.3, native)
     * @throws RuntimeException  no readable source for $class, or $class is not declared in it (§2.5)
     */
    public static function render(string $class, string $block, Closure $source): array
    {
        [$parser, $traverser] = self::pipeline();
        $classNode = self::selectClass($class, $source, $parser, $traverser);
        $seen = []; // one seen-set per run — the cycle/diamond guard is scoped to the projection (§2.4)

        $properties = [];

        // unit 01 lands own-body only: `self::declarable($classNode->getMethods())`;
        // unit 04 swaps the argument for `self::flatten(...)` — the filter itself is untouched.
        foreach (self::declarable(self::flatten($classNode, $source, $parser, $traverser, $seen))[0] as $method) {
            $properties[$method->name->toString()] = self::key($method);
        }

        return [
            'description' => $class.' methods: every key is a method name, its value the argument(s).',
            'type' => ['object', 'null'],
            'additionalProperties' => false,
            'properties' => $properties,
        ];
    }

    /**
     * @param  class-string  $class
     * @param  Closure(string): (string|null)  $source
     * @return list<string>
     *
     * @throws Error|RuntimeException  the same failure surface as render()
     */
    public static function skipped(string $class, Closure $source): array
    {
        [$parser, $traverser] = self::pipeline();
        $classNode = self::selectClass($class, $source, $parser, $traverser);
        $seen = [];

        return array_map(
            static fn (Node\Stmt\ClassMethod $method): string => $method->name->toString(),
            self::declarable(self::flatten($classNode, $source, $parser, $traverser, $seen))[1],
        );
    }

    /**
     * Built once per render()/skipped() call, reused for target + traits (§1.1).
     *
     * @return array{0: Parser, 1: NodeTraverser}
     */
    private static function pipeline(): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion(); // widest acceptance (§1.1)
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver); // namespacedName on declarations + resolved trait imports (§1.6)

        return [$parser, $traverser];
    }

    /**
     * @param  class-string  $class
     * @param  Closure(string): (string|null)  $source
     * @return ClassLike  Class_|Interface_|Enum_|Trait_
     *
     * @throws Error|RuntimeException
     */
    private static function selectClass(string $class, Closure $source, Parser $parser, NodeTraverser $traverser): ClassLike
    {
        $code = $source($class);

        if ($code === null) {
            throw new RuntimeException("No readable source for $class"); // vanished after the command's pre-check (§2.5)
        }

        $stmts = $traverser->traverse($parser->parse($code));

        $classNode = (new NodeFinder)->findFirst($stmts, static fn (Node $node): bool => $node instanceof ClassLike
            && $node->namespacedName?->toString() === $class); // NameResolver sets the property on every ClassLike (§1.7)

        if ($classNode === null) {
            throw new RuntimeException("$class is not declared in its resolved source"); // e.g. an alias-only shim (§2.5)
        }

        return $classNode;
    }
}
```

(The command calls `skipped()` then `render()`; each parses the target independently — the reflection engine likewise instantiated `ReflectionClass` twice, and the output is deterministic, so the double parse is safe.)

### 2.2 Method filter (§4.1) — rules 1–5 over `Node\Stmt\ClassMethod`

| §4.1 rule (unchanged) | AST mechanism |
| --- | --- |
| 1. public | `$method->isPublic()` (unmodified methods are public — §1.3) |
| 2. declaring-class-owned | own-body `getMethods()` + unit-04 flattening (§2.4); parent methods naturally excluded (§1.4) |
| 3. not `@internal` | `str_contains($method->getDocComment()?->getText() ?? '', '@internal')` (§1.2) |
| 4. not `__`-magic | `str_starts_with($method->name->toString(), '__')` |
| 5. declarable | `params !== [] && !array_any($params, fn ($p) => $p->byRef)`; violations land in `skipped()`, never silent |

Order of keys = the flattened method list order (§2.4) — Rule 8.1 declaration order. Statics are included (reflection parity; `Macroable::macro` is static and projects). Interface/enum/trait targets work via `ClassLike` (STYLE 0.2).

```php
/**
 * §4.1 over nodes. Rules 1–4 exclude *silently* (reflection parity: `__construct`, `@internal`,
 * protected/private and parent methods never appear anywhere); rule-5 violations are the only
 * ones *reported* — they land in skipped(), never dropped silently (§2.5).
 *
 * @param  list<Node\Stmt\ClassMethod>  $methods  flattened own-body + trait methods, in reflection order (§2.4)
 * @return array{0: list<Node\Stmt\ClassMethod>, 1: list<Node\Stmt\ClassMethod>}  [declarable, skipped]
 */
private static function declarable(array $methods): array
{
    $declarable = [];
    $skipped = [];

    foreach ($methods as $method) {
        $name = $method->name->toString();

        if (! $method->isPublic()                                                 // rule 1 — implicit-public is public (§1.3)
            || str_contains($method->getDocComment()?->getText() ?? '', '@internal') // rule 3 — Doc node or null (§1.2)
            || str_starts_with($name, '__')) {                                     // rule 4
            continue;                                                              // rule 2 is satisfied by the input list itself
        }

        if ($method->params === []                                                 // rule 5 — zero params
            || array_any($method->params, static fn (Node\Param $param): bool => $param->byRef)) {
            $skipped[] = $method;                                                  // reported, never silent

            continue;
        }

        $declarable[] = $method;
    }

    return [$declarable, $skipped];
}
```

### 2.3 Signature shapes (§4.2) and value types (§4.3) over nodes

**Kind precedence chain** — evaluated top-down, rows unchanged, detection over `Node\Param`:

| Row | AST detection |
| --- | --- |
| variadic-only → `append` | 1 param, `$param->variadic === true` |
| exactly 1 non-variadic → `setter` | 1 param |
| `(scalarKey, string\|array)` → `append-to` | 2 params; `scalarKey($p0)`; `$p1->type` is `UnionType` of exactly two `Identifier`s `{string, array}` (order-agnostic) |
| `(scalarKey, any)` → `binding` | 2 params; `scalarKey($p0)` |
| else → undecided | completing rule: description-only + `TODO(<method>: <all params>)` |

`scalarKey($p)`: `$p->type === null` → `true` (untyped counts, §6.1 precedent); `Identifier` in `{string, int}` → `true`; `UnionType` all of whose members are `Identifier`s in `{string, int}` (no `"null"` member) → `true`; `NullableType` or anything else → `false`. (PHP array keys are `int|string`; a float/bool first param falls through, loud.)

**Type map (§4.3)** — types classified by node class; members expanded in declared order (`array` in place to `["array","object"]`), then ordered by the fixed precedence `string, integer, number, boolean, array, object, null`, deduplicated, with `"null"` appended last. The precedence sort is reflection parity (old unit 03 `uasort`s over the same list): declared order alone would emit `["integer","string"]` for `int|string` where reflection emits `["string","integer"]`, breaking the byte-identical promise:

| Signature type node | Example | JSON Schema |
| --- | --- | --- |
| `null` (no type declared) | `pattern($key, $pattern)` | unknown → `true` + `TODO` |
| `Identifier` `string`/`int`/`float`/`bool` | `string $a` | `{"type":"string"}` / integer / number / boolean |
| `Identifier` `array` | `array $verbs` | `{"type":["array","object"]}` |
| `Identifier` other builtins (`mixed`, `iterable`, `callable`, `object`, `never`, …) | `mixed $m` | unknown → `true` + `TODO` |
| `Node\Name` (any class name, incl. `Closure`, `self`, FQCNs) | `Closure $c` | unknown → `true` + `TODO` — no name resolution needed for this row (§1.6) |
| `NullableType` (`?T`) | `?int $retries` | T's map + `"null"` last |
| `UnionType`, every member mappable | `string\|int`, `string\|null` | members expanded, precedence-ordered, dedup, `"null"` last (explicit `\|null` member ≡ `?T`) |
| `UnionType` with any unmappable member | `string\|Closure` | whole param unknown → `true` + `TODO` (one untypeable member makes the value untypeable) |
| `IntersectionType` | `A&B $x` | unknown → `true` + `TODO` |

Defaults: `$param->default` exists or not — **never emitted**, never evaluated (§4.3 no-defaults rule unchanged; AST cannot fail on constant-expression defaults the way reflection can). Variadic: the type map applies to `$param->type` itself; the `append` row wraps it in `{"type":"array","items":…}` (§4.3 variadic row unchanged). Setter stub suffix (`, one call with the whole value` vs ` when the key is present`) still keys off `"array"` in the resolved schema (old unit 03).

Complete detection and mapping (the builder methods `binding()/appendTo()/append()/setter()/undecided()/stub()/withDescription()` port mechanically: `ReflectionMethod` → `ClassMethod`, `ReflectionParameter` → `Node\Param`, `$parameter->getName()` → `self::paramName($param)`, `$method->getName()` → `$method->name->toString()`):

```php
/** @return array<string, mixed> */
private static function key(Node\Stmt\ClassMethod $method): array
{
    $params = $method->params;

    if (count($params) === 1 && $params[0]->variadic) {                                              // variadic-only → append
        return self::append($method, $params[0]);
    }

    if (count($params) === 1) {                                                                       // exactly 1 non-variadic → setter
        return self::setter($method, $params[0]);
    }

    if (count($params) === 2 && self::scalarKey($params[0]) && self::stringArrayUnion($params[1]->type)) {
        return self::appendTo($method, $params[0], $params[1]);                                       // (scalarKey, string|array) → append-to
    }

    if (count($params) === 2 && self::scalarKey($params[0])) {
        return self::binding($method, $params[0], $params[1]);                                        // (scalarKey, any) → binding
    }

    return self::undecided($method);                                                                  // description-only + TODO(<method>: <all params>)
}

private static function scalarKey(Node\Param $param): bool
{
    $type = $param->type;

    if ($type === null) {
        return true;                                                                                  // untyped counts (§6.1 precedent: vendor keys are untyped)
    }

    if ($type instanceof Node\NullableType) {
        return false;                                                                                 // a key that may be null is not a manifest key
    }

    if ($type instanceof Node\UnionType) {
        return array_all($type->types, static fn (Node $member): bool => $member instanceof Node\Identifier // a Name / A&B member fails loudly
            && in_array($member->toString(), ['string', 'int'], true));                               // an explicit "null" member fails here
    }

    return $type instanceof Node\Identifier && in_array($type->toString(), ['string', 'int'], true);
}

private static function stringArrayUnion(?Node $type): bool
{
    if (! $type instanceof Node\UnionType) {
        return false;
    }

    $names = array_map(static fn (Node $member): string => $member instanceof Node\Identifier ? $member->toString() : '', $type->types);

    sort($names);                                                                                     // order-agnostic (§4.2): array|string ≡ string|array

    return $names === ['array', 'string'];
}

/** @return array{0: mixed, 1: list<string>} */
private static function paramSchema(Node\Param $param): array
{
    $type = $param->type;

    if ($type instanceof Node\UnionType) {
        return self::unionSchema($type, self::paramName($param));                                     // includes explicit "null" members
    }

    if ($type === null || $type instanceof Node\Name || $type instanceof Node\IntersectionType) {
        return [true, [self::paramName($param)]];                                                     // untyped / class / A&B — honestly unknown (no name resolution, §1.6)
    }

    if ($type instanceof Node\NullableType) {
        $expanded = self::expand($type->type);                                                        // inner is Identifier|Name

        if ($expanded === null) {
            return [true, [self::paramName($param)]];                                                 // ?mixed, ?Closure, ?Suit …
        }

        return [['type' => self::typeValue([...$expanded, 'null'])], []];                             // "null" appended last
    }

    $expanded = self::expand($type);                                                                  // Identifier — a builtin

    if ($expanded === null) {
        return [true, [self::paramName($param)]];                                                     // mixed, iterable, callable, object, never …
    }

    return [['type' => self::typeValue($expanded)], []];                                              // string / int / float / bool / array
}

/** @return array{0: mixed, 1: list<string>} */
private static function unionSchema(Node\UnionType $type, string $name): array
{
    $types = [];
    $nullable = false;

    foreach ($type->types as $member) {
        if ($member instanceof Node\IntersectionType) {                                               // DNF (A&B)|x — one untypeable member poisons the whole value
            return [true, [$name]];                                                                   // (a NullableType can never be a union member, §1.5)
        }

        if ($member instanceof Node\Identifier && $member->toString() === 'null') {
            $nullable = true;                                                                         // explicit |null — same treatment as ?T

            continue;
        }

        $expanded = self::expand($member);                                                            // Identifier builtins; every Name → null

        if ($expanded === null) {
            return [true, [$name]];
        }

        $types = [...$types, ...$expanded];                                                           // declared order first (array expands in place)
    }

    $types = self::ordered($types);                                                                   // precedence sort — reflection parity (§2.3 intro)

    if ($nullable) {
        $types[] = 'null';                                                                            // last, after the sort
    }

    return [['type' => self::typeValue($types)], []];
}

/**
 * Reflection parity (old unit 03): `uasort` over the fixed precedence list.
 *
 * @param  list<string>  $types
 * @return list<string>
 */
private static function ordered(array $types): array
{
    $precedence = array_flip(['string', 'integer', 'number', 'boolean', 'array', 'object', 'null']);

    uasort($types, static fn (string $left, string $right): int => $precedence[$left] <=> $precedence[$right]);

    return array_values($types);
}

/** @return list<string>|null */
private static function expand(Node\Identifier|Node\Name $type): ?array
{
    return match ($type->toString()) {
        'string' => ['string'],
        'int' => ['integer'],
        'float' => ['number'],
        'bool' => ['boolean'],
        'array' => ['array', 'object'],
        default => null,                                                                              // every class name, mixed, iterable, callable, …
    };
}

/** @param  list<string>  $types  @return string|list<string> */
private static function typeValue(array $types): string|array
{
    $unique = array_values(array_unique($types));

    return count($unique) === 1 ? $unique[0] : $unique;
}

private static function paramName(Node\Param $param): string
{
    assert($param->var instanceof Node\Expr\Variable);                                                // parser guarantee for method params (§2.3) — house narrowing style

    return $param->var->name;
}
```

Worked example over the `Types` fixture (asserted byte-exact in `SchemaGeneratorTest`):

```
Types::scale(string|int $scale)                       → ['type' => ['string', 'integer']]          // precedence order, not declared order
Types::maybe(string|int|null $maybe)                  → ['type' => ['string', 'integer', 'null']]  // explicit |null member ⇒ "null" appended last
Types::optional(?array $optional)                     → ['type' => ['array', 'object', 'null']]    // array expands in place inside ?T
Types::pair(string|array $pair)                       → ['type' => ['string', 'array', 'object']]  // array|string declared reversed encodes identically
Types::shape(string|Suit $shape)                      → description-only + TODO(shape: $shape)     // one Name member poisons the whole union
Types::either((Stringable&Countable)|string $either)  → description-only + TODO(either: $either)   // IntersectionType member
```

**Stub descriptions (§4.5)** — unchanged text, names extracted from nodes: method `$method->name->toString()`; param names `$param->var->name` (a method param's `var` is always a plain `Node\Expr\Variable` with a string name — parser guarantee; phpstan-narrowed by `instanceof`, no defensive branch).

**Block key (§4.6)** — unchanged: `lcfirst(<class basename>)`; the FQCN for the envelope description is the requested class string (matches the class node's `namespacedName`, which is asserted in the pipeline).

### 2.4 Trait flattening (the new engine capability — replaces reconciliation 1)

Reflection's `getDeclaringClass()` reports the *using* class for trait methods, so the reflection filter included trait methods (`Router::macro`, `Router::tap`). The AST engine must reproduce that flattened view **deterministically**:

```
flatten(ClassLike $c): array<string, ClassMethod>   # seen-set of FQCNs, cycle/diamond guard
  if seen($c->namespacedName): return []
  seen[] = $c->namespacedName
  out = c->getMethods()                              # own body order; own wins over traits
  foreach $c->stmts as $stmt:
    if not TraitUse: continue
    shadowed = for each Precedence adaptation: for each overwritten trait in adaptation->insteadof:
                 [overwrittenTrait][adaptation->method] = true     # $insteadof holds the traits that LOSE; $trait is the winner
    perTrait = for each trait name in $stmt->traits (resolved FQCN, in order):
                 flatten(traitNode) minus shadowed[trait]
    splice perTrait (trait order) into out, skipping names already present
    aliases: for each Alias adaptation with newName !== null not already in out:
               source trait = adaptation->trait ?? first trait of this statement
               out[newName] = rename(clone perTrait[sourceTrait][adaptation->method], newName)
                                              # appended NOW, per statement; the clone is renamed because the alias is a
                                              # distinct method NAME — see the grounding bullet below
  return out
```

Grounding for each clause:

- **Own first, then `TraitUse` statements in body order** — PHP appends trait methods after the class's own methods; verified: reflection lists Router's own methods (`…, setContainer, __call`) *before* `macro, mixin, hasMacro, …` even though `use Macroable` sits at the top of the class body (line 40).
- **Per-trait source order** — verified: Macroable's methods appear in file order `macro, mixin, hasMacro, flushMacros, __callStatic, __call`, then `macroCall` (the alias), then Tappable's `tap`.
- **Alias placement is per statement** — first attempt appending aliases after *all* statements produced `tap` before `macroCall` (order mismatch); appending immediately after the alias's own `TraitUse` statement reproduces reflection exactly. Verified: **65/65 identical order** with `Illuminate\Routing\Router` (two traits, one `__call as macroCall` alias).
- **Alias node shape** — `TraitUseAdaptation\Alias` with `trait: null` when the statement has a single trait (verified on Router), `method`, `newName`, `newModifier`. `newName === null` (visibility-only `as public`) adds no method — the `$newName !== null` guard.
- **`Precedence` node shape — the direction matters** — `Precedence{trait: <winner>, method, insteadof: <overwritten Name[]>}`: the overwritten traits live in `$insteadof`, **not** in `$trait`. An earlier draft of this plan shadowed `$adaptation->trait` and inverted the outcome; a distinguishing-signature fixture proves the corrected rule (`Second::m insteadof First` → `m` maps with an `int` param, matching PHP's reflection truth).
- **The alias is a distinct method NAME, not a renamed node** — PHP reports `Router::macroCall` with `getName() === 'macroCall'` (verified), while the AST `ClassMethod` node it aliases still carries `$name = '__call'`. Everything downstream (declarable's `__` rule, `key()`, `stub()`, the properties key) reads the node name — so the alias loop clones the aliased node and renames it to `newName`. Without the clone+rename, `macroCall` would be filtered out by rule 4 and the stub would read `-> __call(...)` (an earlier draft of this plan keyed the alias without renaming the node and lost it — caught by re-running the Router 65/65 proof against the final code).
- **Dangling or ambiguous adaptations are PHP load-time fatals — reproduced loudly** — verified: `trait T {} class C { use T { b as c; } }` fatals (`An alias (c) was defined for method b(), but this method does not exist`), and a bare `foo as bar` across two traits fatals (`An alias was defined for method foo(), which exists in both …; use Trait::method to resolve the ambiguity`). The AST engine never loads; it throws `RuntimeException` instead (§2.5) — a missing `perTrait` entry for the dangling alias, and an explicit ambiguity guard when a trait-less alias spans multiple traits.
- **Own-wins shadowing** — a class's own method overrides same-named trait methods (PHP semantics; Router's own `__call` overrides Macroable's). Splice skips already-present names.
- **`insteadof` (`Precedence`)** — the overwritten traits are excluded from `perTrait`; the winner's method survives via splice order. PHP requires all `insteadof` participants to be in the same `TraitUse` statement, so the two-step (exclude + splice) is complete for same-statement conflicts. No vendor framework class uses `insteadof` (verified: the only grep hit is a reserved-word list in `GeneratorCommand.php`); a unit-04 fixture pins it anyway.
- **Cycle/diamond guard** — a seen-set on FQCNs; exercised by a diamond fixture (`class uses T1, T2; T2 uses T1` — verified: the algorithm emits `p, q`, matching reflection).

Complete implementation (final state; validated 65/65 against `ReflectionClass` on `Illuminate\Routing\Router`, §1.9):

```php
/**
 * Own methods first (source order; own wins over traits), then per `TraitUse` statement in body
 * order: `insteadof`-excluded methods removed, splice in trait order, `as` aliases appended
 * immediately after their statement. Reproduces `ReflectionClass::getMethods()` order —
 * verified 65/65 on Illuminate\Routing\Router (§1.9).
 *
 * @param  ClassLike  $class  the ClassLike node to inline
 * @param  Closure(string): (string|null)  $source
 * @param  array<string, true>  $seen  FQCNs already inlined (cycle/diamond guard)
 * @return array<string, Node\Stmt\ClassMethod>  keyed by method name, in reflection's order
 *
 * @throws Error|RuntimeException  a trait's source is missing, unreadable, not declared, or an adaptation dangles
 */
private static function flatten(ClassLike $class, Closure $source, Parser $parser, NodeTraverser $traverser, array &$seen): array
{
    $self = (string) $class->namespacedName?->toString();

    if (isset($seen[$self])) {
        return [];                                                       // diamond/cycle: already inlined
    }

    $seen[$self] = true;

    $out = [];

    foreach ($class->getMethods() as $method) {                          // own body only, source order (§1.4)
        $out[$method->name->toString()] = $method;
    }

    foreach ($class->getTraitUses() as $use) {
        $shadowed = [];

        foreach ($use->adaptations as $adaptation) {
            if ($adaptation instanceof Node\Stmt\TraitUseAdaptation\Precedence) {
                foreach ($adaptation->insteadof as $overwritten) {       // the traits that LOSE (§2.4)
                    $shadowed[$overwritten->toString()][$adaptation->method->toString()] = true;
                }
            }
        }

        $perTrait = [];

        foreach ($use->traits as $trait) {
            $fqcn = $trait->toString();                                  // NameResolver resolved this to FullyQualified (§1.6)
            $traitNode = self::selectClass($fqcn, $source, $parser, $traverser);
            $perTrait[$fqcn] = array_diff_key(self::flatten($traitNode, $source, $parser, $traverser, $seen), $shadowed[$fqcn] ?? []);
        }

        foreach ($perTrait as $methods) {                                // splice in trait order, skipping own-wins names
            foreach ($methods as $name => $method) {
                if (! array_key_exists($name, $out)) {
                    $out[$name] = $method;
                }
            }
        }

        foreach ($use->adaptations as $adaptation) {                     // aliases appended NOW, per statement (§1.9)
            if (! $adaptation instanceof Node\Stmt\TraitUseAdaptation\Alias
                || $adaptation->newName === null                         // visibility-only `as public` adds no method
                || array_key_exists($adaptation->newName->toString(), $out)) {
                continue;
            }

            if ($adaptation->trait === null && count($perTrait) > 1) {
                throw new RuntimeException("Ambiguous alias {$adaptation->method->toString()} across multiple traits"); // PHP load-time fatal, reproduced
            }

            $winner = $adaptation->trait?->toString() ?? array_key_first($perTrait); // trait: null on single-trait statements (verified on Router)

            $aliased = $perTrait[$winner][$adaptation->method->toString()]
                ?? throw new RuntimeException("No such trait method $winner::{$adaptation->method->toString()}"); // PHP load-time fatal, reproduced

            $aliased = clone $aliased; // the alias is a distinct method NAME (§2.4): reflection reports getName() === 'macroCall'
            $aliased->name = new Node\Identifier($adaptation->newName->toString());

            $out[$adaptation->newName->toString()] = $aliased;
        }
    }

    return $out;
}
```

### 2.5 Failure surface (Rule 3.3)

| Condition | Behavior | Precedent |
| --- | --- | --- |
| target class has no source file (`findFile` false, or path not a file) | command-level `$this->components->error(...)` + `self::FAILURE` | `ValidateCommand` missing-manifest precedent (expected input failure; presentation layer) |
| source closure returns `null` (file vanished between locate and read; direct core call) | core throws `RuntimeException` naming the FQCN | unit invariant, SPL native |
| source is not valid PHP | native `PhpParser\Error` propagates (default `ErrorHandler\Throwing`) | Rule 3.3; error-handling doc |
| FQCN not declared in its resolved file (e.g. alias-only shim) | core throws `RuntimeException` naming the FQCN | core invariant |
| `--out` missing file / invalid JSON / write failure | unchanged from old unit 05 (`error`+FAILURE / native `JsonException` / `RuntimeException`) | reflection plan unit 05 |

> **Reconciliation with the reflection plan**: old unit 01 failed unknown classes with a native `ReflectionException`. Under the file-based engine there is no native "class does not exist" exception — the pipeline's native failures are `PhpParser\Error` (bad source) and the locator's `false` (no source file), and the missing-file case maps to the `ValidateCommand` presentation precedent. Recorded in unit 01; the old `ReflectionException` tests are rewritten accordingly.

## 3. Units

Same six-unit decomposition as the reflection engine ([00-overview.md](schema_generator/00-overview.md)) so acceptance artifacts stay comparable. Layering rule unchanged: every previous unit's tests stay green at each step; `composer check` (pint, rector, phpstan level 9, 100% coverage) gates each unit. Fixtures stay PSR-4 files under `tests/Fixtures/SchemaGenerator/` — command tests need real, `findFile`-locatable files (verified with `tests/Fixtures/PublicApi/Widget.php`); core tests read them through the injected source closure.

### Unit 01 — Engine swap: pipeline + filter

**Vertical slice**: `declaration:generate-schema '<class>'` prints the same fragments the reflection engine printed for `Basic`/`Kinds`/`Types`/`Suit` — now derived from parsed source. The whole existing `SchemaGeneratorTest` suite passes **byte-identically** (the swap proof), except the failure-mode tests, which change per §2.5.

**Delivers**:
- `composer.json`: `"nikic/php-parser": "^5.9"` in `require` (§1.11).
- `SchemaGenerator::selectClass(string $class, Closure $source, Parser $parser, NodeTraverser $traverser)`-style private pipeline (§2.1 code): `pipeline()` (`ParserFactory::createForNewestSupportedVersion()` + `NameResolver`), `NodeFinder` selection by `namespacedName` (§1.6–1.7).
- `declarable(list<ClassMethod>)` rewritten over nodes (§2.2 code) — **own-body only at this unit**; unit 04 inserts flattening *before* the filter without changing the filter.
- `render()/skipped()` gain the `Closure $source` parameter; `key()/setter()/undecided()/paramSchema()/stub()` minimally ported to `ClassMethod`/`Param` (their full node semantics land in units 02–03; output must already match).
- `GenerateSchemaCommand::handle()` builds the source resolver (`ClassLoader::getRegisteredLoaders()` → `findFile` → `is_file` → `file_get_contents`), pre-checks the target (`components->error` + FAILURE per §2.5), and keeps print mode byte-identical:

```php
public function handle(): int
{
    /** @var class-string $class */
    $class = $this->argument('class');
    $basename = strrpos($class, '\\');
    $block = lcfirst($basename === false ? $class : substr($class, $basename + 1)); // §4.6

    $this->components->info("Block: $block");

    $locate = static function (string $fqcn): ?string {
        foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) { // keyed by vendor dir (§1.8)
            $path = $loader->findFile($fqcn);

            if (is_string($path) && is_file($path)) {
                return $path;                                  // Laravel's multi-prefix PSR-4 resolved here
            }
        }

        return null;
    };

    if ($locate($class) === null) {                            // pre-check locates without reading (§2.5)
        $this->components->error("No source file for $class"); // ValidateCommand missing-manifest precedent

        return self::FAILURE;
    }

    $source = static fn (string $fqcn): ?string => ($path = $locate($fqcn)) === null
        ? null                                                 // vanished between locate and read → core RuntimeException (§2.5)
        : (string) file_get_contents($path);                   // the ONLY I/O lives in this closure

    $skipped = SchemaGenerator::skipped($class, $source);

    if ($skipped !== []) {
        $this->components->warn('Skipped: '.implode(', ', $skipped));
    }

    foreach (explode("\n", rtrim(SchemaGenerator::encode(SchemaGenerator::render($class, $block, $source)))) as $line) {
        $this->line($line);
    }

    return self::SUCCESS;
}
```

**Tests** (updated, in the existing files):
- All current fixture fragment assertions pass unchanged (`Basic`, `Kinds`, `Types`, `Suit`).
- Parent exclusion (`Application` projection) passes naturally (§1.4).
- Unknown class: core test asserts `RuntimeException`; command test asserts `error` + `assertFailed()` (replaces the `ReflectionException` tests).
- `phpstan`: `Closure(string): (string|null)` phpdoc on the seam; `PhpParser\Error` tagged `@throws`.

**Not in this unit**: trait methods — the Router trait-inclusion test **moves to unit 04** (it is the flattening feature's acceptance).

### Unit 02 — Signature shapes over AST params

Swap `scalarKey()`, `stringArrayUnion()`, `binding()`, `appendTo()`, `append()` to `Node\Param`/type-node detection (§2.3 table). Pure swap: the `Kinds` fixture assertions (`group`, `middleware`, `bind`, `handlers`, `ratio`, `register`) pass unchanged. New tests only where the AST adds a row the fixtures don't cover: a two-member union declared in reverse member order (`array|string` still fires `append-to` — order-agnostic per §4.2), and a `NullableType` first param falling through to undecided.

### Unit 03 — Value types over AST type nodes

Swap `paramSchema()`, `unionSchema()`, `expand()` to the node map (§2.3). The `Types`/`Suit` assertions pass unchanged (`Identifier:mixed` hits the same unknown path reflection's `'mixed'` did; `Name\FullyQualified` hits the class row). New tests pin the AST-only distinctions: `iterable`/`callable` as `Identifier` unknown-path (already covered), a `Name`-typed union member making the whole param unknown (`string|\Closure` → `true` + `TODO`), and `never`/`object` builtins.

### Unit 04 — Trait flattening

**Vertical slice**: projections now include trait-provided methods in reflection order — `Router` renders with `macro`, `mixin`, `hasMacro`, `macroCall` (the `as` alias), and `tap` (from `Tappable`), in exactly reflection's order.

**Delivers**: `flatten()` (§2.4) wired between pipeline and filter; recursive source resolution through the injected closure (traits of traits); seen-set guard.

**Tests** (headless, map-backed source closures over inline heredoc sources + the real vendor traits):
1. **Golden order lock**: `render('Illuminate\Routing\Router', 'router', …)` key list equals the 65-key list pinned in old unit 06 (`ROUTER_KEYS`) — the equivalence proof (§1.9), re-homing the two Router command tests (parent exclusion stays in unit 01; trait inclusion lands here).
2. Alias: a fixture `use T { T::__call as macroCall; }` yields `macroCall` positioned right after `T`'s methods; visibility-only `as public` adds no key.
3. Precedence: `use A, B { B::m insteadof A; }` fixture — `m` comes from `B` regardless of statement order.
4. Diamond: `class uses T1, T2; T2 uses T1` — `T1`'s methods splice once.
5. Real vendor traits: `Macroable`/`Tappable` resolved through the closure (`findFile` maps the legacy `Illuminate\Support\Traits\*` FQCN to the physical file — §1.8).

Test examples for 2–4 (headless, map-backed source closures over inline heredoc sources — the §2.1 seam keeps the core I/O-free and deterministic):

```php
const TRAIT_FIXTURES = [
    'T\Alias' => <<<'PHP'
        <?php

        namespace T;

        trait Alias
        {
            public function ping(string $name): void {}

            public function pong(string $name): void {}
        }
        PHP,
    'C\UsesAlias' => <<<'PHP'
        <?php

        namespace C;

        use T\Alias;

        class UsesAlias
        {
            use Alias {
                ping as knock;
            }
        }
        PHP,
];

/** @param  array<string, string>  $map */
function mapSource(array $map): Closure
{
    return static fn (string $fqcn): ?string => $map[$fqcn] ?? null;
}

it('appends an as alias right after the trait\'s methods, per statement', function (): void {
    $properties = SchemaGenerator::render('C\UsesAlias', 'usesAlias', mapSource(TRAIT_FIXTURES))['properties'];

    expect(array_keys($properties))->toBe(['ping', 'pong', 'knock']); // knock directly after T\Alias's methods
});

it('resolves insteadof to the winner, whatever the statement order', function (): void {
    $map = [
        'T\First' => <<<'PHP'
            <?php

            namespace T;

            trait First
            {
                public function m(string $a): void {}
            }
            PHP,
        'T\Second' => <<<'PHP'
            <?php

            namespace T;

            trait Second
            {
                public function m(int $a): void {}
            }
            PHP,
        'C\Prefers' => <<<'PHP'
            <?php

            namespace C;

            use T\First, T\Second;

            class Prefers
            {
                use First, Second {
                    Second::m insteadof First;
                }
            }
            PHP,
    ];

    $m = SchemaGenerator::render('C\Prefers', 'prefers', mapSource($map))['properties']['m'];

    expect($m['type'])->toBe('integer'); // Second's m won — the distinguishing signatures prove the direction (§2.4)
});

it('inlines a diamond once', function (): void {
    // T1 has p(); T2 uses T1; class uses T1, T2 — the seen-set collapses T1 to one inline:
    // keys are exactly ['p'], in T1's source order (verified against reflection, §2.4).
});
```

### Unit 05 — Command write path (`--out`)

Unchanged from old [05-command-write-path.md](schema_generator/05-command-write-path.md) — read → `merge()` → `encode()` → write → report `Added [N]`, idempotent — with two engine adjustments: the resolver closure is built once per run (target + traits share it), and the target pre-check (§2.5) precedes rendering. `merge()`/`encode()` are untouched (unit 04 of the reflection engine, already implemented and green). All old unit-05 tests land as written.

### Unit 06 — Acceptance: `Illuminate\Routing\Router`

As old [06-acceptance-router.md](schema_generator/06-acceptance-router.md): golden 65-key set and order, per-key shape spot checks (`bind`, `middlewareGroup`, `matched`, `resourceVerbs`, `is`, `model`, `view`), merge round trip into a copy of the shipped `manifest.schema.json` (`Added [53]`, curated bytes preserved), idempotency, shipped file untouched. The §6.1 delta table carries over unchanged (untyped vendor params still produce honest `TODO`s) — the AST engine reproduces the reflection engine's honest output because both read the same signatures; only the extraction mechanism differs.

## 4. Acceptance checklist

- [ ] Existing fixture fragments (`Basic`, `Kinds`, `Types`, `Suit`) byte-identical after the swap (units 01–03).
- [ ] Keys in native declaration order — flattened order verified equal to reflection's on `Router` (unit 04, §2.4).
- [ ] Parent methods excluded, trait methods included (with `as`-alias and `insteadof` semantics), `@internal`/`__`/by-ref/zero-param rules unchanged (§2.2, §2.4).
- [ ] Types from the signature only — node-class map, docblocks consulted solely for `@internal` (Rule 2.5); union member order matches reflection's precedence sort (§2.3) — byte-parity guard.
- [ ] Unknown/unreadable paths stay honest: `true` + `TODO`, `skipped()` report, loud command errors (§2.5).
- [ ] `nikic/php-parser` in `require`; `composer require-check` passes; `composer check` green after every unit.
- [ ] Core does no I/O (source closure seam); command is the only I/O owner; stateless, `@internal` (Rule 4).

## 5. Sources

- **PHP-Parser docs** (read in full): [0_Introduction](../repos/nikic/PHP-Parser/doc/0_Introduction.markdown) (what the AST is; version emulation), [2_Usage_of_basic_components](../repos/nikic/PHP-Parser/doc/2_Usage_of_basic_components.markdown) (ParserFactory, parse, comments/`getDocComment()`, NameResolver overview, NodeFinder mention), [component/Walking_the_AST](../repos/nikic/PHP-Parser/doc/component/Walking_the_AST.markdown) (visitors, `NodeFinder`, class-by-name pattern), [component/Name_resolution](../repos/nikic/PHP-Parser/doc/component/Name_resolution.markdown) (`namespacedName` property, resolved FQCNs), [component/Error_handling](../repos/nikic/PHP-Parser/doc/component/Error_handling.markdown) (`PhpParser\Error`, `Throwing` vs `Collecting`), [component/Lexer](../repos/nikic/PHP-Parser/doc/component/Lexer.markdown) (token emulation, attributes), [component/Performance](../repos/nikic/PHP-Parser/doc/component/Performance.markdown) (parser reuse, Xdebug note), [component/FAQ](../repos/nikic/PHP-Parser/doc/component/FAQ.markdown).
- **Reflection-engine plan + units** (algorithm baseline): [declarative-schema-generator.md](../declarative-schema-generator.md); [schema_generator/00-overview.md](schema_generator/00-overview.md) … [06-acceptance-router.md](schema_generator/06-acceptance-router.md).
- **Verified in this repo** (evidence for §1, §2.4): `vendor/nikic/php-parser` v5.9.0 (AST behaviors reproduced empirically); `vendor/laravel/framework/src/Illuminate/Routing/Router.php` (`use Macroable { __call as macroCall; }` line 40, `use Tappable` line 43, own `__call` line 1497); `vendor/laravel/framework/src/Illuminate/Macroable/Traits/Macroable.php` (declares `namespace Illuminate\Support\Traits`); `vendor/laravel/framework/composer.json` (multi-prefix PSR-4 for `Illuminate\Support\`); `vendor/laravel/framework/src/Illuminate/Console/GeneratorCommand.php` (the only `insteadof` grep hit — a reserved-word list, not usage); `composer.lock`/`composer why` (php-parser is transitive-dev only); `composer-require-checker.json` (symbol gate).
- **Existing implementation** (regression harness): [../../src/Internal/SchemaGenerator.php](../../src/Internal/SchemaGenerator.php), [../../src/Internal/Commands/GenerateSchemaCommand.php](../../src/Internal/Commands/GenerateSchemaCommand.php), [../../tests/Feature/SchemaGeneratorTest.php](../../tests/Feature/SchemaGeneratorTest.php), [../../tests/Feature/GenerateSchemaCommandTest.php](../../tests/Feature/GenerateSchemaCommandTest.php), `tests/Fixtures/SchemaGenerator/`.
- **House patterns**: [../../src/Internal/Commands/ValidateCommand.php](../../src/Internal/Commands/ValidateCommand.php) (missing-file error+FAILURE), [../../src/LaravelDeclarationProvider.php](../../src/LaravelDeclarationProvider.php) (registration), [../../STYLE.md](../../STYLE.md) Rules 1–9.