<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use Closure;
use Illuminate\Foundation\Application;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\TraitUseAdaptation;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;
use stdClass;
use ZeroToProd\LaravelDeclaration\Internal\Engine\Forms;
use ZeroToProd\LaravelDeclaration\Internal\Engine\Signature;

/**
 * Σ at generation time (§1.1) — PHP-Parser over the class, its traits and its parent chain (own wins), projected
 * into `definitions.<FQCN>` by the forms (§1.3). Generation is total: every declarable method is projected; the
 * prior schema's curation (`description`, `pattern`, `enum`, … and the `x-manifest` curation keywords) re-merges
 * on top, so regeneration is byte-identical when nothing changed.
 *
 * @phpstan-type Report array{curation-only: list<string>, todo: list<string>}
 *
 * @internal
 */
final class SchemaGenerator
{
    /** The root receiver: the manifest is a body on the application. */
    public const string ROOT = Application::class;

    /** Keys the generator owns on a key schema — everything else on a prior key is curation, preserved byte-for-byte. */
    private const array STRUCTURAL = ['anyOf', 'oneOf', 'allOf', 'type', 'items', 'properties', 'additionalProperties', 'propertyNames', 'required', 'const', '$ref', 'x-manifest'];

    /**
     * @param  string  $class  the FQCN to project — also the schema key, verbatim
     * @param  Closure(string): (string|null)  $source  file contents per FQCN, null when unreadable (the command injects the I/O)
     * @return list<Signature> in `ReflectionClass::getMethods()` order, parents included
     *
     * @throws Error the source is not valid PHP (Rule 3.3, native)
     * @throws RuntimeException no readable source for $class, or $class is not declared in it
     */
    public static function signatures(string $class, Closure $source): array
    {
        return array_map(self::signature(...), self::declarable($class, $source)[0]);
    }

    /**
     * The native methods the gate excludes with a report: by-reference parameters.
     *
     * @param  Closure(string): (string|null)  $source
     * @return list<string>
     *
     * @throws Error|RuntimeException the same failure surface as signatures()
     */
    public static function skipped(string $class, Closure $source): array
    {
        return array_map(static fn (ClassMethod $method): string => $method->name->toString(), self::declarable($class, $source)[1]);
    }

    /**
     * The whole schema for a scope of classes: per class the fragment replaces `definitions.<FQCN>`, per key the
     * prior's curation re-merges on top; prior keys with no signature are kept and reported; definitions outside
     * the scope (vocabulary, data) are kept; the root is `$ref`s to the root receiver's keys ∪ the prior root's
     * data keys ∪ every projected FQCN with a static key.
     *
     * @param  list<class-string>  $classes
     * @param  array<string, mixed>|null  $prior  the decoded prior schema, null on bootstrap
     * @param  Closure(string): (string|null)  $source
     * @return array{0: array<string, mixed>, 1: Report}
     */
    public static function generate(array $classes, ?array $prior, Closure $source): array
    {
        $definitions = self::array($prior['definitions'] ?? null);
        $report = ['curation-only' => [], 'todo' => []];

        foreach ($classes as $class) {
            $priorKeys = self::keys($definitions, $class);
            $properties = [];

            foreach (self::signatures($class, $source) as $signature) {
                $priorKey = $priorKeys[$signature->name] ?? [];
                $curation = array_intersect_key(self::array($priorKey['x-manifest'] ?? null), array_flip(Forms::CURATION));

                $generated = Forms::schema($signature, $curation, $classes, $class);

                if (str_contains(Forms::stub($signature, $curation), 'TODO(')) {
                    $report['todo'][] = $class.'::'.$signature->name;
                }

                $properties[$signature->name] = [...$generated, ...array_diff_key($priorKey, array_flip(self::STRUCTURAL))];
            }

            foreach ($priorKeys as $name => $priorKey) {
                if (! array_key_exists($name, $properties)) {
                    $properties[$name] = $priorKey;                                    // a curated key with no signature (a __call surface)
                    $report['curation-only'][] = $class.'::'.$name;
                }
            }

            $definitions[$class] = [
                'description' => $class.' methods: every key is a method name, its value the argument(s); keys apply in manifest order.',
                'type' => ['object', 'null'],
                'additionalProperties' => false,
                'properties' => $properties,
            ];
        }

        if ($classes !== []) {
            $bodies = [];

            foreach ($classes as $class) {
                $bodies[$class] = ['$ref' => '#/definitions/'.$class];
            }

            $definitions['bodies'] = [
                'description' => 'A map keyed by projected class names: each value is a body on that class (`make`, `afterResolving`, …).',
                'type' => 'object',
                'properties' => $bodies,
            ];
        }

        return [[
            '$schema' => $prior['$schema'] ?? 'http://json-schema.org/draft-07/schema#',
            'title' => $prior['title'] ?? 'Laravel Declaration manifest',
            'description' => $prior['description'] ?? 'The YAML file at `laravel-declaration.manifest` (default `manifest/app.yml`): a body on '.self::ROOT.'.',
            'type' => ['object', 'null'],
            'additionalProperties' => false,
            'x-manifest' => ['classes' => $classes],
            'properties' => self::root($classes, $prior, $definitions) ?: new stdClass,       // an empty root is a JSON object, not a list
            'definitions' => $definitions,
        ], $report];
    }

