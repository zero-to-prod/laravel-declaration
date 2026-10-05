<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

/**
 * The raw manifest as YAML decoded it. The data keys are read from here by the seams and by `declaration:migrate`;
 * `body()` is what the interpreter applies to the application.
 *
 * @internal
 */
final readonly class ManifestStore
{
    /** Root keys the host reads itself; the interpreter never sees them. */
    public const array DATA = ['requests', 'models', 'queries', 'schema', 'extra'];

    /** @param  array<string, mixed>  $manifest */
    public function __construct(private array $manifest = []) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->manifest;
    }

    /** @return array<string, mixed> the manifest minus the data keys: a body on Illuminate\Foundation\Application */
    public function body(): array
    {
        return array_diff_key($this->manifest, array_flip(self::DATA));
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
