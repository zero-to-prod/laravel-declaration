<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Commands;

use Illuminate\Console\Command;
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

        foreach (explode("\n", rtrim(SchemaGenerator::encode(SchemaGenerator::render($class)))) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