    /** Nodes whose single-line form is at most this long print inline; longer ones open across lines. */
    private const int INLINE = 100;

    /**
     * Encodes a schema array in the repo's JSON style: 2-space indentation, unescaped slashes and unicode (the
     * curated prose keeps its `—`, `≈`), short nodes inline, a trailing newline. A pure function of the decoded
     * data, so re-encoding is idempotent.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function encode(array $schema): string
    {
        return self::print($schema, 0)."\n";
    }

    private static function print(mixed $node, int $depth): string
    {
        $inline = json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (! is_array($node) || $node === [] || strlen($inline) <= self::INLINE) {
            return is_array($node) ? preg_replace(['/,(?=\S)/', '/:(?=\S)/'], [', ', ': '], $inline) ?? $inline : $inline;
        }

        $pad = str_repeat('  ', $depth + 1);
        $lines = [];

        foreach ($node as $key => $value) {
            $lines[] = $pad.(array_is_list($node) ? '' : json_encode((string) $key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).': ').self::print($value, $depth + 1);
        }

        [$open, $close] = array_is_list($node) ? ['[', ']'] : ['{', '}'];

        return $open."\n".implode(",\n", $lines)."\n".str_repeat('  ', $depth).$close;
    }

    /**
     * @param  list<class-string>  $classes
     * @param  array<string, mixed>|null  $prior
     * @param  array<mixed>  $definitions
     * @return array<string, mixed>
     */
    private static function root(array $classes, ?array $prior, array $definitions): array
    {
        $properties = [];

        foreach (array_keys(self::keys($definitions, self::ROOT)) as $method) {
            $properties[$method] = ['$ref' => '#/definitions/'.self::ROOT.'/properties/'.$method];
        }

        foreach (self::array($prior['properties'] ?? null) as $key => $property) {
            if ((self::array(self::array($property)['x-manifest'] ?? null)['data'] ?? false) === true) {
                $properties[(string) $key] = $property;                                 // a curated data key: stored, never dispatched
            }
        }

        foreach ($classes as $class) {
            $statics = [];

            foreach (self::keys($definitions, $class) as $method => $key) {
                if ((self::array($key['x-manifest'] ?? null)['static'] ?? false) === true) {
                    $statics[$method] = ['$ref' => '#/definitions/'.$class.'/properties/'.$method];
                }
            }

            if ($statics !== []) {
                $properties[$class] = [
                    'description' => $class.' as a static receiver: every key is a static method name, its value the argument(s).',
                    'type' => ['object', 'null'],
                    'additionalProperties' => false,
                    'properties' => $statics,
                ];
            }
        }

        return $properties;
    }

    /**
     * The key objects of one definition, by method name.
     *
     * @param  array<mixed>  $definitions
     * @return array<string, array<mixed>>
     */
    private static function keys(array $definitions, string $class): array
    {
        $keys = [];

        foreach (self::array(self::array($definitions[$class] ?? null)['properties'] ?? null) as $name => $key) {
            if (is_array($key)) {
                $keys[(string) $name] = $key;
            }
        }

        return $keys;
    }

