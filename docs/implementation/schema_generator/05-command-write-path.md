---
name: command-write-path
task: >-
  Complete the command: --out=<path> merges the fragment into that schema file
  in place, reports what it added, and is idempotent.
plan: docs/declarative-schema-generator.md §5.2, §6
depends_on: 01-projection-skeleton.md, 04-merge-and-encoding.md
delivers:
  - GenerateSchemaCommand --out branch (read → merge → encode → write → report)
---

# Unit 05 — Command Write Path

## Vertical slice

`php artisan declaration:generate-schema 'Some\Class' --out=manifest.schema.json` reads that file, merges the freshly rendered fragment into it, writes it back in the repo's 2-space style, and reports the keys it added plus the methods it skipped. Running it again changes nothing (byte-identical file, `Added [0]`). Without `--out` the fragment is only printed (unit 01) — the file is never touched.

## Spec carried by this unit

Command `handle()` flow (§5.2), with the print mode already delivered by unit 01:

1. derive the block key: `lcfirst(class basename)` (§4.6);
2. `SchemaGenerator::render($class, $block)` — a native `ReflectionException` propagates for an unknown class (Rule 3.3);
3. **`--out` path**: read the file at the given path, `json_decode($raw, true, 512, JSON_THROW_ON_ERROR)` (a malformed file fails with a native `JsonException`), `SchemaGenerator::merge(...)`, `SchemaGenerator::encode(...)`, `file_put_contents(...)`, report added keys;
4. **no `--out`**: print the fragment JSON (unit 01) — and still print `Block:` + `Skipped:` so the review channel stays complete.

Failure modes, following the house pattern (`ValidateCommand`):

| Condition | Behavior | Justification |
| --- | --- | --- |
| `--out` path does not exist | `$this->components->error(...)`, `self::FAILURE` | `ValidateCommand`'s missing-manifest precedent (command-level presentation) |
| file contents are not valid JSON | native `JsonException` propagates | Rule 3.3 — no bespoke validation layer |
| unknown class | native `ReflectionException` propagates | Rule 3.3 |
| `file_put_contents` returns `false` | native `RuntimeException` propagates | Rule 3.3 — no bespoke messaging for I/O failure |

Reporting (§5.2 step 4): one line listing the added keys — `Added [N]: a, b, c` — and `Added [0]` when nothing changed. The skipped report is unchanged from unit 01.

The schema file's path must stay stable: `ValidateCommand` loads the schema via `file://` realpath (§1) — the generator only edits the file it is pointed at, never moves or renames it.

## Implementation

Complete `src/Internal/Commands/GenerateSchemaCommand.php` (unit 01's print branch extended with the `--out` branch):

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;

/** @internal */
class GenerateSchemaCommand extends Command
{
    /** @var string */
    protected $signature = 'declaration:generate-schema
        {class : The native Laravel class FQCN, e.g. Illuminate\\Routing\\Router}
        {--out= : Write the merged manifest.schema.json (default: print the fragment)}';

    /** @var array<int, string> */
    protected $aliases = ['laravel-declaration:generate-schema'];

    /** @var string */
    protected $description = 'Generate manifest.schema.json definitions from a native Laravel class';

    public function handle(): int
    {
        $class = $this->argument('class');
        $block = lcfirst(substr($class, (int) (strrpos($class, '\\') + 1))); // §4.6

        $this->components->info("Block: $block");

        $skipped = SchemaGenerator::skipped($class);

        if ($skipped !== []) {
            $this->components->warn('Skipped: '.implode(', ', $skipped));
        }

        $fragment = SchemaGenerator::render($class, $block);

        $out = $this->option('out');

        if (! is_string($out)) {
            $this->line(SchemaGenerator::encode($fragment));

            return self::SUCCESS;
        }

        if (! is_file($out)) {
            $this->components->error('Schema not found at `'.json_encode($out).'`.');

            return self::FAILURE;
        }

        /** @var array<string, mixed> $schema */
        $schema = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR); // Rule 3.3: native failure

        $before = $schema['definitions'][$block]['properties'] ?? [];

        $schema = SchemaGenerator::merge($schema, $block, $fragment);

        $added = array_diff(array_keys($fragment['properties']), array_keys($before));

        if (file_put_contents($out, SchemaGenerator::encode($schema)) === false) {
            throw new RuntimeException("Failed to write schema to `$out`.");
        }

        $this->components->info($added === []
            ? 'Added [0]'
            : 'Added ['.count($added).']: '.implode(', ', $added));

        return self::SUCCESS;
    }
}
```

Import `JsonException` if phpstan/pint require the explicit tag on the throwing call; the exception itself propagates natively (no catch — Rule 3.3).

## Tests — `tests/Feature/GenerateSchemaCommandTest.php` (additions)

A helper writes a small schema file to a temp path (testbench skeleton untouched):

```php
<?php

