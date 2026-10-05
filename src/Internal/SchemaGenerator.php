<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use Closure;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TraitUseAdaptation;
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
     * @param  string  $class  the native Laravel class FQCN to project — also the schema key, verbatim (§2.3)
     * @param  Closure(string): (string|null)  $source  file contents per FQCN, null when unreadable (the command injects the I/O)
     * @return array<string, mixed> the JSON-decodable definition object (not encoded — the caller encodes)
     *
     * @throws Error the source is not valid PHP (Rule 3.3, native)
     * @throws RuntimeException no readable source for $class, or $class is not declared in it (§2.5)
     */
    public static function render(string $class, Closure $source): array
    {
        [$parser, $traverser] = self::pipeline();
        $classNode = self::selectClass($class, $source, $parser, $traverser);
        $seen = []; // one seen-set per run — the cycle/diamond guard is scoped to the projection (§2.4)

        $properties = [];

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
     * @param  string  $class  the native class FQCN
     * @param  Closure(string): (string|null)  $source
     * @return list<string>
     *
     * @throws Error|RuntimeException the same failure surface as render()
     */
    public static function skipped(string $class, Closure $source): array
    {
        [$parser, $traverser] = self::pipeline();
        $classNode = self::selectClass($class, $source, $parser, $traverser);
        $seen = [];

        return array_map(
            static fn (ClassMethod $method): string => $method->name->toString(),
            self::declarable(self::flatten($classNode, $source, $parser, $traverser, $seen))[1],
        );
    }

    /**
     * Appends only the missing native keys under `definitions.<class>` — curated
     * `description` prose and key objects are preserved byte-for-byte, nothing is
     * deleted, and the root `properties` are untouched (the block→class `$ref` wiring
     * is hand-written curation in `src/Manifest.php`, not derivable — §2.3).
     *
     * @param  array<string, mixed>  $schema  parsed manifest.schema.json
     * @param  string  $class  the schema key — the FQCN verbatim (§2.3)
     * @param  array<string, mixed>  $fragment  from render()
     * @return array<string, mixed> the updated schema, ready to re-encode
     */
    public static function merge(array $schema, string $class, array $fragment): array
    {
        $definitions = is_array($schema['definitions'] ?? null) ? $schema['definitions'] : [];

        if (array_key_exists($class, $definitions)) {
            $curated = is_array($definitions[$class] ?? null) ? $definitions[$class] : [];

            $existing = is_array($curated['properties'] ?? null) ? $curated['properties'] : [];

            $incoming = is_array($fragment['properties'] ?? null) ? $fragment['properties'] : [];

            foreach ($incoming as $key => $keySchema) {
                if (! array_key_exists($key, $existing)) {
                    $existing[$key] = $keySchema; // appended in native declaration order
                }
            }

            $curated['properties'] = $existing;
            $definitions[$class] = $curated;
            $schema['definitions'] = $definitions;

            return $schema;
        }

        $definitions[$class] = $fragment;
        $schema['definitions'] = $definitions;

        return $schema;
    }

    /**
     * Encodes a schema array in the repo's 2-space JSON style (§5.1).
     *
     * JSON_PRETTY_PRINT indents in exact multiples of 4, so halving reproduces
     * 2-space; JSON_UNESCAPED_UNICODE keeps the file's curated prose (`—`, `≈`)
     * readable; the trailing newline matches the shipped file.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function encode(array $schema): string
    {
        $json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return (preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json) ?? $json)."\n";
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
     * @param  string  $class  the FQCN to select — the target or a trait during flattening
     * @param  Closure(string): (string|null)  $source
     * @return ClassLike Class_|Interface_|Enum_|Trait_
     *
     * @throws Error|RuntimeException
     */
    private static function selectClass(string $class, Closure $source, Parser $parser, NodeTraverser $traverser): ClassLike
    {
        $code = $source($class);

        if ($code === null) {
            throw new RuntimeException("No readable source for $class"); // vanished after the command's pre-check (§2.5)
        }

        $stmts = $traverser->traverse($parser->parse($code) ?? []);

        $classNode = (new NodeFinder)->findFirst($stmts, static fn (Node $node): bool => $node instanceof ClassLike
            && $node->namespacedName?->toString() === $class); // NameResolver sets the property on every ClassLike (§1.7)

        if (! $classNode instanceof Node) {
            throw new RuntimeException("$class is not declared in its resolved source"); // e.g. an alias-only shim (§2.5)
        }

        assert($classNode instanceof ClassLike); // parser guarantee — house narrowing style

        return $classNode;
    }

    /**
     * §4.1 over nodes. Rules 1–4 exclude *silently* (reflection parity: `__construct`, `@internal`,
     * protected/private and parent methods never appear anywhere); rule-5 violations are the only
     * ones *reported* — they land in skipped(), never dropped silently (§2.5).
     *
     * @param  array<string, ClassMethod>  $methods  flattened own-body + trait methods, keyed by name, in reflection order (§2.4)
     * @return array{0: list<ClassMethod>, 1: list<ClassMethod>} [declarable, skipped]
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

    /**
     * Own methods first (source order; own wins over traits), then per `TraitUse` statement in body
     * order: `insteadof`-excluded methods removed, splice in trait order, `as` aliases appended
     * immediately after their statement. Reproduces `ReflectionClass::getMethods()` order —
     * verified 65/65 on Illuminate\Routing\Router (§1.9).
     *
     * @param  ClassLike  $class  the ClassLike node to inline
     * @param  Closure(string): (string|null)  $source
     * @param  array<string, true>  $seen  FQCNs already inlined (cycle/diamond guard)
     * @return array<string, ClassMethod> keyed by method name, in reflection's order
     *
     * @throws Error|RuntimeException a trait's source is missing, unreadable, not declared, or an adaptation dangles
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
                if ($adaptation instanceof TraitUseAdaptation\Precedence) {
                    foreach ($adaptation->insteadof as $overwritten) {       // the traits that LOSE (§2.4)
                        $shadowed[$overwritten->toString()][$adaptation->method->toString()] = true;
                    }
                }
            }

            $perTrait = [];                                                  // list of {name, methods} — trait order (§2.4)

            foreach ($use->traits as $trait) {
                $fqcn = $trait->toString();                              // NameResolver resolved this to FullyQualified (§1.6)
                $traitNode = self::selectClass($fqcn, $source, $parser, $traverser);
                $perTrait[] = ['name' => $fqcn, 'methods' => array_diff_key(self::flatten($traitNode, $source, $parser, $traverser, $seen), $shadowed[$fqcn] ?? [])];
            }

            foreach ($perTrait as $traitMethods) {                           // splice in trait order, skipping own-wins names
                foreach ($traitMethods['methods'] as $name => $method) {
                    if (! array_key_exists($name, $out)) {
                        $out[$name] = $method;
                    }
                }
            }

            foreach ($use->adaptations as $adaptation) {                     // aliases appended NOW, per statement (§1.9)
                if (! $adaptation instanceof TraitUseAdaptation\Alias
                    || ! $adaptation->newName instanceof Identifier                         // visibility-only `as public` adds no method
                    || array_key_exists($adaptation->newName->toString(), $out)) {
                    continue;
                }

                if (! $adaptation->trait instanceof Name && count($perTrait) > 1) {
                    throw new RuntimeException("Ambiguous alias {$adaptation->method->toString()} across multiple traits"); // PHP load-time fatal, reproduced
                }

                $winner = $adaptation->trait?->toString();                   // trait: null on single-trait statements (verified on Router)

                $aliased = null;

                foreach ($perTrait as $traitMethods) {                       // bind the named trait — or the single candidate
                    if ($winner !== null && $traitMethods['name'] !== $winner) {
                        continue;
                    }

                    $aliased = $traitMethods['methods'][$adaptation->method->toString()] ?? null;

                    break;
                }

                if ($aliased === null) {
                    throw new RuntimeException("No such trait method {$winner}::{$adaptation->method->toString()}"); // PHP load-time fatal, reproduced
                }

                $aliased = clone $aliased; // the alias is a distinct method NAME (§2.4): reflection reports getName() === 'macroCall'
                $aliased->name = new Identifier($adaptation->newName->toString());

                $out[$adaptation->newName->toString()] = $aliased;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function key(ClassMethod $method): array
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

        if (! $type instanceof Node) {
            return true;                                                                                  // untyped counts (§6.1 precedent: vendor keys are untyped)
        }

        if ($type instanceof Node\NullableType) {
            return false;                                                                                 // a key that may be null is not a manifest key
        }

        if ($type instanceof Node\UnionType) {
            return array_all($type->types, static fn (Node $member): bool => $member instanceof Identifier // a Name / A&B member fails loudly
                && in_array($member->toString(), ['string', 'int'], true));                               // an explicit "null" member fails here
        }

        return $type instanceof Identifier && in_array($type->toString(), ['string', 'int'], true);
    }

    private static function stringArrayUnion(?Node $type): bool
    {
        if (! $type instanceof Node\UnionType) {
            return false;
        }

        $names = array_map(static fn (Node $member): string => $member instanceof Identifier ? $member->toString() : '', $type->types);

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

        if (! $type instanceof Node || $type instanceof Name || $type instanceof Node\IntersectionType) {
            return [true, [self::paramName($param)]];                                                     // untyped / class / A&B — honestly unknown (no name resolution, §1.6)
        }

        if ($type instanceof Node\NullableType) {
            $expanded = self::expand($type->type);                                                        // inner is Identifier|Name

            if ($expanded === null) {
                return [true, [self::paramName($param)]];                                                 // ?mixed, ?Closure, ?Suit …
            }

            return [['type' => self::typeValue([...$expanded, 'null'])], []];                             // "null" appended last
        }

        $expanded = $type instanceof Identifier ? self::expand($type) : null; // Identifier builtins; untyped / class / A&B → unknown

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

            if ($member instanceof Identifier && $member->toString() === 'null') {
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
    private static function expand(Identifier|Name $type): ?array
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

    /**
     * @param  list<string>  $types
     * @return string|list<string>
     */
    private static function typeValue(array $types): string|array
    {
        $unique = array_values(array_unique($types));

        return count($unique) === 1 ? $unique[0] : $unique;
    }

    private static function paramName(Node\Param $param): string
    {
        assert($param->var instanceof Node\Expr\Variable);                                                // parser guarantee for method params (§2.3) — house narrowing style
        assert(is_string($param->var->name));

        return $param->var->name;
    }

    /** @return array<string, mixed> */
    private static function binding(ClassMethod $method, Node\Param $key, Node\Param $value): array
    {
        [$schema, $unknown] = self::paramSchema($value);

        return self::withDescription(
            ['type' => 'object', 'additionalProperties' => $schema],
            self::stub($method, [self::paramName($key), self::paramName($value)], ', one call per entry', $unknown),
        );
    }

    /** @return array<string, mixed> */
    private static function appendTo(ClassMethod $method, Node\Param $key, Node\Param $value): array
    {
        return self::withDescription(
            [
                'type' => 'object',
                'additionalProperties' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'array'],
                    ],
                ],
            ],
            self::stub($method, [self::paramName($key), self::paramName($value)], ', one call per item', []),
        );
    }

    /** @return array<string, mixed> */
    private static function append(ClassMethod $method, Node\Param $parameter): array
    {
        [$schema, $unknown] = self::paramSchema($parameter);

        return self::withDescription(
            ['type' => 'array', 'items' => $schema],
            self::stub($method, [self::paramName($parameter)], ', one call per item', $unknown),
        );
    }

    /** @return array<string, mixed> */
    private static function setter(ClassMethod $method, Node\Param $parameter): array
    {
        [$schema, $unknown] = self::paramSchema($parameter);

        $suffix = is_array($schema) && str_contains(json_encode($schema, JSON_THROW_ON_ERROR), '"array"')
            ? ', one call with the whole value'
            : ' when the key is present';

        return self::withDescription($schema, self::stub($method, [self::paramName($parameter)], $suffix, $unknown));
    }

    /** @return array<string, mixed> */
    private static function undecided(ClassMethod $method): array
    {
        $params = array_values(array_map(self::paramName(...), $method->params));

        return self::withDescription(true, self::stub($method, $params, ' when the key is present', $params));
    }

    /**
     * @param  list<string>  $params
     * @param  list<string>  $undecided
     */
    private static function stub(ClassMethod $method, array $params, string $suffix, array $undecided): string
    {
        $todo = $undecided === [] ? '' : ' TODO('.$method->name->toString().': '.implode(', ', array_map(static fn (string $param): string => '$'.$param, $undecided)).')';

        return '-> '.$method->name->toString().'('.implode(', ', array_map(static fn (string $param): string => '$'.$param, $params)).')'.$suffix.$todo;
    }

    /** @return array<string, mixed> */
    private static function withDescription(mixed $schema, string $description): array
    {
        return is_array($schema) ? ['description' => $description, ...$schema] : ['description' => $description];
    }
}
