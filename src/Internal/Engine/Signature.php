<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Engine;

/**
 * Σ(class)[m] — one method's signature: the only input the forms (§1.2) consume.
 *
 * `type` ∈ {array, closure, string, int, float, bool} or null (untyped, class, mixed, union).
 *
 * @phpstan-type Param array{name: string, type: string|null, variadic: bool}
 *
 * @internal
 */
final readonly class Signature
{
    public const string ARRAY = 'array';

    public const string CLOSURE = 'closure';

    public const string STRING = 'string';

    public const string INT = 'int';

    public const string FLOAT = 'float';

    public const string BOOL = 'bool';

    /** @param  list<Param>  $params */
    public function __construct(
        public string $name,
        public bool $static,
        public array $params,
    ) {}

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (array $param): string => $param['name'], $this->params);
    }

    /** @return Param|null */
    public function param(int $position): ?array
    {
        return $this->params[$position] ?? null;
    }

    /** @return Param|null */
    public function named(string $name): ?array
    {
        foreach ($this->params as $param) {
            if ($param['name'] === $name) {
                return $param;
            }
        }

        return null;
    }

    /**
     * The `x-manifest` signature metadata the schema carries per key: `static` only when true, `params` as a
     * `{name: type}` map in declaration order, a variadic parameter spelled `...name`.
     *
     * @return array{static?: true, params: array<string, string|null>}
     */
    public function toArray(): array
    {
        $params = [];

        foreach ($this->params as $param) {
            $params[($param['variadic'] ? '...' : '').$param['name']] = $param['type'];
        }

        return [...($this->static ? ['static' => true] : []), 'params' => $params];
    }

    /**
     * @param  array<string, mixed>  $manifest  a key's `x-manifest` object
     */
    public static function fromArray(string $name, array $manifest): self
    {
        $params = [];

        foreach (is_array($manifest['params'] ?? null) ? $manifest['params'] : [] as $key => $type) {
            $key = (string) $key;
            $variadic = str_starts_with($key, '...');

            $params[] = ['name' => $variadic ? substr($key, 3) : $key, 'type' => is_string($type) ? $type : null, 'variadic' => $variadic];
        }

        return new self($name, ($manifest['static'] ?? false) === true, $params);
    }
}
