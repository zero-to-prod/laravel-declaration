<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

/**
 * The raw manifest as YAML decoded it. `calls` is the body the interpreter applies to the application; every other
 * root key is data the seams and `declaration:migrate` read from here.
 *
 * @internal
 */
final readonly class ManifestStore
{
    /** @param  array<string, mixed>  $manifest */
    public function __construct(private array $manifest = []) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->manifest;
    }

    /** @return list<array<string, mixed>> the `calls` key: a body on Illuminate\Foundation\Application */
    public function body(): array
    {
        /** @var list<array<string, mixed>> $calls  a `calls` that is not a list is the interpreter's TypeError */
        $calls = $this->manifest['calls'] ?? [];

        return $calls;
    }

    public function block(string $block): mixed
    {
        return $this->manifest[$block] ?? null;
    }

    /**
     * The list items of a block, keyed by the value of one of their fields (`requests` by `name`, `models` by `class`).
     *
     * @return array<string, array<string, mixed>>
     */
    public function items(string $block, string $field): array
    {
        $items = [];
        $block = $this->block($block);

        foreach (is_array($block) ? $block : [] as $item) {
            $key = is_array($item) ? ($item[$field] ?? null) : null;

            if (is_array($item) && is_string($key)) {
                /** @var array<string, mixed> $item */
                $items[$key] = $item;
            }
        }

        return $items;
    }

    /** @return array<string, mixed>|null */
    public function item(string $block, string $field, string $value): ?array
    {
        return $this->items($block, $field)[$value] ?? null;
    }
}
