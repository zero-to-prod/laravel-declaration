<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Engine;

use Closure;
use Illuminate\Contracts\Container\Container;

/**
 * THE CORE (§1) — one ordered decision list. Nothing here branches on a component, a method name, an attribute
 * or a typed class: `body()` and `chain()` are PHP's two ways to compose calls (statements on one receiver,
 * fluent on the return), `λ` is PHP's closure, and the forms are the grammar of a call site. The schema is the
 * only input besides the manifest: every key carries `x-manifest` = { static, params, …curation }, so the
 * runtime recomputes the forms and never re-reads vendor.
 *
 * @phpstan-type Guard Closure(object|string, string, array<int|string, mixed>, array<string, mixed>): bool
 *
 * @internal
 */
final class Engine
{
    /** @var Guard */
    private Closure $guard;

    /**
     * @param  array<string, mixed>  $schema  the decoded manifest.schema.json
     * @param  Container  $container  the root receiver; also the only object the forms need (`call`, `path.base`)
     * @param  Guard|null  $guard  an optional command hook deciding per call (§2.4); identity for the provider
     */
    public function __construct(
        private readonly array $schema,
        private readonly Container $container,
        private readonly Resolve $resolve,
        ?Closure $guard = null,
    ) {
        $this->guard = $guard ?? static fn (): bool => true;
    }

    /** @param  Guard  $guard */
    public function withGuard(Closure $guard): self
    {
        $clone = clone $this;
        $clone->guard = $guard;

        return $clone;
    }

    /**
     * `$t->a(); $t->b();` — the root, or a closure body. Manifest order = call order.
     *
     * @param  object|string  $t  the receiver; a class-string is a static receiver
     * @param  array<mixed>  $data
     */
    public function body(object|string $t, array $data): void
    {
        $definition = $this->def($t);

        foreach ($data as $key => $value) {
            $key = (string) $key;
            $meta = is_array($definition[$key] ?? null) ? $definition[$key] : null;

            if ($meta === null && $t === $this->container) {                            // root-only keys
                $root = self::array(self::array($this->schema['properties'] ?? null)[$key] ?? null);

                if ((self::array($root['x-manifest'] ?? null)['data'] ?? false) === true) {
                    continue;                                                             // a data key: stored, never dispatched
                }

                if ($this->isStaticReceiver($key)) {
                    $this->body($key, is_array($value) ? $value : []);                    // a projected FQCN as a key → static calls

                    continue;
                }
            }

            foreach ($this->calls($meta ?? Forms::VARIADIC, $value) as [$args, $rest]) {
                if (! ($this->guard)($t, $key, $args, $rest)) {
                    continue;
                }

                $static = is_string($t) || ((self::array(self::array($meta)['x-manifest'] ?? null)['static'] ?? false) === true);
                $result = $static ? $t::{$key}(...$args) : $t->{$key}(...$args);         // native failure propagates (STYLE 3.3)

                if ($rest !== []) {
                    $this->chain($result, $rest);                                         // extra keys ride the return value
                }
            }
        }
    }

    /**
     * `$t->a()->b()` — the return threads to the next key; a void or scalar return keeps the receiver.
     *
     * @param  array<string, mixed>  $rest
     */
    private function chain(mixed $t, array $rest): void
    {
        foreach ($rest as $key => $value) {
            $meta = is_object($t) ? ($this->def($t)[$key] ?? null) : null;

            foreach ($this->calls(is_array($meta) ? $meta : Forms::VARIADIC, $value) as [$args, $deeper]) {
                $result = is_object($t)
                    ? $t->{$key}(...$args)
                    : throw new \Error('Call to a member function '.$key.'() on '.get_debug_type($t));

                if ($deeper !== []) {
                    $this->chain($result, $deeper);
                }

                $t = is_object($result) ? $result : $t;
            }
        }
    }

    /**
     * λ — a map under a closure parameter is a body on the closure's argument.
     *
     * @param  array<mixed>  $data
     */
    private function lambda(array $data): Closure
    {
        return function (object $receiver) use ($data): void {
            $this->body($receiver, $data);
        };
    }

    /**
     * The definition's keys for a receiver: its own FQCN, then its parents, then its interfaces; [] = unknown.
     *
     * @return array<mixed>
     */
    private function def(object|string $t): array
    {
        $class = is_object($t) ? $t::class : $t;
        $candidates = class_exists($class) || interface_exists($class)
            ? [$class, ...array_values(class_parents($class) ?: []), ...array_values(class_implements($class) ?: [])]
            : [$class];

        $definitions = self::array($this->schema['definitions'] ?? null);

        foreach ($candidates as $candidate) {
            $properties = self::array($definitions[$candidate] ?? null)['properties'] ?? null;

            if (is_array($properties)) {
                return $properties;
            }
        }

        return [];
    }

    /** A projected definition with at least one static key may be addressed by its FQCN at the root. */
    private function isStaticReceiver(string $key): bool
    {
        $properties = self::array(self::array($this->schema['definitions'] ?? null)[$key] ?? null)['properties'] ?? null;

        return is_array($properties)
            && array_any($properties, static fn (mixed $property): bool => (self::array(self::array($property)['x-manifest'] ?? null)['static'] ?? false) === true);
    }

    /**
     * The decoded schema is `mixed` all the way down; every step narrows to an array or nothing.
     *
     * @return array<mixed>
     */
    private static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $meta  a key schema carrying `x-manifest`
     * @return list<array{0: array<int|string, mixed>, 1: array<string, mixed>}>
     */
    private function calls(array $meta, mixed $value): array
    {
        $manifest = self::array($meta['x-manifest'] ?? null);
        $curation = array_intersect_key($manifest, array_flip(Forms::CURATION));

        return Forms::calls(
            Signature::fromArray('', $manifest),
            $curation,
            $value,
            fn (mixed $value, array $param): mixed => ($this->resolve)($value, $param, $curation),
            $this->lambda(...),
        );
    }
}
