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
        /** @var class-string $class */
        $class = $this->argument('class');
        $basename = strrpos($class, '\\');
        $block = lcfirst($basename === false ? $class : substr($class, $basename + 1)); // §4.6

        $this->components->info("Block: $block");

        $skipped = SchemaGenerator::skipped($class);

        if ($skipped !== []) {
            $this->components->warn('Skipped: '.implode(', ', $skipped));
        }

        $fragment = SchemaGenerator::render($class, $block);

        $out = $this->option('out');

        if (! is_string($out)) {
            foreach (explode("\n", rtrim(SchemaGenerator::encode($fragment))) as $line) {
                $this->line($line);
            }

            return self::SUCCESS;
        }

        if (! is_file($out)) {
            $this->components->error('Schema not found at `'.json_encode($out).'`.');

            return self::FAILURE;
        }

        /** @var array<string, mixed> $schema */
        $schema = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR); // Rule 3.3: native failure

        $definitions = is_array($schema['definitions'] ?? null) ? $schema['definitions'] : [];
        $curated = is_array($definitions[$block] ?? null) ? $definitions[$block] : [];

        /** @var array<string, mixed> $before */
        $before = is_array($curated['properties'] ?? null) ? $curated['properties'] : [];

        $schema = SchemaGenerator::merge($schema, $block, $fragment);

        $incoming = is_array($fragment['properties'] ?? null) ? $fragment['properties'] : [];
        $added = array_keys(array_diff_key($incoming, $before));

        if (file_put_contents($out, SchemaGenerator::encode($schema)) === false) {
            throw new RuntimeException("Failed to write schema to `$out`.");
        }

        $this->components->info($added === []
            ? 'Added [0]'
            : 'Added ['.count($added).']: '.implode(', ', $added));

        return self::SUCCESS;
    }
}