    /**
     * The decoded prior is `mixed` all the way down; every step narrows to an array or nothing.
     *
     * @return array<mixed>
     */
    private static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function signature(ClassMethod $method): Signature
    {
        $params = [];

        foreach ($method->params as $param) {
            $params[] = ['name' => self::paramName($param), 'type' => self::paramType($param->type), 'variadic' => $param->variadic];
        }

        return new Signature($method->name->toString(), $method->isStatic(), $params);
    }

    /**
     * The type vocabulary Σ carries: array · closure (Closure|callable) · string · int · float · bool · null
     * (untyped, class, mixed, union). A nullable type is its inner type; a union with one non-null member is that member.
     */
    private static function paramType(?Node $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            return self::paramType($type->type);
        }

        if ($type instanceof Node\UnionType) {
            $members = array_values(array_filter($type->types, static fn (Node $member): bool => ! $member instanceof Identifier || $member->toString() !== 'null'));

            return count($members) === 1 ? self::paramType($members[0]) : null;
        }

        if ($type instanceof Identifier) {
            return match ($type->toString()) {
                'array' => Signature::ARRAY,
                'callable' => Signature::CLOSURE,
                'string' => Signature::STRING,
                'int' => Signature::INT,
                'float' => Signature::FLOAT,
                'bool' => Signature::BOOL,
                default => null,                                                      // mixed, iterable, object, never, false, true, self, static …
            };
        }

