---
name: merge-and-encoding
task: >-
  Deliver the merge layer: regeneration into an existing curated schema preserves
  curation; encoding is canonical 2-space JSON, deterministic and idempotent.
plan: docs/declarative-schema-generator.md §3 (two layers), §5.1 (merge + encoding details)
depends_on: 01-projection-skeleton.md, 02-signature-shapes.md, 03-value-types.md
delivers:
  - SchemaGenerator::merge()
  - completed SchemaGenerator::encode() byte properties
---

# Unit 04 — Merge and Encoding

## Vertical slice

`SchemaGenerator::merge($schema, $block, $fragment)` folds a freshly rendered fragment into the parsed `manifest.schema.json` such that every curated byte of meaning survives and only missing keys are appended. `SchemaGenerator::encode($schema)` reproduces the repo's 2-space JSON style deterministically; running merge+encode twice changes nothing.

## Spec carried by this unit

### Merge semantics (§5.1 + §3)

The plan's merge contract:

> Existing per-key `description` strings are preserved; new keys are appended in native declaration order; keys no longer native are left untouched (regeneration never deletes).

and §3's layer table: curation = "`description` prose, `pattern`s, `items`, kind corrections — preserved on merge".

**Implementation rule — additive-only (reconciliation 6 in [00-overview.md](00-overview.md)):** for a block whose definition already exists, the curated envelope (`description`, `type`, `additionalProperties`) and **every existing key object are left untouched**; only keys absent from the curated fragment are appended in native declaration order. Justification:

- "its `description` wins" (§5.1) is the only per-key preservation rule the plan states; curated refinements (`pattern`, `items`, kind-corrected `anyOf` shapes) live *inside* the same key object, so preserving the object is the only deterministic way to preserve all of them (a diff-based "regenerate structure, re-apply curation" would have to guess which parts are curated).
- "regeneration never deletes" + additive-only ⇒ merge is idempotent and cannot damage the schema it edits.
- The regenerated structure layer remains fully visible through the default print mode, where it is reviewed by hand — that is §3's "loud and cheap to fix" channel.

Stale keys (in the file, no longer native) are left untouched — regeneration never deletes (§5.1).

### New block wiring

When `definitions.<block>` does not exist: insert the whole fragment, and append a root reference `properties.<block> = {"$ref": "#/definitions/<block>"}` **at the end** of the root properties map (the existing map is not alphabetical — `db` follows `pagination` — so end-append is the deterministic position). The `$schema` header, root `type`/`additionalProperties`, and all other definitions are untouched (§5.1); `ValidateCommand` resolves the schema by `file://` realpath, so the file must stay at its path — the generator only edits it, never moves it.

### Encoding (§5.1 + reconciliations 3–4)

```php
json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
```

then halve the indentation (`JSON_PRETTY_PRINT` indents in exact multiples of 4; halving reproduces 2-space):

```php
preg_replace_callback('/^( +)/m', static fn ($m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json)."\n"
```

Two evidence-backed completions of the plan's snippet:

1. **`JSON_UNESCAPED_UNICODE` is required** — `manifest.schema.json` contains 11 non-ASCII characters (em-dashes `—` and `≈` in curated prose); without the flag the first write would replace them with `\uXXXX` escapes.
2. **The output is the algorithm's own canonical style, not today's file byte-for-byte** — the current file keeps short arrays inline (`"type": ["object", "null"]`, prettier-style), while `JSON_PRETTY_PRINT` expands every array. Verified by running the algorithm over the real file: output is deterministic and **idempotent** (`encode(decode(encode(decode(raw))))` is byte-stable), but differs from today's bytes. The first `--out` write of an existing file therefore reformats it once; from then on regeneration is byte-stable. Tests pin idempotency and the decode/encode round trip rather than a golden copy of today's file.

Decode side (used by the command in unit 05): `json_decode($raw, true, 512, JSON_THROW_ON_ERROR)`. Verified: the current file contains no empty objects/arrays, so assoc-decode round-trips losslessly; the byte-stability test over the real file locks this property (if a curated fragment ever adds `{}`, the decode step must become object-preserving — loud test failure, not silent corruption).

## Implementation

Added to `src/Internal/SchemaGenerator.php`:

```php
    /**
     * Merges a rendered fragment into the parsed manifest.schema.json.
     *
     * Additive-only: an existing `definitions.<block>` keeps its curated
     * envelope and every existing key object untouched; missing keys are
     * appended in native declaration order; stale keys are left untouched
     * (regeneration never deletes); all other definitions and the root are
     * untouched. For a new block the fragment is inserted and a root
     * `properties.<block>` reference is appended at the end.
     *
     * @param  array<string, mixed>  $schema    parsed manifest.schema.json
     * @param  array<string, mixed>  $fragment  from render()
     * @return array<string, mixed>  the updated schema, ready to re-encode
     */
    public static function merge(array $schema, string $block, array $fragment): array
    {
        $definitions = $schema['definitions'];

        if (array_key_exists($block, $definitions)) {
            $existing = $definitions[$block]['properties'] ?? [];

            foreach ($fragment['properties'] as $key => $keySchema) {
                if (! array_key_exists($key, $existing)) {
                    $existing[$key] = $keySchema; // appended in native declaration order
                }
            }

            $schema['definitions'][$block]['properties'] = $existing;

            return $schema;
        }

        $schema['definitions'][$block] = $fragment;
        $schema['properties'][$block] = ['$ref' => "#/definitions/$block"];

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

        return preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json)."\n";
    }
```