use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator\Basic;

function tempSchema(string $json): string
{
    $path = tempnam(sys_get_temp_dir(), 'schema-').'.json';

    file_put_contents($path, $json);

    return $path;
}

const MINIMAL_SCHEMA = <<<'JSON'
    {
      "$schema": "http://json-schema.org/draft-07/schema#",
      "type": "object",
      "additionalProperties": false,
      "properties": [],
      "definitions": {
        "basic": {
          "description": "curated basic prose",
          "type": ["object", "null"],
          "additionalProperties": false,
          "properties": {
            "label": {
              "description": "curated label prose: it wins over the stub",
              "type": "string"
            }
          }
        }
      }
    }

    JSON;

it('merges the fragment into the schema file in place', function (): void {
    $path = tempSchema(MINIMAL_SCHEMA);

    $this->artisan('declaration:generate-schema', ['class' => Basic::class, '--out' => $path])
        ->expectsOutputToContain('Block: basic')
        ->expectsOutputToContain('Added [3]: retries, handler, register')
        ->assertSuccessful()
        ->run();

    $schema = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect($schema['definitions']['basic']['properties']['label']['description'])
        ->toBe('curated label prose: it wins over the stub') // curation preserved
        ->and($schema['definitions']['basic']['properties'])->toHaveKey('handler')
        ->and(file_get_contents($path))->toContain('  "type": ['); // 2-space canonical style
});

it('is idempotent: the second write changes nothing', function (): void {
    $path = tempSchema(MINIMAL_SCHEMA);

    $this->artisan('declaration:generate-schema', ['class' => Basic::class, '--out' => $path])
        ->assertSuccessful()
        ->run();

    $afterFirst = file_get_contents($path);

    $this->artisan('declaration:generate-schema', ['class' => Basic::class, '--out' => $path])
        ->expectsOutputToContain('Added [0]')
        ->assertSuccessful()
        ->run();

    expect(file_get_contents($path))->toBe($afterFirst);
});

it('fails when the schema file is missing', function (): void {
    $this->artisan('declaration:generate-schema', [
        'class' => Basic::class,
        '--out' => sys_get_temp_dir().'/does-not-exist.json',
    ])
        ->expectsOutputToContain('Schema not found')
        ->assertFailed()
        ->run();
});

it('fails natively when the schema file is not valid json (Rule 3.3)', function (): void {
    $path = tempSchema('not json');

    expect(fn () => $this->artisan('declaration:generate-schema', ['class' => Basic::class, '--out' => $path])->run())
        ->toThrow(JsonException::class);
});

it('leaves the file untouched in print mode', function (): void {
    $path = tempSchema(MINIMAL_SCHEMA);

    $this->artisan('declaration:generate-schema', ['class' => Basic::class])
        ->expectsOutputToContain('-> label($label) when the key is present')
        ->assertSuccessful()
        ->run();

    expect(file_get_contents($path))->toBe(MINIMAL_SCHEMA);
});
```

## Acceptance checklist

- [ ] `--out` reads, merges, re-encodes (2-space) and writes the same path; reports added keys (§5.2, §6).
- [ ] Second run is byte-identical and reports `Added [0]` (idempotent regeneration, §3).
- [ ] Print mode never touches the file.
- [ ] Native failures propagate (`JsonException`, `ReflectionException`, `RuntimeException`); missing file is a command-level `FAILURE` (Rule 3.3, `ValidateCommand` precedent).
- [ ] Curated prose survives the write (verified above; full artifact round trip in unit 06).
- [ ] `composer check` passes (every command branch covered: print, merge, missing file, invalid JSON, write failure).

## Sources

- Plan: [docs/declarative-schema-generator.md](../../declarative-schema-generator.md) §5.2 (command skeleton + handle steps), §6 (usage), §7 (command tests).
- [src/Internal/Commands/ValidateCommand.php](../../../src/Internal/Commands/ValidateCommand.php) — `is_file` → `error` → `FAILURE` precedent; `file://` realpath load (path stability).
- [src/Internal/Commands/MigrateCommand.php](../../../src/Internal/Commands/MigrateCommand.php) — signature/alias/description conventions, `$this->components->...` reporting style.
- [tests/Feature/InstallCommandTest.php](../../../tests/Feature/InstallCommandTest.php) — `$this->artisan(...)` test convention.