        return $type instanceof Name && $type->toString() === Closure::class ? Signature::CLOSURE : null; // every other class, A&B
    }

    private static function paramName(Node\Param $param): string
    {
        assert($param->var instanceof Node\Expr\Variable);                                // parser guarantee for method params
        assert(is_string($param->var->name));

        return $param->var->name;
    }

    /**
     * The gate: public ∧ not `__*` ∧ not `@internal` (silent — reflection parity) · no by-reference parameter (reported).
     * Zero-parameter methods ARE declarable (row 1).
     *
     * @param  Closure(string): (string|null)  $source
     * @return array{0: list<ClassMethod>, 1: list<ClassMethod>} [declarable, skipped]
     */
    private static function declarable(string $class, Closure $source): array
    {
        [$parser, $traverser] = self::pipeline();
        $seen = [];                                                                       // one seen-set per run — the cycle/diamond guard is scoped to the projection
        $declarable = [];
        $skipped = [];

        foreach (self::flatten(self::selectClass($class, $source, $parser, $traverser), $source, $parser, $traverser, $seen) as $method) {
            $name = $method->name->toString();

            if (! $method->isPublic()
                || str_contains($method->getDocComment()?->getText() ?? '', '@internal')
                || str_starts_with($name, '__')) {
                continue;
            }

            if (array_any($method->params, static fn (Node\Param $param): bool => $param->byRef)) {
                $skipped[] = $method;                                                     // reported, never silent

                continue;
            }

            $declarable[] = $method;
        }

        return [$declarable, $skipped];
    }

    /**
     * Built once per run, reused for the target, its traits and its parents.
     *
     * @return array{0: Parser, 1: NodeTraverser}
     */
    private static function pipeline(): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();                 // widest acceptance
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);                                         // namespacedName on declarations + resolved imports

        return [$parser, $traverser];
    }

    /**
     * @param  string  $class  the FQCN to select — the target, a trait, or a parent during flattening
     * @param  Closure(string): (string|null)  $source
     * @return ClassLike Class_|Interface_|Enum_|Trait_
     *
     * @throws Error|RuntimeException
     */
    private static function selectClass(string $class, Closure $source, Parser $parser, NodeTraverser $traverser): ClassLike
    {
        $code = $source($class);

        if ($code === null) {
            throw new RuntimeException("No readable source for $class");               // vanished after the command's pre-flight
        }

        $stmts = $traverser->traverse($parser->parse($code) ?? []);

        $classNode = (new NodeFinder)->findFirst($stmts, static fn (Node $node): bool => $node instanceof ClassLike
            && $node->namespacedName?->toString() === $class);                            // NameResolver sets the property on every ClassLike

        if (! $classNode instanceof Node) {
            throw new RuntimeException("$class is not declared in its resolved source"); // e.g. an alias-only shim
        }

        assert($classNode instanceof ClassLike);                                          // parser guarantee — house narrowing style

        return $classNode;
    }

    /**
     * Own methods first (source order; own wins), then per `TraitUse` statement in body order (splice in trait order,
     * an `as` alias right before the method it names, `insteadof` losers removed), then the parent chain the same
     * way, recursively. Reproduces `ReflectionClass::getMethods()` order, parents included.
     *
     * @param  Closure(string): (string|null)  $source
     * @param  array<string, true>  $seen  FQCNs already inlined (cycle/diamond guard)
     * @return array<string, ClassMethod> keyed by method name, in reflection's order
     *
     * @throws Error|RuntimeException a trait's or parent's source is missing, unreadable, not declared, or an adaptation dangles
     */
    private static function flatten(ClassLike $class, Closure $source, Parser $parser, NodeTraverser $traverser, array &$seen): array
    {
        $self = (string) $class->namespacedName?->toString();

        if (isset($seen[$self])) {
            return [];                                                                    // diamond/cycle: already inlined
        }

        $seen[$self] = true;

        $out = [];

        foreach ($class->getMethods() as $method) {                                       // own body only, source order
            $out[$method->name->toString()] = $method;
        }

        foreach ($class->getTraitUses() as $use) {
            $shadowed = [];
            $aliases = [];

            foreach ($use->adaptations as $adaptation) {
                if ($adaptation instanceof TraitUseAdaptation\Precedence) {
                    foreach ($adaptation->insteadof as $overwritten) {                    // the traits that LOSE
                        $shadowed[$overwritten->toString()][$adaptation->method->toString()] = true;
                    }
                } elseif ($adaptation instanceof TraitUseAdaptation\Alias && $adaptation->newName instanceof Identifier) {
                    $aliases[] = [$adaptation->trait?->toString(), $adaptation->method->toString(), $adaptation->newName->toString()]; // visibility-only `as public` adds no method
                }
            }

            $perTrait = [];                                                               // fqcn => methods — trait order

            foreach ($use->traits as $trait) {
                $fqcn = $trait->toString();                                               // NameResolver resolved this to FullyQualified
                $perTrait[$fqcn] = self::flatten(self::selectClass($fqcn, $source, $parser, $traverser), $source, $parser, $traverser, $seen);
            }

            $bound = [];                                                                  // fqcn => method => list<alias> — each alias binds to exactly one trait

            foreach ($aliases as [$winner, $method, $alias]) {
                $candidates = array_keys(array_filter($perTrait, static fn (array $methods, string $fqcn): bool => ($winner === null || $fqcn === $winner) && isset($methods[$method]), ARRAY_FILTER_USE_BOTH));

                if (count($candidates) > 1) {
                    throw new RuntimeException("Ambiguous alias $method across multiple traits"); // PHP load-time fatal, reproduced
                }

                $fqcn = $candidates[0] ?? throw new RuntimeException("No such trait method {$winner}::{$method}"); // PHP load-time fatal, reproduced

                $bound[$fqcn][$method][] = $alias;
            }

            foreach ($perTrait as $fqcn => $methods) {                                    // splice in trait order, skipping own-wins names
                foreach ($methods as $name => $method) {
                    foreach ($bound[$fqcn][$name] ?? [] as $alias) {                      // PHP adds an alias right BEFORE the method it names
                        if (! array_key_exists($alias, $out)) {
                            $aliased = clone $method;                                     // the alias is a distinct method NAME
                            $aliased->name = new Identifier($alias);
                            $out[$alias] = $aliased;
                        }
                    }

                    if (! isset($shadowed[$fqcn][$name]) && ! array_key_exists($name, $out)) {
                        $out[$name] = $method;
                    }
                }
            }
        }

        $parents = match (true) {
            $class instanceof Class_ && $class->extends instanceof Name => [$class->extends],
            $class instanceof Interface_ => $class->extends,
            default => [],
        };

        foreach ($parents as $parent) {                                                   // the parent chain, recursively — own wins
            foreach (self::flatten(self::selectClass($parent->toString(), $source, $parser, $traverser), $source, $parser, $traverser, $seen) as $name => $method) {
                if (! array_key_exists($name, $out)) {
                    $out[$name] = $method;
                }
            }
        }

        return $out;
    }
}