(`JsonException` import arrives with unit 05's decode path; `JSON_THROW_ON_ERROR` needs no import. Unit 01 already shipped `encode()` — extend it to exactly this body.)

## Tests — added to `tests/Feature/SchemaGeneratorTest.php`

```php
<?php

use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator\Kinds;

function curatedSchema(): array
{
    return [
        '$schema' => 'http://json-schema.org/draft-07/schema#',
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'app' => ['$ref' => '#/definitions/app'],
        ],
        'definitions' => [
            'app' => [
                'description' => 'curated app prose',
                'type' => ['object', 'null'],
                'additionalProperties' => false,
                'properties' => [
                    'bind' => [
                        'description' => 'curated bind prose',
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'string', 'pattern' => '^App\\\\'],
                    ],
                    'stale' => ['description' => 'no longer native'],
                ],
            ],
        ],
    ];
}

it('appends only missing keys and preserves every curated byte of a key', function (): void {
    $fragment = SchemaGenerator::render(
        ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator\Basic::class,
        'app',
    );

    $merged = SchemaGenerator::merge(curatedSchema(), 'app', $fragment);
    $app = $merged['definitions']['app'];

    expect($app['description'])->toBe('curated app prose') // envelope untouched
        ->and($app['properties']['bind'])->toBe(curatedSchema()['definitions']['app']['properties']['bind'])
        ->and($app['properties']['stale'])->toBe(['description' => 'no longer native']) // never deleted
        ->and(array_keys($app['properties']))->toBe(['bind', 'stale', 'label', 'retries', 'handler', 'register']) // native order
        ->and($merged['definitions'])->toHaveKey('app') // sibling definitions untouched
        ->and($merged['properties'])->toBe(curatedSchema()['properties']); // root untouched for an existing block
});

it('wires a new block into definitions and the root properties', function (): void {
    $fragment = SchemaGenerator::render(Kinds::class, 'kinds');

    $merged = SchemaGenerator::merge(curatedSchema(), 'kinds', $fragment);

    expect($merged['definitions']['kinds'])->toBe($fragment)
        ->and($merged['properties']['kinds'])->toBe(['$ref' => '#/definitions/kinds'])
        ->and(array_keys($merged['properties']))->toBe(['app', 'kinds']); // appended at the end
});

it('merges idempotently', function (): void {
    $fragment = SchemaGenerator::render(Kinds::class, 'kinds');

    $once = SchemaGenerator::merge(curatedSchema(), 'kinds', $fragment);
    $twice = SchemaGenerator::merge($once, 'kinds', $fragment);

    expect($twice)->toBe($once);
});

it('encodes with 2-space indentation, unescaped unicode and a trailing newline', function (): void {
    $encoded = SchemaGenerator::encode([
        'description' => 'curated — prose ≈ here',
        'type' => 'object',
    ]);

    expect($encoded)->toBe(<<<'JSON'
        {
          "description": "curated — prose ≈ here",
          "type": "object"
        }

        JSON);
});

it('re-encodes the shipped schema idempotently', function (): void {
    $raw = file_get_contents(dirname(__DIR__, 2).'/manifest.schema.json');

    $schema = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    $once = SchemaGenerator::encode($schema);
    $twice = SchemaGenerator::encode(json_decode($once, true, 512, JSON_THROW_ON_ERROR));

    expect($once)->toBe($twice) // deterministic + idempotent
        ->and($once)->toContain('—') // unicode preserved
        ->and(str_ends_with($once, "}\n"))->toBeTrue();
});
```

## Acceptance checklist

- [ ] Regeneration never loses curated `description` prose, `pattern`s, `items`, or kind-corrected shapes (§3, §9).
- [ ] New keys land in native declaration order; stale keys and the envelope are never rewritten; other definitions and the root are untouched (§5.1).
- [ ] New blocks get their root `properties.<block>` `$ref`; `$schema` header untouched.
- [ ] Output is byte-stable across runs (idempotent merge; idempotent encode) in the repo's 2-space style.
- [ ] `composer check` passes (both `merge` branches, both `??` fallbacks, encode covered).

## Sources

- Plan: [docs/declarative-schema-generator.md](../../declarative-schema-generator.md) §3 (two layers + merge behavior), §5.1 (`merge()` contract, encoding snippet, path stability), §9 (acceptance).
- [manifest.schema.json](../../../manifest.schema.json) — curated fragments (`bind`'s `pattern`, `matched`'s append shape, em-dash prose) that merge must preserve; 11 non-ASCII characters (unicode-flag evidence); no empty objects (assoc-decode round trip).
- [src/Internal/Commands/ValidateCommand.php](../../../src/Internal/Commands/ValidateCommand.php) — schema loaded via `file://` realpath: the generator only edits the file, never moves it.