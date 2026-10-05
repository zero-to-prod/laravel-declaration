<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Commands;

use Composer\Autoload\ClassLoader;
use Illuminate\Console\Command;
use RuntimeException;
use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;

/** @internal */
class GenerateManifestSchemaCommand extends Command
{
    /** @var string */
    protected $signature = 'declaration:generate-manifest-schema
        {classes* : The native Laravel class FQCNs, e.g. Illuminate\\Routing\\Router}
        {--out= : Write to this path (default: the package root manifest.schema.json)}';

    /** @var array<int, string> */
    protected $aliases = ['laravel-declaration:generate-manifest-schema'];

    /** @var string */
    protected $description = 'Generate manifest.schema.json definitions from native Laravel classes (one merged write)';

    public function handle(): int
    {
        /** @var list<class-string> $classes */
        $classes = $this->argument('classes');

        $out = is_string($this->option('out')) ? $this->option('out') : dirname(__DIR__, 3).'/manifest.schema.json'; // the package root artifact (§2.3)

        if (! is_file($out)) {
            $this->components->error('Schema not found at `'.json_encode($out).'`.');

            return self::FAILURE;
        }

        /** @var array<string, mixed> $schema */
        $schema = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR); // Rule 3.3: native failure

        $locate = static function (string $fqcn): ?string {
            foreach (ClassLoader::getRegisteredLoaders() as $loader) { // keyed by vendor dir (§1.8)
                $path = $loader->findFile($fqcn);

                if (is_string($path) && is_file($path)) {
                    return $path; // Laravel's multi-prefix PSR-4 resolved here
                }
            }

            return null;
        };

        foreach ($classes as $class) {
            if ($locate($class) === null) {                            // every class pre-checked BEFORE any mutation (§2.5)
                $this->components->error("No source file for $class"); // ValidateCommand missing-manifest precedent

                return self::FAILURE;
            }
        }

        $source = static fn (string $fqcn): ?string => ($path = $locate($fqcn)) === null ? null : (string) file_get_contents($path); // the ONLY I/O lives in this closure

        $addedAll = 0;

        foreach ($classes as $class) {
            $this->components->info("Schema key: $class"); // the FQCN verbatim — nothing derived (§2.3)

            $skipped = SchemaGenerator::skipped($class, $source);

            if ($skipped !== []) {
                $this->components->warn('Skipped: '.implode(', ', $skipped));
            }

            $fragment = SchemaGenerator::render($class, $source);

            $definitions = is_array($schema['definitions'] ?? null) ? $schema['definitions'] : [];
            $curated = is_array($definitions[$class] ?? null) ? $definitions[$class] : [];

            /** @var array<string, mixed> $before */
            $before = is_array($curated['properties'] ?? null) ? $curated['properties'] : [];

            $schema = SchemaGenerator::merge($schema, $class, $fragment);

            $incoming = is_array($fragment['properties'] ?? null) ? $fragment['properties'] : [];
            $added = array_keys(array_diff_key($incoming, $before));
            $addedAll += count($added);

            $this->components->info($added === []
                ? 'Added [0]'
                : 'Added ['.count($added).']: '.implode(', ', $added));
        }

        if (file_put_contents($out, SchemaGenerator::encode($schema)) === false) {
            throw new RuntimeException("Failed to write schema to `$out`.");
        }

        $this->components->info('Regenerated '.count($classes).' definitions, '.$addedAll.' keys added');

        return self::SUCCESS;
    }
}
