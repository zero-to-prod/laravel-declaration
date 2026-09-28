<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Commands;

use Illuminate\Console\Command;
use JsonSchema\Constraints\BaseConstraint;
use JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

use function is_file;
use function is_string;

/**
 * Validates the manifest against `manifest.schema.json`.
 *
 * @internal
 */
class ValidateCommand extends Command
{
    /** @var string */
    protected $signature = 'laravel-declaration:validate {--manifest= : Path to the manifest file}';

    /** @var string */
    protected $description = 'Validate the manifest against manifest.schema.json';

    public function handle(): int
    {
        $file = $this->option('manifest') ?? config('laravel-declaration.manifest', 'manifest/app.yml');

        if (! is_string($file) || ! is_file($file)) {
            $this->components->error('Manifest not found at `'.json_encode($file).'`.');

            return self::FAILURE;
        }

        /** @var array<string, mixed> $manifest */
        $manifest = Yaml::parseFile($file) ?? [];

        $data = BaseConstraint::arrayToObjectRecursive($manifest);
        $schema = (object) ['$ref' => 'file://'.realpath(__DIR__.'/../../../manifest.schema.json')];

        $validator = new Validator;
        $validator->validate($data, $schema);

        if ($validator->isValid()) {
            $this->components->info("Manifest `$file` is valid.");

            return self::SUCCESS;
        }

        foreach ($validator->getErrors() as $error) {
            $this->components->error(($error['property'] ?: 'value').': '.$error['message']);
        }

        return self::FAILURE;
    }
}
